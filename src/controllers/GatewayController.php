<?php

namespace justinholtweb\penny\controllers;

use craft\web\Controller;
use justinholtweb\penny\Plugin;
use yii\web\Response;

/**
 * The pretty link.
 *
 * `/penny/<key>` on the site, forwarding to the control panel route the editing page is actually
 * served from. It deliberately does not check the key first: doing so would either duplicate every
 * audit entry or teach a caller the difference between "no such link" and "a link that is spent",
 * and the page it forwards to has to answer both questions properly anyway.
 */
class GatewayController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function actionOpen(string $key): Response
    {
        return $this->redirect(Plugin::getInstance()->keys->hostedUrlForKey($key));
    }
}
