<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use DateTime;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use yii\web\Cookie;

/**
 * View links: one person, one look, at a page on the site — drafts and disabled entries included.
 *
 * "Used once" cannot mean "fetched once". Mail scanners, Slack and iMessage previews and Safe Links
 * all fetch a link before its recipient does, and a page makes more than one request of itself
 * anyway. So a view link is spent by a *choice*, a button nothing automated presses, and the page
 * is then bound to the one browser that pressed it — by a validated cookie — for a short window.
 * The URL the page is shown at carries a Craft token, but the token alone gets nobody anywhere:
 * copied into another browser it has no cookie to go with it.
 */
class Views extends Component
{
    /** The token route that renders the page. Craft calls it with the invite id and nothing else. */
    public const RENDER_ROUTE = 'penny/view/render';

    private const COOKIE_PREFIX = 'penny_view_';

    /**
     * The element the link shows: the draft it was shared from, if there is one, otherwise the
     * element itself, whatever its status — showing unpublished work is the point.
     */
    public function elementFor(Invite $invite, Target $target): ?ElementInterface
    {
        /** @var class-string<ElementInterface> $type */
        $type = $target->elementType;

        if (!$target->elementId || !class_exists($type)) {
            return null;
        }

        $query = $type::find()
            ->siteId($target->siteId ?? $invite->targetSiteId)
            ->status(null);

        $element = $target->draftId
            ? $query->draftId($target->draftId)->one()
            : $query->id($target->elementId)->one();

        return $element instanceof ElementInterface ? $element : null;
    }

    /** The element behind a view invite, or null when there is nothing to show. */
    public function elementForInvite(Invite $invite): ?ElementInterface
    {
        $target = $invite->getTargets()[0] ?? null;

        return $target !== null ? $this->elementFor($invite, $target) : null;
    }

    /**
     * Spends the link, binds the page to this browser, and returns where to send it.
     *
     * Null when there is nothing to send it to — in which case nothing has been spent either.
     */
    public function open(Invite $invite, WebResponse $response): ?string
    {
        $element = $this->elementForInvite($invite);
        $url = $element?->getUrl();

        if ($url === null) {
            return null;
        }

        Plugin::getInstance()->invites->markViewed($invite);

        $until = $this->windowEndsAt($invite);

        $response->getCookies()->add(new Cookie(Craft::cookieConfig([
            'name' => $this->cookieName($invite),
            'value' => $this->cookieValue($invite),
            'expire' => $until->getTimestamp(),
            'httpOnly' => true,
        ])));

        // No usage limit on the token: the page reloads, and a limit of one would spend itself on
        // the first request. What stops it being shared is the cookie, checked on every render.
        //
        // And it outlives the window by a day, on purpose. Craft deletes an expired token and
        // answers a request for it with a bare "Invalid token" error before any plugin is asked;
        // kept alive, the request reaches `refusal()`, which closes the page and says why.
        $token = Craft::$app->getTokens()->createToken(
            [self::RENDER_ROUTE, ['inviteId' => $invite->id]],
            null,
            (clone $until)->modify('+1 day'),
        );

        if ($token === false) {
            Craft::error("Could not create a view token for invite $invite->id.", Plugin::LOG_CATEGORY);

            return null;
        }

        return UrlHelper::urlWithToken($url, $token);
    }

    /**
     * Why this browser may not see the page right now, or null if it may.
     *
     * Asked on every render, not once, so revoking the invite closes a page somebody is still
     * reloading, and the window really is a window.
     */
    public function refusal(?Invite $invite, WebRequest $request): ?string
    {
        if ($invite === null || $invite->getSurface() !== Surface::View || $invite->dateApplied === null) {
            return Craft::t('penny', 'This link is not valid.');
        }

        if ($invite->dateRevoked !== null) {
            return Craft::t('penny', 'This link has been turned off.');
        }

        if ($request->getCookies()->getValue($this->cookieName($invite)) !== $this->cookieValue($invite)) {
            return Craft::t('penny', 'This link has already been used.');
        }

        if ($this->windowEndsAt($invite)->getTimestamp() <= time()) {
            return Craft::t('penny', 'This page has closed.');
        }

        return null;
    }

    /** When the browser that opened the link stops being able to see it. */
    public function windowEndsAt(Invite $invite): DateTime
    {
        $minutes = max(1, Plugin::getInstance()->getSettings()->viewWindowMinutes);
        $from = $invite->dateApplied ?? new DateTime();

        return (clone $from)->modify("+$minutes minutes");
    }

    private function cookieName(Invite $invite): string
    {
        return self::COOKIE_PREFIX . $invite->id;
    }

    /**
     * What the cookie has to hold.
     *
     * Yii signs cookies with the site's validation key, so this cannot be forged; it names the key
     * as well as the invite so that a re-issued link starts from nobody.
     */
    private function cookieValue(Invite $invite): string
    {
        return $invite->id . ':' . ($invite->keyIssuedAt?->getTimestamp() ?? 0) . ':' . substr($invite->keyHash, 0, 16);
    }
}
