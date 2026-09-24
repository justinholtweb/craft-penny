<?php

namespace justinholtweb\penny\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\penny\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The control panel surface's one action of its own: "I'm finished".
 *
 * Craft's editor has no notion of handing work in — only of saving and applying drafts, and the
 * session is not allowed to apply its own. So the hand-in bar posts here, and this does what the
 * hosted page's submit button does, then signs the recipient out of an account that no longer
 * exists.
 */
class SessionController extends Controller
{
    public function actionHandIn(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $plugin = Plugin::getInstance();
        $invite = $plugin->sessions->currentInvite();

        if ($invite === null) {
            throw new ForbiddenHttpException('This is not an invited session.');
        }

        if (!$invite->getIsRedeemable()) {
            return $this->asFailure(Craft::t('penny', 'This link has closed, so there is nothing left to hand in.'));
        }

        $plugin->sessions->handIn($invite);

        // Signed out without destroying the session, so the thank-you page can still tell which
        // invite it is thanking somebody for. The account itself is already gone.
        Craft::$app->getUser()->logout(false);
        Craft::$app->getSession()->set(HostedController::HANDED_IN_SESSION_KEY, $invite->id);

        return $this->asSuccess(
            Craft::t('penny', 'Handed in. Thank you.'),
            redirect: $plugin->keys->hostedDoneUrl(),
        );
    }
}
