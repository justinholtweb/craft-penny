<?php

namespace justinholtweb\penny\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\enums\TargetKind;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use justinholtweb\penny\web\assets\cp\CpAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The admin side: making invites, watching them, and turning them off.
 */
class InvitesController extends Controller
{
    /** The one-shot flash the freshly minted key travels in, because it is never stored. */
    private const KEY_FLASH = 'penny.key';

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('penny/invites/_index', [
            'title' => Craft::t('penny', 'Invites'),
            'elementType' => Invite::class,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionEdit(?int $inviteId = null, ?Invite $invite = null): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);
        $this->view->registerAssetBundle(CpAsset::class);

        $plugin = Plugin::getInstance();

        if ($invite === null) {
            $invite = $inviteId !== null
                ? $plugin->invites->getInviteById($inviteId)
                : $plugin->invites->create();

            if ($invite === null) {
                throw new NotFoundHttpException('Invite not found');
            }
        }

        $isNew = !$invite->id;

        return $this->renderTemplate('penny/invites/_edit', [
            'invite' => $invite,
            'isNew' => $isNew,
            'title' => $isNew ? Craft::t('penny', 'New invite') : $invite->getUiLabel(),
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'surfaces' => Surface::cases(),
            'elementTypeOptions' => $this->elementTypeOptions(),
            'sectionOptions' => $this->sectionOptions(),
            'events' => $invite->id ? $plugin->audit->eventsForInvite($invite) : [],
            'canReview' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REVIEW),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();
        $inviteId = $this->request->getBodyParam('inviteId');

        if ($inviteId) {
            $invite = $plugin->invites->getInviteById((int)$inviteId);

            if ($invite === null) {
                throw new NotFoundHttpException('Invite not found');
            }
        } else {
            $invite = $plugin->invites->create();
        }

        $invite->title = $this->request->getBodyParam('title');
        $invite->recipientName = $this->request->getBodyParam('recipientName');
        $invite->recipientEmail = $this->request->getBodyParam('recipientEmail') ?: null;
        $invite->message = $this->request->getBodyParam('message');
        $invite->surface = $this->request->getBodyParam('surface', $invite->surface);
        $invite->requireReview = (bool)$this->request->getBodyParam('requireReview');
        $invite->targetSiteId = (int)$this->request->getBodyParam('targetSiteId', $invite->targetSiteId);
        $invite->setNotifyEmails($this->request->getBodyParam('notifyEmails', ''));

        $expiry = $this->request->getBodyParam('expiryDate');
        $invite->expiryDate = $expiry ? DateTimeHelper::toDateTime($expiry) ?: null : null;

        if ($plugin->isPro()) {
            $invite->setBranding($this->request->getBodyParam('branding', []));
        }

        $invite->setTargets($this->postedTargets($invite));

        if (!$plugin->invites->save($invite)) {
            $this->setFailFlash(Craft::t('penny', 'Couldn’t save invite.'));

            Craft::$app->getUrlManager()->setRouteParams(['invite' => $invite]);

            return null;
        }

        // The plain key exists for this request and no other, so if one was minted it has to be
        // handed to the next screen now or never.
        if ($invite->getPlainKey() !== null) {
            Craft::$app->getSession()->setFlash(self::KEY_FLASH, $invite->getPlainKey());

            if ($plugin->getSettings()->sendOnCreate && $invite->recipientEmail) {
                $plugin->notifications->sendInvite($invite, $invite->getPlainKey());
            }

            return $this->redirect(UrlHelper::cpUrl("penny/invites/$invite->id/link"));
        }

        $this->setSuccessFlash(Craft::t('penny', 'Invite saved.'));

        return $this->redirectToPostedUrl($invite);
    }

    /**
     * The one screen that shows a link.
     *
     * It reads the key out of a one-shot flash, so a refresh shows the "it's gone" state rather
     * than the link — which is exactly right, because by then it really is gone from everywhere
     * except wherever the admin pasted it.
     */
    public function actionLink(int $inviteId): Response
    {
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $invite = Plugin::getInstance()->invites->getInviteById($inviteId);

        if ($invite === null) {
            throw new NotFoundHttpException('Invite not found');
        }

        $key = Craft::$app->getSession()->getFlash(self::KEY_FLASH);

        return $this->renderTemplate('penny/invites/_link', [
            'invite' => $invite,
            'title' => Craft::t('penny', 'Invite link'),
            'key' => $key,
            'url' => $key ? Plugin::getInstance()->keys->urlForKey($key) : null,
        ]);
    }

    public function actionReissue(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $invite = $this->inviteFromRequest();
        $key = Plugin::getInstance()->invites->reissue($invite);

        if ($key === null) {
            $this->setFailFlash(Craft::t('penny', 'Couldn’t re-issue this link.'));

            return $this->redirect($invite->getCpEditUrl());
        }

        Craft::$app->getSession()->setFlash(self::KEY_FLASH, $key);

        return $this->redirect(UrlHelper::cpUrl("penny/invites/$invite->id/link"));
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('Sending invites by email is a Pro feature.');
        }

        $invite = $this->inviteFromRequest();

        // Sending means re-issuing: only the hash is stored, so there is no existing link to send.
        // Which is also the safer behaviour — the link that went to the wrong address stops working.
        $key = $plugin->invites->reissue($invite);

        if ($key === null || !$plugin->notifications->sendInvite($invite, $key)) {
            $this->setFailFlash(Craft::t('penny', 'Couldn’t send the invite.'));

            return $this->redirect($invite->getCpEditUrl());
        }

        $this->setSuccessFlash(Craft::t('penny', 'Invite sent to {email}.', ['email' => $invite->recipientEmail]));

        return $this->redirect($invite->getCpEditUrl());
    }

    public function actionRevoke(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $invite = $this->inviteFromRequest();
        Plugin::getInstance()->invites->revoke($invite);

        $this->setSuccessFlash(Craft::t('penny', 'Invite revoked. The link no longer works.'));

        return $this->redirect($invite->getCpEditUrl());
    }

    /**
     * Approves content that was held for review.
     */
    public function actionApprove(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_REVIEW);

        $plugin = Plugin::getInstance();
        $invite = $this->inviteFromRequest();
        $applied = 0;

        foreach ($invite->getTargets() as $target) {
            if ($target->draftId === null) {
                continue;
            }

            $draft = $plugin->editor->workingElement($invite, $target);

            if ($draft === null || $draft->draftId === null) {
                continue;
            }

            $result = Craft::$app->getDrafts()->applyDraft($draft);
            $plugin->targets->markSaved($target, resultElementId: $result->id);
            $applied++;
        }

        $plugin->invites->markApplied($invite);

        $this->setSuccessFlash(Craft::t('penny', 'Approved. {n, plural, =1{One item is} other{# items are}} now live.', ['n' => $applied]));

        return $this->redirect($invite->getCpEditUrl());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $invite = $this->inviteFromRequest();
        Craft::$app->getElements()->deleteElement($invite);

        $this->setSuccessFlash(Craft::t('penny', 'Invite deleted.'));

        return $this->redirect(UrlHelper::cpUrl('penny/invites'));
    }

    /**
     * Everything below a target row's two top selects, re-rendered.
     *
     * Asked for over Ajax whenever the row's kind or element type changes, because the picker and
     * the field list both depend on them: an element select has to know its element type when it
     * is built, and there is no field layout to offer until something has been picked. Guessing
     * either would be wrong for most of the element types Penny supports.
     */
    public function actionTargetBody(): Response
    {
        return $this->renderTargetPart('penny/invites/_target-body');
    }

    /**
     * Just the "which fields" half of a target row.
     *
     * Asked for when the chosen element changes. Rebuilding the whole row there would tear out the
     * element select while Craft is still animating the newly chosen chip into it, and leave the
     * chip stranded in the middle of the page.
     */
    public function actionTargetScope(): Response
    {
        return $this->renderTargetPart('penny/invites/_target-scope');
    }

    private function renderTargetPart(string $template): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        // "Everything" is a null list, not an empty one. Reading the posted checkboxes without
        // consulting the mode turns a row that shows every field into one that shows none, the
        // moment anything on it is re-rendered.
        $selected = $this->request->getBodyParam('scopeMode') === 'some'
            ? $this->request->getBodyParam('selected')
            : null;

        $target = new Target([
            'kind' => $this->request->getBodyParam('kind', TargetKind::Element->value),
            'elementType' => $this->request->getBodyParam('elementType', Entry::class),
            'elementId' => (int)$this->request->getBodyParam('elementId') ?: null,
            'sectionId' => (int)$this->request->getBodyParam('sectionId') ?: null,
            'entryTypeId' => (int)$this->request->getBodyParam('entryTypeId') ?: null,
            'layoutElementUids' => is_array($selected) ? $selected : null,
        ]);

        $target->siteId = (int)$this->request->getBodyParam('siteId') ?: null;

        $view = $this->getView();

        $html = $view->renderTemplate($template, [
            'target' => $target,
            'index' => (string)$this->request->getBodyParam('index', '0'),
            'tabs' => Plugin::getInstance()->scope->selectableLayoutElements($target),
            'sectionOptions' => $this->sectionOptions(),
            'entryTypeOptions' => $this->entryTypeOptionsFor($target->sectionId),
        ]);

        // Element selects, date pickers and the rest wire themselves up from the head and body HTML
        // the view collected while rendering. Returning the markup alone gives an inert row.
        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /** Entry types available in a section, for the "create something new" row. */
    public function actionEntryTypes(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $sectionId = (int)$this->request->getRequiredBodyParam('sectionId');
        $section = Craft::$app->getEntries()->getSectionById($sectionId);

        return $this->asJson(['options' => $this->entryTypeOptionsFor($sectionId)]);
    }

    /** @return array<int, array{value: int, label: string}> */
    private function entryTypeOptionsFor(?int $sectionId): array
    {
        if (!$sectionId) {
            return [];
        }

        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        $options = [];

        foreach ($section?->getEntryTypes() ?? [] as $entryType) {
            $options[] = ['value' => $entryType->id, 'label' => $entryType->name];
        }

        return $options;
    }

    // ------------------------------------------------------------------ helpers

    private function inviteFromRequest(): Invite
    {
        $inviteId = (int)$this->request->getRequiredBodyParam('inviteId');
        $invite = Plugin::getInstance()->invites->getInviteById($inviteId);

        if ($invite === null) {
            throw new NotFoundHttpException('Invite not found');
        }

        return $invite;
    }

    /**
     * @return Target[]
     */
    private function postedTargets(Invite $invite): array
    {
        $posted = $this->request->getBodyParam('targets', []);
        $targets = [];

        if (!is_array($posted)) {
            return $targets;
        }

        foreach ($posted as $row) {
            if (!is_array($row)) {
                continue;
            }

            $kind = $row['kind'] ?? TargetKind::Element->value;

            // Craft's element select posts an array of ids even when it only accepts one.
            $elementIds = $row['elementId'] ?? null;
            $elementId = is_array($elementIds) ? (int)($elementIds[0] ?? 0) : (int)$elementIds;

            $target = new Target([
                'id' => isset($row['id']) && $row['id'] !== '' ? (int)$row['id'] : null,
                'kind' => $kind,
                'elementType' => $row['elementType'] ?? Entry::class,
                'elementId' => $kind === TargetKind::New->value ? null : ($elementId ?: null),
                'sectionId' => (int)($row['sectionId'] ?? 0) ?: null,
                'entryTypeId' => (int)($row['entryTypeId'] ?? 0) ?: null,
                'parentId' => (int)($row['parentId'] ?? 0) ?: null,
                'label' => $row['label'] ?? null,
                'instructions' => $row['instructions'] ?? null,
            ]);

            // "Everything on the layout" is stored as null rather than as a list, so a field added
            // to the layout tomorrow is included without anybody having to remember to tick it.
            $scopeMode = $row['scopeMode'] ?? 'all';
            $target->layoutElementUids = $scopeMode === 'all'
                ? null
                : array_values(array_filter((array)($row['layoutElementUids'] ?? [])));

            $target->siteId = $invite->targetSiteId;
            $targets[] = $target;
        }

        return $targets;
    }

    /** @return array<string, string> */
    private function elementTypeOptions(): array
    {
        $plugin = Plugin::getInstance();
        $options = [];

        foreach ($plugin->targets->supportedElementTypes() as $class => $label) {
            $isLite = in_array($class, $plugin->targets->liteElementTypes(), true);

            $options[$class] = $plugin->isPro() || $isLite
                ? $label
                : Craft::t('penny', '{label} (Pro)', ['label' => $label]);
        }

        return $options;
    }

    /** @return array<int, array{value: int, label: string}> */
    private function sectionOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = ['value' => $section->id, 'label' => $section->name];
        }

        return $options;
    }
}
