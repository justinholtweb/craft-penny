<?php

namespace justinholtweb\penny\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\models\AccessResult;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use yii\web\Response;

/**
 * The hosted editing surface.
 *
 * A control panel controller with anonymous access — the same shape as Craft's own login and
 * set-password screens — because control panel field inputs need control panel asset bundles and a
 * control panel request to render at all. What it serves has none of the control panel's chrome:
 * Penny's own layout, the fields the invite names, and a submit button.
 */
class HostedController extends Controller
{
    /** Where `SessionController` leaves the id of an invite it has just handed in. */
    public const HANDED_IN_SESSION_KEY = 'penny.handedIn';

    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function actionIndex(string $key): Response
    {
        $result = Plugin::getInstance()->access->resolve($key);

        if (!$result->ok) {
            return $this->renderClosed($result);
        }

        $invite = $result->invite;
        Plugin::getInstance()->invites->markOpened($invite);

        if ($invite->getSurface() === Surface::Cp && Plugin::getInstance()->isPro()) {
            return $this->handOverToCp($invite);
        }

        $this->actAsInviteAuthor($invite);

        return $this->renderForm($invite, $key);
    }

    /**
     * Saves what has been filled in so far, without handing it in.
     *
     * Only offered for targets whose element type keeps drafts. For everything else there is
     * nowhere safe to put a half-finished answer — writing it would put it straight on the site —
     * so those are written once, at submission, and the page says so.
     */
    public function actionSaveProgress(): Response
    {
        $this->requirePostRequest();

        $key = (string)$this->request->getRequiredBodyParam('key');
        $result = Plugin::getInstance()->access->resolve($key);

        if (!$result->ok) {
            return $this->asFailure($result->message);
        }

        $invite = $result->invite;
        $this->actAsInviteAuthor($invite);

        $saved = 0;

        foreach ($invite->getTargets() as $target) {
            if (!Plugin::getInstance()->editor->isProgressive($target)) {
                continue;
            }

            $element = Plugin::getInstance()->editor->workingElement($invite, $target);

            if ($element !== null && Plugin::getInstance()->editor->save($invite, $target, $element)) {
                $saved++;
            }
        }

        if ($saved > 0) {
            Plugin::getInstance()->audit->record($invite, EventType::Saved);
        }

        return $this->asSuccess(Craft::t('penny', 'Saved. You can come back to this link later.'));
    }

    /**
     * Hands the work in, and spends the link.
     */
    public function actionSubmit(): Response
    {
        $this->requirePostRequest();

        $key = (string)$this->request->getRequiredBodyParam('key');
        $result = Plugin::getInstance()->access->resolve($key);

        if (!$result->ok) {
            return $this->renderClosed($result);
        }

        $invite = $result->invite;
        $this->actAsInviteAuthor($invite);

        $editor = Plugin::getInstance()->editor;
        $errors = [];
        $elements = [];

        foreach ($invite->getTargets() as $target) {
            $element = $editor->workingElement($invite, $target);

            if ($element === null) {
                $errors[$target->id] = [Craft::t('penny', 'This is no longer available. Please ask for a new link.')];
                continue;
            }

            $elements[$target->id] = $element;

            if (!$editor->save($invite, $target, $element)) {
                $errors[$target->id] = $this->scopedErrors($target, $element);
            }
        }

        if ($errors) {
            // Nothing is submitted and the link stays live: a validation failure is the one case
            // where the recipient has to be able to come back to exactly what they typed. So the
            // form is drawn from the elements that failed, still holding the posted values, and
            // not reloaded from the database, which only has the last save that succeeded.
            return $this->renderForm($invite, $key, $errors, $elements);
        }

        $editor->submit($invite);
        Plugin::getInstance()->notifications->notifySubmission($invite);

        return $this->renderTemplate('penny/hosted/done', [
            'invite' => $invite,
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * The thank-you page for a control panel recipient, who arrives here already signed out.
     *
     * The invite comes from the session rather than the URL, so this page cannot be pointed at
     * somebody else's invite, and it is shown once.
     */
    public function actionDone(): Response
    {
        $session = Craft::$app->getSession();
        $inviteId = $session->get(self::HANDED_IN_SESSION_KEY);
        $session->remove(self::HANDED_IN_SESSION_KEY);

        $invite = $inviteId ? Plugin::getInstance()->invites->getInviteById((int)$inviteId) : null;

        if ($invite === null) {
            return $this->renderClosed(AccessResult::deny(
                AccessResult::REASON_SUBMITTED,
                Craft::t('penny', 'This link has already been used.'),
            ));
        }

        return $this->renderTemplate('penny/hosted/done', [
            'invite' => $invite,
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }

    // ------------------------------------------------------------------ rendering

    /**
     * @param array<int, string[]> $errors keyed by target id
     * @param array<int, ElementInterface> $elements keyed by target id — elements to draw instead
     * of loading the stored ones
     */
    private function renderForm(Invite $invite, string $key, array $errors = [], array $elements = []): Response
    {
        $editor = Plugin::getInstance()->editor;
        $panels = [];

        foreach ($invite->getTargets() as $target) {
            $element = $elements[$target->id] ?? $editor->workingElement($invite, $target);

            $panels[] = [
                'target' => $target,
                'element' => $element,
                'fields' => $element !== null ? $editor->renderFields($target, $element) : null,
                'progressive' => $editor->isProgressive($target),
                'errors' => $errors[$target->id] ?? [],
            ];
        }

        return $this->renderTemplate('penny/hosted/index', [
            'invite' => $invite,
            'key' => $key,
            'panels' => $panels,
            'settings' => Plugin::getInstance()->getSettings(),
            'hasErrors' => $errors !== [],
        ], View::TEMPLATE_MODE_CP);
    }

    private function renderClosed(AccessResult $result): Response
    {
        $this->response->setStatusCode(410);

        return $this->renderTemplate('penny/hosted/closed', [
            'result' => $result,
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * @return string[]
     */
    private function scopedErrors(Target $target, ElementInterface $element): array
    {
        $messages = [];
        $scope = Plugin::getInstance()->scope;
        $allowed = array_merge($scope->fieldHandles($target), $scope->nativeAttributes($target));

        foreach ($element->getErrors() as $attribute => $attributeErrors) {
            // Errors on fields the recipient was never shown are not theirs to fix, and telling
            // them about a field they cannot see is worse than saying nothing.
            $root = explode('.', $attribute)[0];

            if (!in_array($root, $allowed, true)) {
                continue;
            }

            foreach ($attributeErrors as $error) {
                $messages[] = $error;
            }
        }

        return $messages ?: [Craft::t('penny', 'Something on this form could not be saved.')];
    }

    // ------------------------------------------------------------------ identity

    /**
     * Gives the request an identity, in memory only.
     *
     * Element select fields, asset uploads and every permission-aware input read the current user,
     * and with no user at all they either render empty or throw. So the request borrows one —
     * through `setIdentity()`, which sets it for this request and nothing else: no session is
     * written, no cookie is issued, and the recipient's browser never holds a Craft login.
     *
     * This is not what bounds the recipient. The invite's scope is, and it is enforced on the way
     * into the database rather than here.
     */
    private function actAsInviteAuthor(Invite $invite): void
    {
        $user = $this->actingUser($invite);

        if ($user !== null) {
            Craft::$app->getUser()->setIdentity($user);
        }
    }

    private function actingUser(Invite $invite): ?User
    {
        $users = Craft::$app->getUsers();
        $configured = Plugin::getInstance()->getSettings()->actingUserId;

        foreach ([$configured, $invite->authorId] as $id) {
            if ($id && ($user = $users->getUserById($id)) !== null && !$user->suspended) {
                return $user;
            }
        }

        // Last resort, so that an invite whose author has since been deleted still opens.
        $admin = User::find()->admin()->status(User::STATUS_ACTIVE)->orderBy(['id' => SORT_ASC])->one();

        return $admin instanceof User ? $admin : null;
    }

    // ------------------------------------------------------------------ handover

    private function handOverToCp(Invite $invite): Response
    {
        $user = Plugin::getInstance()->sessions->openFor($invite);

        if ($user === null) {
            return $this->renderClosed(AccessResult::deny(
                AccessResult::REASON_MISCONFIGURED,
                Craft::t('penny', 'This link could not be opened. Please ask whoever sent it to you.'),
                $invite,
            ));
        }

        $target = $invite->getTargets()[0] ?? null;
        $element = $target !== null ? Plugin::getInstance()->editor->workingElement($invite, $target) : null;

        return $this->redirect($element?->getCpEditUrl() ?? 'dashboard');
    }
}
