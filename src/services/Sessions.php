<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\penny\db\Table;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use Throwable;

/**
 * The Pro control-panel surface: a real, temporary, tightly-permissioned Craft user.
 *
 * This is the surface that buys the recipient Craft's whole editor rather than Penny's rendering
 * of it. The price is a real user account — which a Solo licence counts, and which therefore has
 * to be cleaned up reliably rather than eventually.
 *
 * Permissions are written straight to the user, not to a group. Groups live in project config, and
 * a plugin that writes project config in the middle of somebody clicking a link would fail on
 * every environment with `allowAdminChanges` off — which is every production environment worth
 * having.
 */
class Sessions extends Component
{
    private ?Invite $_currentInvite = null;
    private bool $_currentInviteLoaded = false;
    private bool $_handingIn = false;

    /**
     * Logs the recipient in and returns where to send them.
     *
     * Everything about the account is derived from the invite, so re-opening a link inside its
     * window resumes the same session rather than piling up accounts.
     */
    public function openFor(Invite $invite): ?User
    {
        if ($invite->getSurface() !== Surface::Cp || !Plugin::getInstance()->isPro()) {
            return null;
        }

        $user = $this->findOrCreateUser($invite);

        if ($user === null) {
            return null;
        }

        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $this->permissionsFor($invite));

        $duration = max(60, Plugin::getInstance()->getSettings()->cpSessionDuration);

        if (!Craft::$app->getUser()->login($user, $duration)) {
            Craft::error("Could not log in the session user for invite $invite->id.", Plugin::LOG_CATEGORY);

            return null;
        }

        return $user;
    }

    /**
     * The invite behind the current control panel session, if there is one.
     *
     * Looked up from the signed-in user rather than from a session variable: the user *is* the
     * marker, so this stays true across requests, redirects and a browser restart, and there is no
     * second place for the two to disagree.
     */
    public function currentInvite(): ?Invite
    {
        if ($this->_currentInviteLoaded) {
            return $this->_currentInvite;
        }

        $this->_currentInviteLoaded = true;
        $userId = Craft::$app->getUser()->getId();

        if (!$userId) {
            return null;
        }

        $invite = Invite::find()->sessionUserId($userId)->status(null)->one();

        return $this->_currentInvite = ($invite instanceof Invite ? $invite : null);
    }

    /**
     * Whether the current control panel session may save this element.
     *
     * Permissions are a section-level tool and Penny's scope is per element, so this is the guard
     * that closes the gap: an invite that grants "save entries in Blog" must still not let its
     * recipient edit somebody else's blog post.
     */
    public function currentSessionAllows(ElementInterface $element): bool
    {
        $invite = $this->currentInvite();

        if ($invite === null) {
            return true;
        }

        if (!$invite->getIsRedeemable()) {
            return false;
        }

        if (Plugin::getInstance()->scope->allows($invite, $element)) {
            return true;
        }

        // Nested elements — Matrix entries, content blocks — belong to whatever owns them, and the
        // owner is the thing scope has an opinion about.
        // Only nested elements have an owner at all; asking anything else throws.
        $owner = $element instanceof NestedElementInterface ? $element->getOwner() : null;

        return $owner !== null && Plugin::getInstance()->scope->allows($invite, $owner);
    }

    /**
     * Whether this save would publish work the recipient has not handed in yet.
     *
     * The session holds `saveEntries` and `savePeerEntryDrafts` because it has to be able to work
     * on a draft of somebody else's entry — and those are exactly the permissions Craft's *Apply
     * draft* button asks for. Left alone, a recipient could publish their own draft straight past
     * hold for review. So while the session is live, the live copy of anything that keeps drafts
     * is not theirs to write; handing in is the one way it changes, and `handIn()` says so.
     */
    public function refusesCanonicalSave(ElementInterface $element): bool
    {
        $invite = $this->currentInvite();

        if ($invite === null || $this->_handingIn || $element->getIsDraft() || $element->getIsRevision()) {
            return false;
        }

        $target = Plugin::getInstance()->scope->targetFor($invite, $element);

        return $target !== null && $target->getSupportsDrafts();
    }

    /**
     * The recipient says they are finished.
     *
     * Exactly what submitting does on the hosted page — apply the drafts unless the invite is held
     * for review, spend the link, tell whoever is waiting — and then, because `markSubmitted()`
     * ends the session, the account is suspended and deleted before the response goes out.
     */
    public function handIn(Invite $invite): void
    {
        $plugin = Plugin::getInstance();
        $this->_handingIn = true;

        try {
            $plugin->editor->submit($invite);
        } finally {
            $this->_handingIn = false;
        }

        $plugin->notifications->notifySubmission($invite);
    }

    /**
     * What the hand-in bar at the foot of every control panel page needs to draw itself.
     *
     * One link per target, because permissions and the stripped-down nav put the recipient on the
     * first target's screen and would otherwise leave them no way to find the second.
     *
     * @return array{targets: array<int, array{label: string, url: string}>, review: bool}
     */
    public function handInBarConfig(Invite $invite): array
    {
        $editor = Plugin::getInstance()->editor;
        $targets = [];

        foreach ($invite->getTargets() as $target) {
            $element = $editor->workingElement($invite, $target);
            $url = $element?->getCpEditUrl();

            if ($url === null) {
                continue;
            }

            $targets[] = [
                'label' => $target->getDisplayLabel(),
                'url' => $url,
            ];
        }

        return [
            'targets' => $targets,
            'review' => (bool)$invite->requireReview,
        ];
    }

    /**
     * Puts back anything the recipient changed that was not theirs to change.
     *
     * The control panel surface hands over Craft's real editor, and Craft's real editor renders the
     * whole field layout — it has no notion of "these three fields". So element scope is enforced by
     * refusing the save outright, and *field* scope is enforced here, by restoring every
     * out-of-scope value from the canonical element before the save goes through.
     *
     * Restoring rather than refusing, because the recipient did nothing wrong: the control panel
     * showed them a field, and an error message about a field they were invited to fill in would be
     * baffling. They simply find it unchanged afterwards.
     */
    public function enforceFieldScope(ElementInterface $element, ?Invite $invite = null): void
    {
        $invite ??= $this->currentInvite();

        if ($invite === null) {
            return;
        }

        $target = Plugin::getInstance()->scope->targetFor($invite, $element);

        if ($target === null || $target->getIsWholeLayout()) {
            return;
        }

        $canonical = $element->getIsDraft() ? $element->getCanonical() : null;
        $inScope = Plugin::getInstance()->scope->fieldHandles($target);
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        foreach ($layout->getCustomFields() as $field) {
            if (in_array($field->handle, $inScope, true)) {
                continue;
            }

            // A brand-new element has no canonical to restore from, and its out-of-scope fields
            // started empty — so leaving them is the same as putting them back.
            if ($canonical !== null) {
                $element->setFieldValue($field->handle, $canonical->getFieldValue($field->handle));
            }
        }
    }

    /**
     * CSS that hides the fields this session may not write.
     *
     * Cosmetic. `enforceFieldScope()` above is the boundary; this is so the recipient is not invited
     * to type into something that will be quietly put back.
     */
    public function hiddenFieldCss(Invite $invite): ?string
    {
        $selectors = [];

        foreach ($invite->getTargets() as $target) {
            if ($target->getIsWholeLayout()) {
                // One target that shows everything means nothing can be hidden without hiding it on
                // the others' screens too — the control panel has one stylesheet, not one per tab.
                return null;
            }

            foreach ($target->layoutElementUids ?? [] as $uid) {
                $selectors[] = sprintf('[data-layout-element="%s"]', $uid);
            }
        }

        if (!$selectors) {
            return null;
        }

        return sprintf(
            'body.penny-invited-session [data-layout-element]:not(%s) { display: none !important; }',
            implode('):not(', $selectors),
        );
    }

    /**
     * Ends the session and disposes of the account.
     *
     * Suspended first and deleted second, so that a failure part-way through leaves an account that
     * cannot log in rather than one that can.
     */
    public function revokeFor(Invite $invite): void
    {
        if (!$invite->sessionUserId) {
            return;
        }

        $user = Craft::$app->getUsers()->getUserById($invite->sessionUserId);

        if ($user !== null) {
            try {
                Craft::$app->getUsers()->suspendUser($user);
                Craft::$app->getUserPermissions()->saveUserPermissions($user->id, []);

                if (Plugin::getInstance()->getSettings()->deleteSessionUsers) {
                    $this->deleteAfterRequest($user->id, $invite->id);
                }
            } catch (Throwable $e) {
                Craft::error("Could not dispose of the session user for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        $invite->sessionUserId = null;

        Db::update(Table::INVITES, ['sessionUserId' => null], ['id' => $invite->id], updateTimestamp: false);
    }

    /**
     * Deletes the account once the request is over, not now.
     *
     * Applying a draft copies its change-tracking rows onto the live element in an after-request
     * callback, still stamped with the id of whoever made the changes — this account. Delete it
     * first and those inserts fail their foreign key, and the recipient's hand-in ends in a 500
     * after everything that mattered has already happened. Queued after Craft's own callback, so
     * the rows land and the delete then nulls their user column like any other.
     *
     * The account is already suspended and stripped of permissions by then, so the delay buys
     * nobody anything.
     */
    private function deleteAfterRequest(int $userId, int $inviteId): void
    {
        Craft::$app->onAfterRequest(function() use ($userId, $inviteId) {
            try {
                $user = Craft::$app->getUsers()->getUserById($userId);

                if ($user !== null) {
                    Craft::$app->getElements()->deleteElement($user, hardDelete: true);
                }
            } catch (Throwable $e) {
                Craft::error("Could not delete the session user for invite $inviteId: " . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        });
    }

    /**
     * The permissions this invite's targets imply.
     *
     * Built up from the targets rather than from a fixed list, so an invite that only touches one
     * global set cannot reach the entries section next door.
     *
     * @return string[]
     */
    public function permissionsFor(Invite $invite): array
    {
        $permissions = ['accessCp'];

        // On a multi-site install every element authorisation check starts with the site, before it
        // looks at the section at all — so without this the recipient is refused by Craft on a
        // screen Penny sent them to, with permissions that otherwise look complete.
        $site = $invite->getSite();

        if (Craft::$app->getIsMultiSite()) {
            $permissions[] = "editSite:$site->uid";
        }

        foreach ($invite->getTargets() as $target) {
            foreach ($this->permissionsForTarget($target) as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    /** @return string[] */
    private function permissionsForTarget(Target $target): array
    {
        $entries = Craft::$app->getEntries();

        return match (true) {
            is_a($target->elementType, Entry::class, true) => $this->entryPermissions($target, $entries),
            is_a($target->elementType, GlobalSet::class, true) => $this->globalSetPermissions($target),
            is_a($target->elementType, Category::class, true) => $this->categoryPermissions($target),
            is_a($target->elementType, Asset::class, true) => $this->assetPermissions($target),
            is_a($target->elementType, User::class, true) => ['viewUsers', 'editUsers'],
            default => [],
        };
    }

    /** @return string[] */
    private function entryPermissions(Target $target, \craft\services\Entries $entries): array
    {
        $sectionId = $target->sectionId;

        if (!$sectionId) {
            $element = $target->getElement();
            $sectionId = $element instanceof Entry ? $element->sectionId : null;
        }

        $section = $sectionId ? $entries->getSectionById($sectionId) : null;

        if (!$section) {
            return [];
        }

        $permissions = [
            "viewEntries:$section->uid",
            "saveEntries:$section->uid",

            // Somebody else wrote the entry the invite points at, which is the whole premise.
            "viewPeerEntries:$section->uid",
            "savePeerEntries:$section->uid",

            // And the draft they work on was created by whoever made the invite, not by them — so
            // without these Craft treats their own working copy as somebody else's.
            "viewPeerEntryDrafts:$section->uid",
            "savePeerEntryDrafts:$section->uid",
        ];

        if ($target->getIsNew()) {
            $permissions[] = "createEntries:$section->uid";
        }

        return $permissions;
    }

    /** @return string[] */
    private function globalSetPermissions(Target $target): array
    {
        $element = $target->getElement();

        return $element instanceof GlobalSet ? ["editGlobalSet:$element->uid"] : [];
    }

    /** @return string[] */
    private function categoryPermissions(Target $target): array
    {
        $element = $target->getElement();

        if (!$element instanceof Category) {
            return [];
        }

        $group = $element->getGroup();

        return [
            "viewCategories:$group->uid",
            "saveCategories:$group->uid",
            "viewPeerCategoryDrafts:$group->uid",
            "savePeerCategoryDrafts:$group->uid",
        ];
    }

    /** @return string[] */
    private function assetPermissions(Target $target): array
    {
        $element = $target->getElement();

        if (!$element instanceof Asset) {
            return [];
        }

        $volume = $element->getVolume();

        return ["viewAssets:$volume->uid", "saveAssets:$volume->uid"];
    }

    // ------------------------------------------------------------------ the account

    private function findOrCreateUser(Invite $invite): ?User
    {
        if ($invite->sessionUserId) {
            $existing = Craft::$app->getUsers()->getUserById($invite->sessionUserId);

            if ($existing !== null && !$existing->suspended) {
                return $existing;
            }
        }

        $user = new User();
        $user->active = true;
        $user->admin = false;
        $user->email = $this->emailFor($invite);
        $user->username = 'penny-' . strtolower(StringHelper::randomString(12));
        $user->firstName = $invite->recipientName ?: Craft::t('penny', 'Invited');
        $user->lastName = Craft::t('penny', 'Guest');

        // No password is ever set. The only way into this account is the invite link, which means
        // the account cannot outlive the link even if the cleanup below were to fail.
        if (!Craft::$app->getElements()->saveElement($user)) {
            Craft::error("Could not create a session user for invite $invite->id: " . json_encode($user->getErrors()), Plugin::LOG_CATEGORY);

            return null;
        }

        $invite->sessionUserId = $user->id;

        Db::update(Table::INVITES, ['sessionUserId' => $user->id], ['id' => $invite->id], updateTimestamp: false);

        return $user;
    }

    /**
     * A unique address for the account.
     *
     * Never the recipient's own: Craft requires emails to be unique, and an invite sent to somebody
     * who already has an account would otherwise either collide or quietly hand out a second login
     * to an account that is not theirs.
     */
    private function emailFor(Invite $invite): string
    {
        $host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', PHP_URL_HOST) ?: 'example.com';

        return sprintf('penny-invite-%d-%s@invalid.%s', $invite->id, strtolower(StringHelper::randomString(8)), $host);
    }
}
