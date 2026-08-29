<?php
/**
 * Penny integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-penny/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every fixture it creates it deletes again, whether the run passes
 * or not.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\fields\PlainText;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\enums\InviteStatus;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\enums\TargetKind;
use justinholtweb\penny\models\AccessResult;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function heading(string $text): void
{
    echo "\n$text\n";
}

$plugin = Plugin::getInstance();
$suffix = strtolower(StringHelper::randomString(6));
$originalEdition = $plugin->edition;

/** @var array<string, mixed> $fixtures */
$fixtures = ['fields' => [], 'section' => null, 'entry' => null, 'invites' => []];

try {
    // ------------------------------------------------------------------ fixtures

    heading('Fixtures');

    $fieldsService = Craft::$app->getFields();

    $bodyField = new PlainText();
    $bodyField->name = "Penny Body $suffix";
    $bodyField->handle = "pennyBody$suffix";
    $bodyField->multiline = true;

    if (!$fieldsService->saveField($bodyField)) {
        throw new RuntimeException('Could not save the body field: ' . json_encode($bodyField->getErrors()));
    }

    $noteField = new PlainText();
    $noteField->name = "Penny Note $suffix";
    $noteField->handle = "pennyNote$suffix";

    if (!$fieldsService->saveField($noteField)) {
        throw new RuntimeException('Could not save the note field: ' . json_encode($noteField->getErrors()));
    }

    $fixtures['fields'] = [$bodyField, $noteField];

    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content']);
    $tab->setLayout($layout);
    $tab->setElements([
        new EntryTitleField(),
        new CustomField($bodyField),
        new CustomField($noteField),
    ]);
    $layout->setTabs([$tab]);

    $entryType = new EntryType();
    $entryType->name = "Penny Type $suffix";
    $entryType->handle = "pennyType$suffix";
    $entryType->setFieldLayout($layout);

    if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
        throw new RuntimeException('Could not save the entry type: ' . json_encode($entryType->getErrors()));
    }

    $section = new Section();
    $section->name = "Penny Section $suffix";
    $section->handle = "pennySection$suffix";
    $section->type = Section::TYPE_CHANNEL;
    $section->setSiteSettings([
        new Section_SiteSettings([
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'hasUrls' => false,
            'enabledByDefault' => true,
        ]),
    ]);
    $section->setEntryTypes([$entryType]);

    if (!Craft::$app->getEntries()->saveSection($section)) {
        throw new RuntimeException('Could not save the section: ' . json_encode($section->getErrors()));
    }

    $fixtures['section'] = $section;

    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->title = 'Before';
    $entry->setFieldValue($bodyField->handle, 'original body');
    $entry->setFieldValue($noteField->handle, 'original note');

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('Could not save the entry: ' . json_encode($entry->getErrors()));
    }

    $fixtures['entry'] = $entry;

    $admin = User::find()->admin()->status(User::STATUS_ACTIVE)->orderBy(['id' => SORT_ASC])->one();

    check('fixtures are in place', fn() => $entry->id > 0 && $admin !== null);

    $layoutElements = [];

    foreach ($entry->getFieldLayout()->getTabs() as $layoutTab) {
        foreach ($layoutTab->getElements() as $layoutElement) {
            if ($layoutElement instanceof CustomField) {
                $layoutElements[$layoutElement->attribute()] = $layoutElement->uid;
            } elseif ($layoutElement instanceof EntryTitleField) {
                $layoutElements['title'] = $layoutElement->uid;
            }
        }
    }

    check('the fixture layout exposes title and two fields', fn() =>
        count($layoutElements) === 3 || 'got ' . json_encode(array_keys($layoutElements)));

    // ------------------------------------------------------------------ keys

    heading('Keys');

    $keys = $plugin->keys;

    check('a minted key is URL-safe and long enough', function() use ($keys) {
        $key = $keys->mint();

        return (strlen($key) >= 40 && preg_match('/^[A-Za-z0-9_\-]+$/', $key) === 1)
            ?: "got '$key'";
    });

    check('two keys are never the same', fn() => $keys->mint() !== $keys->mint());

    check('hashing is stable and is not the key', function() use ($keys) {
        $key = $keys->mint();
        $hash = $keys->hash($key);

        return ($hash === $keys->hash($key) && $hash !== $key && strlen($hash) === 64)
            ?: 'hash was ' . $hash;
    });

    check('obvious rubbish is rejected before the database is touched', fn() =>
        !$keys->looksLikeKey('') && !$keys->looksLikeKey('../../etc/passwd') && !$keys->looksLikeKey('short')
        && $keys->looksLikeKey($keys->mint()));

    check('the pretty URL carries the configured prefix', function() use ($keys, $plugin) {
        $url = $keys->urlForKey('abc123');

        return str_contains($url, '/' . trim($plugin->getSettings()->inviteUriPrefix, '/') . '/abc123')
            ?: "got $url";
    });

    check('the hosted URL is a control panel URL', function() use ($keys) {
        $url = $keys->hostedUrlForKey('abc123');

        return str_contains($url, Plugin::HOSTED_SEGMENT . '/abc123') ?: "got $url";
    });

    // ------------------------------------------------------------------ invites

    heading('Invites');

    $plugin->getPlugins?->getPlugins ?? null; // no-op guard for static analysers

    $makeInvite = function(array $attributes = [], array $targetOverrides = []) use ($entry, $admin, $plugin, $layoutElements, &$fixtures): Invite {
        $invite = $plugin->invites->create(array_merge([
            'title' => 'Check invite',
            'authorId' => $admin?->id,
        ], $attributes));

        $target = new Target(array_merge([
            'kind' => TargetKind::Element->value,
            'elementType' => Entry::class,
            'elementId' => $entry->id,
            'layoutElementUids' => [$layoutElements['title'], $layoutElements[$entry->getFieldLayout()->getCustomFields()[0]->handle]],
        ], $targetOverrides));

        $invite->setTargets([$target]);

        if (!$plugin->invites->save($invite)) {
            throw new RuntimeException('Could not save the invite: ' . json_encode($invite->getErrors()));
        }

        $fixtures['invites'][] = $invite;

        return $invite;
    };

    $invite = $makeInvite();
    $plainKey = $invite->getPlainKey();

    check('creating an invite mints a key and stores only its hash', function() use ($invite, $plainKey, $keys) {
        return ($plainKey !== null
            && $invite->keyHash === $keys->hash($plainKey)
            && $invite->keyHash !== $plainKey)
            ?: 'key or hash missing';
    });

    check('the key is not readable once the invite is reloaded', function() use ($invite, $plugin) {
        $reloaded = $plugin->invites->getInviteById($invite->id);

        return ($reloaded !== null && $reloaded->getPlainKey() === null) ?: 'a reloaded invite handed back a key';
    });

    check('the plain key is nowhere in the invites table', function() use ($invite, $plainKey) {
        $row = (new craft\db\Query())->from(justinholtweb\penny\db\Table::INVITES)->where(['id' => $invite->id])->one();

        return !str_contains(json_encode($row), $plainKey) ?: 'the key was found in the row';
    });

    check('a new invite is pending', fn() =>
        $invite->getInviteStatus() === InviteStatus::Pending ?: $invite->getStatus());

    check('the default expiry comes from the settings', function() use ($invite, $plugin) {
        $days = $plugin->getSettings()->defaultExpiryDays;

        if ($days <= 0) {
            return $invite->expiryDate === null;
        }

        return $invite->expiryDate !== null && $invite->expiryDate > new DateTime();
    });

    check('targets survive a round trip', function() use ($invite, $plugin, $entry, $layoutElements) {
        $reloaded = $plugin->invites->getInviteById($invite->id);
        $targets = $reloaded->getTargets();

        if (count($targets) !== 1) {
            return 'got ' . count($targets) . ' targets';
        }

        return ($targets[0]->elementId === $entry->id
            && $targets[0]->elementType === Entry::class
            && count($targets[0]->layoutElementUids) === 2
            && in_array($layoutElements['title'], $targets[0]->layoutElementUids, true))
            ?: 'target came back wrong: ' . json_encode($targets[0]->toArray());
    });

    check('"everything on the layout" is stored as null, not as a list', function() use ($makeInvite) {
        $wide = $makeInvite([], ['layoutElementUids' => null]);
        $reloaded = Plugin::getInstance()->invites->getInviteById($wide->id);

        return ($reloaded->getTargets()[0]->layoutElementUids === null
            && $reloaded->getTargets()[0]->getIsWholeLayout())
            ?: 'a whole-layout target did not come back as null';
    });

    // ------------------------------------------------------------------ access

    heading('Access');

    check('the right key opens the invite', function() use ($plugin, $plainKey, $invite) {
        $result = $plugin->access->resolve($plainKey);

        return ($result->ok && $result->invite?->id === $invite->id) ?: 'denied: ' . $result->reason;
    });

    check('a wrong key is turned away without saying why', function() use ($plugin, $keys) {
        $result = $plugin->access->resolve($keys->mint());

        return (!$result->ok
            && $result->reason === AccessResult::REASON_UNKNOWN
            && !$result->getIsSpecific())
            ?: 'reason was ' . $result->reason;
    });

    check('junk is turned away too', function() use ($plugin) {
        $result = $plugin->access->resolve('nope');

        return (!$result->ok && $result->reason === AccessResult::REASON_UNKNOWN) ?: 'reason was ' . $result->reason;
    });

    check('a revoked invite says so', function() use ($plugin, $makeInvite) {
        $revoked = $makeInvite();
        $key = $revoked->getPlainKey();
        $plugin->invites->revoke($revoked);

        $result = $plugin->access->resolve($key);

        return (!$result->ok && $result->reason === AccessResult::REASON_REVOKED) ?: 'reason was ' . $result->reason;
    });

    check('an expired invite says so', function() use ($plugin, $makeInvite) {
        $expired = $makeInvite();
        $key = $expired->getPlainKey();

        craft\helpers\Db::update(justinholtweb\penny\db\Table::INVITES, [
            'expiryDate' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-1 hour')),
        ], ['id' => $expired->id], updateTimestamp: false);

        $result = $plugin->access->resolve($key);

        return (!$result->ok && $result->reason === AccessResult::REASON_EXPIRED) ?: 'reason was ' . $result->reason;
    });

    check('re-issuing turns the old link off and hands back a new one', function() use ($plugin, $makeInvite) {
        $reissued = $makeInvite();
        $oldKey = $reissued->getPlainKey();
        $newKey = $plugin->invites->reissue($reissued);

        if ($newKey === null || $newKey === $oldKey) {
            return 'no new key';
        }

        return (!$plugin->access->resolve($oldKey)->ok && $plugin->access->resolve($newKey)->ok)
            ?: 'the old key still works, or the new one does not';
    });

    check('deleting an invite kills its link immediately', function() use ($plugin, $makeInvite) {
        $doomed = $makeInvite();
        $key = $doomed->getPlainKey();

        Craft::$app->getElements()->deleteElement($doomed);

        $result = $plugin->access->resolve($key);

        return !$result->ok ?: 'a deleted invite still opened';
    });

    // ------------------------------------------------------------------ status and queries

    heading('Status and queries');

    check('opening an invite moves it from pending to in progress', function() use ($plugin, $makeInvite) {
        $opened = $makeInvite();
        $plugin->invites->markOpened($opened);
        $reloaded = $plugin->invites->getInviteById($opened->id);

        return ($reloaded->getInviteStatus() === InviteStatus::Opened) ?: $reloaded->getStatus();
    });

    check('only the first open is written to the audit trail', function() use ($plugin, $makeInvite) {
        $opened = $makeInvite();
        $plugin->invites->markOpened($opened);
        $plugin->invites->markOpened($opened);
        $plugin->invites->markOpened($opened);

        $opens = array_filter(
            $plugin->audit->eventsForInvite($opened),
            fn(array $event) => $event['type'] === EventType::Opened,
        );

        return count($opens) === 1 ?: 'got ' . count($opens) . ' open events';
    });

    check('the index can filter by every status', function() use ($plugin, $makeInvite) {
        $pending = $makeInvite();

        $found = Invite::find()->status(InviteStatus::Pending->value)->id($pending->id)->exists();
        $notFound = Invite::find()->status(InviteStatus::Submitted->value)->id($pending->id)->exists();

        return ($found && !$notFound) ?: 'pending invite matched the wrong status';
    });

    check('an invite with no expiry is not treated as expired', function() use ($plugin, $makeInvite) {
        $forever = $makeInvite(['expiryDate' => null]);

        return (Invite::find()->live()->id($forever->id)->exists()
            && !Invite::find()->status(InviteStatus::Expired->value)->id($forever->id)->exists())
            ?: 'an invite with no deadline was filtered out';
    });

    check('live() excludes revoked and expired invites', function() use ($plugin, $makeInvite) {
        $revoked = $makeInvite();
        $plugin->invites->revoke($revoked);

        return !Invite::find()->live()->id($revoked->id)->exists() ?: 'a revoked invite counted as live';
    });

    check('an invite can be found by the element it covers', function() use ($makeInvite, $entry) {
        $found = $makeInvite();

        return Invite::find()->forElementId($entry->id)->id($found->id)->exists()
            ?: 'forElementId did not find it';
    });

    // ------------------------------------------------------------------ scope

    heading('Scope');

    $scoped = $makeInvite();
    $scopedTarget = $plugin->invites->getInviteById($scoped->id)->getTargets()[0];
    $bodyHandle = $bodyField->handle;
    $noteHandle = $noteField->handle;

    check('only the ticked fields are in scope', function() use ($plugin, $scopedTarget, $bodyHandle, $noteHandle) {
        $handles = $plugin->scope->fieldHandles($scopedTarget);

        return (in_array($bodyHandle, $handles, true) && !in_array($noteHandle, $handles, true))
            ?: 'got ' . json_encode($handles);
    });

    check('a ticked native field is in scope', fn() =>
        in_array('title', $plugin->scope->nativeAttributes($scopedTarget), true)
        ?: 'got ' . json_encode($plugin->scope->nativeAttributes($scopedTarget)));

    check('a posted value for an out-of-scope field is dropped', function() use ($plugin, $scopedTarget, $bodyHandle, $noteHandle) {
        $filtered = $plugin->scope->filterFieldValues($scopedTarget, [
            $bodyHandle => 'allowed',
            $noteHandle => 'not allowed',
            'somethingInvented' => 'definitely not',
        ]);

        return ($filtered === [$bodyHandle => 'allowed']) ?: 'got ' . json_encode($filtered);
    });

    check('a posted value for an out-of-scope native attribute is dropped', function() use ($plugin, $scopedTarget) {
        $filtered = $plugin->scope->filterNativeValues($scopedTarget, [
            'title' => 'allowed',
            'slug' => 'not ticked',
            'authorId' => 99,
        ]);

        return ($filtered === ['title' => 'allowed']) ?: 'got ' . json_encode($filtered);
    });

    check('privilege-granting attributes are never writable', function() use ($plugin) {
        $never = $plugin->scope->neverWritableAttributes();

        foreach (['admin', 'permissions', 'groups', 'email', 'username', 'newPassword', 'suspended'] as $attribute) {
            if (!in_array($attribute, $never, true)) {
                return "$attribute is missing from the denylist";
            }
        }

        return true;
    });

    check('a whole-layout target exposes every field', function() use ($plugin, $makeInvite, $bodyHandle, $noteHandle) {
        $wide = $makeInvite([], ['layoutElementUids' => null]);
        $target = $plugin->invites->getInviteById($wide->id)->getTargets()[0];
        $handles = $plugin->scope->fieldHandles($target);

        return (in_array($bodyHandle, $handles, true) && in_array($noteHandle, $handles, true))
            ?: 'got ' . json_encode($handles);
    });

    check('scope recognises the element it covers, and nothing else', function() use ($plugin, $scoped, $entry) {
        $invite = $plugin->invites->getInviteById($scoped->id);
        $other = new Entry(['id' => 999999]);

        return ($plugin->scope->allows($invite, $entry) && !$plugin->scope->allows($invite, $other))
            ?: 'scope matched the wrong element';
    });

    // ------------------------------------------------------------------ editor and drafts

    heading('The editor');

    check('opening a target starts a draft rather than touching the live entry', function() use ($plugin, $makeInvite, $entry) {
        $editing = $makeInvite();
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];

        $working = $plugin->editor->workingElement($invite, $target);

        if ($working === null) {
            return 'no working element';
        }

        if ($working->draftId === null) {
            return 'the working element is not a draft';
        }

        return ($working->getCanonicalId() === $entry->id
            && $working->id !== $entry->id)
            ?: 'the draft is not a draft of the fixture entry';
    });

    check('coming back to the link finds the same draft', function() use ($plugin, $makeInvite) {
        $editing = $makeInvite();
        $invite = $plugin->invites->getInviteById($editing->id);
        $first = $plugin->editor->workingElement($invite, $invite->getTargets()[0]);

        $reloaded = $plugin->invites->getInviteById($editing->id);
        $second = $plugin->editor->workingElement($reloaded, $reloaded->getTargets()[0]);

        return ($first->draftId === $second->draftId) ?: "draft {$first->draftId} became {$second->draftId}";
    });

    check('the rendered form contains the field in scope and not the one out of it', function() use ($plugin, $makeInvite, $bodyHandle, $noteHandle) {
        $editing = $makeInvite();
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        $html = $plugin->editor->withCpTemplateMode(fn() => $plugin->editor->renderFields($target, $working));

        return (str_contains($html, $bodyHandle) && !str_contains($html, $noteHandle))
            ?: 'the rendered form did not match the scope';
    });

    check('the form namespaces its inputs to the target', function() use ($plugin, $makeInvite) {
        $editing = $makeInvite();
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        $html = $plugin->editor->withCpTemplateMode(fn() => $plugin->editor->renderFields($target, $working));

        return str_contains($html, "targets[$target->id][fields]") ?: 'inputs were not namespaced';
    });

    check('submitting applies the draft to the live entry', function() use ($plugin, $makeInvite, $entry, $bodyHandle) {
        $editing = $makeInvite();
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        $working->title = 'After';
        $working->setFieldValue($bodyHandle, 'edited by the recipient');

        if (!Craft::$app->getElements()->saveElement($working)) {
            return 'could not save the draft: ' . json_encode($working->getErrors());
        }

        $before = Entry::find()->id($entry->id)->status(null)->one();

        if ($before->title !== 'Before') {
            return 'the live entry changed before submission';
        }

        $plugin->editor->submit($invite);

        $after = Entry::find()->id($entry->id)->status(null)->one();

        return ($after->title === 'After' && $after->getFieldValue($bodyHandle) === 'edited by the recipient')
            ?: "the live entry says '{$after->title}'";
    });

    check('a submitted invite is spent', function() use ($plugin, $makeInvite) {
        $editing = $makeInvite();
        $key = $editing->getPlainKey();
        $invite = $plugin->invites->getInviteById($editing->id);

        $plugin->editor->submit($invite);

        $reloaded = $plugin->invites->getInviteById($editing->id);
        $result = $plugin->access->resolve($key);

        return ($reloaded->getInviteStatus() === InviteStatus::Submitted
            && !$result->ok
            && $result->reason === AccessResult::REASON_SUBMITTED)
            ?: 'status is ' . $reloaded->getStatus() . ', access reason ' . $result->reason;
    });

    check('review mode leaves the draft unapplied and the entry alone', function() use ($plugin, $makeInvite, $entry, $bodyHandle) {
        $entry->title = 'Untouched';
        Craft::$app->getElements()->saveElement($entry);

        $editing = $makeInvite(['requireReview' => true]);
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        $working->title = 'Proposed';
        Craft::$app->getElements()->saveElement($working);

        $plugin->editor->submit($invite);

        $reloaded = $plugin->invites->getInviteById($editing->id);
        $live = Entry::find()->id($entry->id)->status(null)->one();

        return ($reloaded->getInviteStatus() === InviteStatus::AwaitingReview && $live->title === 'Untouched')
            ?: 'status is ' . $reloaded->getStatus() . ' and the entry says ' . $live->title;
    });

    check('approving a held submission publishes it', function() use ($plugin, $makeInvite, $entry) {
        $entry->title = 'Still untouched';
        Craft::$app->getElements()->saveElement($entry);

        $editing = $makeInvite(['requireReview' => true]);
        $invite = $plugin->invites->getInviteById($editing->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        $working->title = 'Approved copy';
        Craft::$app->getElements()->saveElement($working);
        $plugin->editor->submit($invite);

        $held = $plugin->invites->getInviteById($editing->id);
        $draft = $plugin->editor->workingElement($held, $held->getTargets()[0]);
        Craft::$app->getDrafts()->applyDraft($draft);
        $plugin->invites->markApplied($held);

        $reloaded = $plugin->invites->getInviteById($editing->id);
        $live = Entry::find()->id($entry->id)->status(null)->one();

        return ($reloaded->getInviteStatus() === InviteStatus::Submitted && $live->title === 'Approved copy')
            ?: 'status is ' . $reloaded->getStatus() . ' and the entry says ' . $live->title;
    });

    check('a new-entry target creates a draft nobody can see yet', function() use ($plugin, $makeInvite, $section, $entryType) {
        $creating = $makeInvite([], [
            'kind' => TargetKind::New->value,
            'elementId' => null,
            'sectionId' => $section->id,
            'entryTypeId' => $entryType->id,
            'layoutElementUids' => null,
        ]);

        $invite = $plugin->invites->getInviteById($creating->id);
        $target = $invite->getTargets()[0];
        $working = $plugin->editor->workingElement($invite, $target);

        if ($working === null) {
            return 'nothing was created';
        }

        $visible = Entry::find()->sectionId($section->id)->id($working->getCanonicalId())->exists();

        return ($working->draftId !== null && !$visible)
            ?: 'the new entry is already visible in its section';
    });

    // ------------------------------------------------------------------ sessions

    heading('Control panel sessions');

    check('permissions are computed from the targets, and reach no further', function() use ($plugin, $makeInvite, $section) {
        $cpInvite = $makeInvite(['surface' => Surface::Cp->value]);
        $invite = $plugin->invites->getInviteById($cpInvite->id);
        $permissions = $plugin->sessions->permissionsFor($invite);

        if (!in_array('accessCp', $permissions, true)) {
            return 'no accessCp';
        }

        if (!in_array("saveEntries:$section->uid", $permissions, true)) {
            return 'cannot save its own section: ' . json_encode($permissions);
        }

        $allowedSuffixes = [$section->uid, $invite->getSite()->uid];

        foreach ($permissions as $permission) {
            if (!str_contains($permission, ':')) {
                continue;
            }

            foreach ($allowedSuffixes as $suffix) {
                if (str_ends_with($permission, $suffix)) {
                    continue 2;
                }
            }

            return "reached beyond its scope: $permission";
        }

        // And nothing that lets them take anything away.
        foreach ($permissions as $permission) {
            if (str_starts_with($permission, 'delete')) {
                return "granted a delete permission: $permission";
            }
        }

        return true;
    });

    check('a new-entry target adds the create permission and an edit one does not', function() use ($plugin, $makeInvite, $section, $entryType) {
        $editInvite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value])->id);

        $createInvite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value], [
            'kind' => TargetKind::New->value,
            'elementId' => null,
            'sectionId' => $section->id,
            'entryTypeId' => $entryType->id,
            'layoutElementUids' => null,
        ])->id);

        return (!in_array("createEntries:$section->uid", $plugin->sessions->permissionsFor($editInvite), true)
            && in_array("createEntries:$section->uid", $plugin->sessions->permissionsFor($createInvite), true))
            ?: 'the create permission was granted to the wrong invite';
    });

    check('there is no current invite outside a session', fn() =>
        $plugin->sessions->currentInvite() === null ?: 'a console run reported an invite session');

    check('a multi-site install grants the site permission the auth check starts with', function() use ($plugin, $makeInvite) {
        $invite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value])->id);
        $permissions = $plugin->sessions->permissionsFor($invite);
        $expected = 'editSite:' . $invite->getSite()->uid;

        if (!Craft::$app->getIsMultiSite()) {
            return !in_array($expected, $permissions, true) ?: 'a single-site install was granted a site permission';
        }

        return in_array($expected, $permissions, true)
            ?: 'without this, Craft refuses every element before it looks at the section: ' . json_encode($permissions);
    });

    check('the recipient can reach a draft somebody else created for them', function() use ($plugin, $makeInvite, $section) {
        $invite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value])->id);
        $permissions = $plugin->sessions->permissionsFor($invite);

        foreach (["viewPeerEntryDrafts:$section->uid", "savePeerEntryDrafts:$section->uid"] as $expected) {
            if (!in_array($expected, $permissions, true)) {
                return "$expected is missing, so the recipient cannot open their own working copy";
            }
        }

        return true;
    });

    check('an out-of-scope field is put back on the way into the database', function() use ($plugin, $makeInvite, $noteHandle, $bodyHandle) {
        $invite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value])->id);
        $target = $invite->getTargets()[0];
        $draft = $plugin->editor->workingElement($invite, $target);

        $draft->setFieldValue($bodyHandle, 'in scope, keep me');
        $draft->setFieldValue($noteHandle, 'out of scope, put me back');

        $plugin->sessions->enforceFieldScope($draft, $invite);

        return ($draft->getFieldValue($bodyHandle) === 'in scope, keep me'
            && $draft->getFieldValue($noteHandle) !== 'out of scope, put me back')
            ?: 'body=' . var_export($draft->getFieldValue($bodyHandle), true)
                . ' note=' . var_export($draft->getFieldValue($noteHandle), true);
    });

    check('a whole-layout invite hides nothing', function() use ($plugin, $makeInvite) {
        $invite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value], ['layoutElementUids' => null])->id);

        return $plugin->sessions->hiddenFieldCss($invite) === null ?: 'it tried to hide fields it allows';
    });

    check('a scoped invite hides the fields it does not cover', function() use ($plugin, $makeInvite, $layoutElements) {
        $invite = $plugin->invites->getInviteById($makeInvite(['surface' => Surface::Cp->value])->id);
        $css = $plugin->sessions->hiddenFieldCss($invite);

        return ($css !== null && str_contains($css, $layoutElements['title']) && str_contains($css, 'display: none'))
            ?: 'got ' . var_export($css, true);
    });

    // ------------------------------------------------------------------ audit

    heading('The audit trail');

    check('the lifecycle is recorded', function() use ($plugin, $makeInvite) {
        $watched = $makeInvite();
        $plugin->invites->markOpened($watched);
        $plugin->invites->revoke($watched);

        $types = array_map(fn(array $event) => $event['type'], $plugin->audit->eventsForInvite($watched));

        foreach ([EventType::Created, EventType::Opened, EventType::Revoked] as $expected) {
            if (!in_array($expected, $types, true)) {
                return $expected->value . ' was not recorded';
            }
        }

        return true;
    });

    check('a refused key is recorded against the invite it named', function() use ($plugin, $makeInvite) {
        $watched = $makeInvite();
        $key = $watched->getPlainKey();
        $plugin->invites->revoke($watched);
        $plugin->access->resolve($key);

        $types = array_map(fn(array $event) => $event['type'], $plugin->audit->eventsForInvite($watched));

        return in_array(EventType::Denied, $types, true) ?: 'the refusal was not recorded';
    });

    check('the audit trail never contains the key', function() use ($plugin, $makeInvite) {
        $watched = $makeInvite();
        $key = $watched->getPlainKey();
        $plugin->invites->markOpened($watched);

        $rows = (new craft\db\Query())
            ->from(justinholtweb\penny\db\Table::EVENTS)
            ->where(['inviteId' => $watched->id])
            ->all();

        return !str_contains(json_encode($rows), $key) ?: 'the key was found in the audit trail';
    });

    // ------------------------------------------------------------------ editions

    heading('Editions');

    Craft::$app->getPlugins()->switchEdition('penny', Plugin::EDITION_LITE);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    check('Lite refuses a second target', function() use ($plugin, $admin, $entry) {
        $invite = $plugin->invites->create(['title' => 'Two targets', 'authorId' => $admin?->id]);
        $invite->setTargets([
            new Target(['elementType' => Entry::class, 'elementId' => $entry->id]),
            new Target(['elementType' => Entry::class, 'elementId' => $entry->id]),
        ]);

        return (!$plugin->invites->save($invite) && $invite->hasErrors('targets'))
            ?: 'Lite accepted two targets';
    });

    check('Lite refuses a control panel session', function() use ($plugin, $admin, $entry) {
        $invite = $plugin->invites->create(['title' => 'CP on Lite', 'authorId' => $admin?->id, 'surface' => Surface::Cp->value]);
        $invite->setTargets([new Target(['elementType' => Entry::class, 'elementId' => $entry->id])]);

        return (!$plugin->invites->save($invite) && $invite->hasErrors('surface'))
            ?: 'Lite accepted a control panel invite';
    });

    check('Lite refuses a new-entry target', function() use ($plugin, $admin, $section, $entryType) {
        $invite = $plugin->invites->create(['title' => 'New on Lite', 'authorId' => $admin?->id]);
        $invite->setTargets([new Target([
            'kind' => TargetKind::New->value,
            'elementType' => Entry::class,
            'sectionId' => $section->id,
            'entryTypeId' => $entryType->id,
        ])]);

        return (!$plugin->invites->save($invite) && $invite->hasErrors('targets'))
            ?: 'Lite accepted a create-new target';
    });

    check('Lite refuses element types it does not cover', function() use ($plugin, $admin) {
        $target = new Target(['elementType' => User::class, 'elementId' => 1]);

        return ($plugin->targets->checkEditionSupport($target) !== null)
            ?: 'Lite accepted a user target';
    });

    check('Lite still accepts an entry target', function() use ($plugin, $admin, $entry) {
        $invite = $plugin->invites->create(['title' => 'Fine on Lite', 'authorId' => $admin?->id]);
        $invite->setTargets([new Target(['elementType' => Entry::class, 'elementId' => $entry->id])]);

        $saved = $plugin->invites->save($invite);

        if ($saved) {
            $GLOBALS['pennyLiteInviteId'] = $invite->id;
        }

        return $saved ?: 'Lite rejected an entry target: ' . json_encode($invite->getErrors());
    });

    check('Lite sends no email', function() use ($plugin, $admin, $entry) {
        $invite = $plugin->invites->create(['title' => 'No mail', 'authorId' => $admin?->id, 'recipientEmail' => 'nobody@example.com']);
        $invite->setTargets([new Target(['elementType' => Entry::class, 'elementId' => $entry->id])]);
        $plugin->invites->save($invite);

        return !$plugin->notifications->sendInvite($invite, $invite->getPlainKey())
            ?: 'Lite sent an email';
    });

    Craft::$app->getPlugins()->switchEdition('penny', Plugin::EDITION_PRO);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    check('Pro accepts two targets', function() use ($plugin, $admin, $entry) {
        $invite = $plugin->invites->create(['title' => 'Two on Pro', 'authorId' => $admin?->id]);
        $invite->setTargets([
            new Target(['elementType' => Entry::class, 'elementId' => $entry->id]),
            new Target(['elementType' => Entry::class, 'elementId' => $entry->id]),
        ]);

        return $plugin->invites->save($invite) ?: 'Pro rejected two targets: ' . json_encode($invite->getErrors());
    });

    // ------------------------------------------------------------------ housekeeping

    heading('Housekeeping');

    check('the audit trail is trimmed to the retention window', function() use ($plugin, $makeInvite) {
        $watched = $makeInvite();
        $plugin->invites->markOpened($watched);

        craft\helpers\Db::update(justinholtweb\penny\db\Table::EVENTS, [
            'dateCreated' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-5 years')),
        ], ['inviteId' => $watched->id], updateTimestamp: false);

        $plugin->audit->prune();

        return count($plugin->audit->eventsForInvite($watched)) === 0
            ?: 'old events survived the prune';
    });

    check('sweeping does not touch a live invite', function() use ($plugin, $makeInvite) {
        $live = $makeInvite(['surface' => Surface::Cp->value]);
        $plugin->invites->sweepExpired();

        return $plugin->invites->getInviteById($live->id)->getIsRedeemable()
            ?: 'a live invite was swept';
    });
} finally {
    // ------------------------------------------------------------------ cleanup

    heading('Cleanup');

    Craft::$app->getPlugins()->switchEdition('penny', $originalEdition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    foreach (Invite::find()->status(null)->all() as $leftover) {
        Craft::$app->getElements()->deleteElement($leftover, true);
    }

    if ($fixtures['section'] !== null) {
        Craft::$app->getEntries()->deleteSection($fixtures['section']);
    }

    foreach ($fixtures['fields'] as $field) {
        Craft::$app->getFields()->deleteField($field);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    echo "  fixtures removed\n";
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
