<?php

namespace justinholtweb\penny\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Application;
use craft\web\Controller;
use craft\web\View;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\enums\TargetKind;
use justinholtweb\penny\models\AccessResult;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * View links, on the site.
 *
 * Three steps, and the split between the first two is the whole design. `index` is what a GET of
 * the link gets, and it spends nothing — mail scanners and chat unfurlers fetch links, and a link
 * spent by a robot is dead on arrival for the person it was meant for. `open` is the button, which
 * nothing automated presses: it spends the link and binds the page to this browser. `render` is
 * the page itself, behind a Craft token that is useless without the browser's cookie.
 */
class ViewController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'index' => self::ALLOW_ANONYMOUS_LIVE,
        'open' => self::ALLOW_ANONYMOUS_LIVE,
        'render' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function actionIndex(string $key): Response
    {
        $result = Plugin::getInstance()->access->resolve($key);

        if (!$result->ok) {
            return $this->renderClosed($result);
        }

        $invite = $result->invite;

        if ($invite->getSurface() !== Surface::View) {
            return $this->redirect(Plugin::getInstance()->keys->hostedUrlForKey($key));
        }

        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');
        $this->response->setNoCacheHeaders();

        return $this->renderTemplate('penny/hosted/view', [
            'invite' => $invite,
            'key' => $key,
            'target' => $invite->getTargets()[0] ?? null,
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }

    public function actionOpen(): Response
    {
        $this->requirePostRequest();

        $key = (string)$this->request->getRequiredBodyParam('key');
        $plugin = Plugin::getInstance();
        $result = $plugin->access->resolve($key);

        if (!$result->ok) {
            return $this->renderClosed($result);
        }

        $invite = $result->invite;

        if ($invite->getSurface() !== Surface::View) {
            return $this->redirect($plugin->keys->hostedUrlForKey($key));
        }

        $url = $plugin->views->open($invite, $this->response);

        if ($url === null) {
            return $this->renderClosed(AccessResult::deny(
                AccessResult::REASON_MISCONFIGURED,
                Craft::t('penny', 'This link could not be opened. Please ask whoever sent it to you.'),
                $invite,
            ));
        }

        return $this->redirect($url);
    }

    /**
     * The page, rendered by the site's own templates as it would be for a preview.
     *
     * Craft's preview action would do this if it could be reached, but it only answers its own
     * tokens. So this does the same few things it does: put the element in place as the one being
     * previewed, and send the request back round the router with the token check off.
     */
    public function actionRender(int $inviteId): Response
    {
        $this->requireToken();

        $plugin = Plugin::getInstance();
        $invite = $plugin->invites->getInviteById($inviteId);
        $refusal = $plugin->views->refusal($invite, $this->request);

        if ($refusal !== null) {
            return $this->renderClosed(AccessResult::deny(AccessResult::REASON_CLOSED, $refusal, $invite));
        }

        $element = $plugin->views->elementForInvite($invite);

        if ($element === null) {
            throw new NotFoundHttpException('The page this link showed no longer exists.');
        }

        if (!$element->lft && $element->getIsDerivative()) {
            // A draft of something in a structure has no place in it of its own; borrow the
            // canonical's, the way a preview does, so breadcrumbs and navigation still render.
            $canonical = $element->getCanonical(true);
            $element->structureId = $canonical->structureId;
            $element->root = $canonical->root;
            $element->lft = $canonical->lft;
            $element->rgt = $canonical->rgt;
            $element->level = $canonical->level;
        }

        $element->previewing = true;
        Craft::$app->getElements()->setPlaceholderElement($element);

        // Never cached, never indexed, and the token in this URL goes nowhere else — not on to an
        // outbound link, not to whatever serves the page's images.
        $this->response->setNoCacheHeaders();
        $this->response->getHeaders()
            ->set('X-Robots-Tag', 'noindex, nofollow')
            ->set('Referrer-Policy', 'no-referrer');

        $this->request->checkIfActionRequest(true, false);

        /** @var Application $app */
        $app = Craft::$app;
        $urlManager = $app->getUrlManager();
        $urlManager->checkToken = false;
        $urlManager->setRouteParams([], false);
        $urlManager->setMatchedElement(null);

        return $app->handleRequest($this->request, true);
    }

    /**
     * "Share once", from an element's edit screen: a view invite for this element (or this draft
     * of it), made and handed back in one step.
     */
    public function actionShare(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException('View links are a Pro feature.');
        }

        $elementType = (string)$this->request->getRequiredBodyParam('elementType');
        $canonicalId = (int)$this->request->getRequiredBodyParam('canonicalId');
        $siteId = (int)$this->request->getRequiredBodyParam('siteId');
        $draftId = (int)$this->request->getBodyParam('draftId') ?: null;

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            throw new NotFoundHttpException('Unknown element type.');
        }

        $canonical = Craft::$app->getElements()->getElementById($canonicalId, $elementType, $siteId);

        if ($canonical === null) {
            throw new NotFoundHttpException('Element not found.');
        }

        // Sharing a page means being allowed to see it — and a draft means being allowed to see
        // that draft, which for somebody else's is a separate permission.
        $shown = $draftId
            ? $elementType::find()->draftId($draftId)->siteId($siteId)->status(null)->one()
            : $canonical;

        if ($shown === null || !Craft::$app->getElements()->canView($shown)) {
            throw new ForbiddenHttpException('You cannot share this.');
        }

        $invite = $plugin->invites->create([
            'title' => Craft::t('penny', 'Shared: {title}', ['title' => $canonical->getUiLabel()]),
            'surface' => Surface::View->value,
            'targetSiteId' => $siteId,
        ]);

        $invite->setTargets([new Target([
            'kind' => TargetKind::Element->value,
            'elementType' => $elementType,
            'elementId' => $canonical->id,
            'draftId' => $draftId,
        ])]);

        if (!$plugin->invites->save($invite)) {
            return $this->asFailure(implode(' ', $invite->getFirstErrors()) ?: Craft::t('penny', 'Couldn’t make a link.'));
        }

        return $this->asJson([
            'url' => $invite->getInviteUrl(),
            'editUrl' => $invite->getCpEditUrl(),
            'expires' => $invite->expiryDate
                ? Craft::t('penny', 'Opens once, until {date}.', ['date' => Craft::$app->getFormatter()->asDatetime($invite->expiryDate, 'short')])
                : Craft::t('penny', 'Opens once. No deadline.'),
        ]);
    }

    private function renderClosed(AccessResult $result): Response
    {
        $this->response->setStatusCode(410);
        $this->response->getHeaders()->set('X-Robots-Tag', 'noindex, nofollow');

        return $this->renderTemplate('penny/hosted/closed', [
            'result' => $result,
            'settings' => Plugin::getInstance()->getSettings(),
        ], View::TEMPLATE_MODE_CP);
    }
}
