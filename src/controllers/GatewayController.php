<?php

namespace justinholtweb\penny\controllers;

use craft\web\Controller;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\Plugin;
use yii\web\Response;

/**
 * The pretty link.
 *
 * `/penny/<key>` on the site, forwarding to the control panel route the editing page is actually
 * served from. It deliberately does not *check* the key: doing so would either duplicate every
 * audit entry or teach a caller the difference between "no such link" and "a link that is spent",
 * and the page it forwards to has to answer both questions properly anyway.
 *
 * It does look the key up, once, for one thing only — whether it is a view link. Those are answered
 * here, on the site, because the site is where the page they show lives.
 */
class GatewayController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function actionOpen(string $key): Response
    {
        $keys = Plugin::getInstance()->keys;

        if ($keys->looksLikeKey($key)) {
            $invite = Invite::find()->keyHash($keys->hash($key))->status(null)->one();

            if ($invite instanceof Invite && $invite->getSurface() === Surface::View) {
                /** @var Response */
                return Plugin::getInstance()->runAction('view/index', ['key' => $key]);
            }
        }

        return $this->redirect($keys->hostedUrlForKey($key));
    }
}
