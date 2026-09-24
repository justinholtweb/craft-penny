<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\helpers\UrlHelper;
use craft\web\Request as WebRequest;
use justinholtweb\penny\Plugin;

/**
 * Minting, hashing and locating invite keys.
 *
 * Penny stores only the SHA-256 of a key. That means a database dump does not contain a working
 * link, and it also means Penny genuinely cannot show an admin the same link twice — which is why
 * the CP offers *re-issue* rather than *show me that again*.
 */
class Keys extends Component
{
    /** Bytes of entropy per key. 32 is 256 bits; the URL carries 43 characters. */
    private const KEY_BYTES = 32;

    private const RATE_LIMIT_PREFIX = 'penny:attempts:';

    /**
     * A new key.
     *
     * URL-safe base64 rather than hex so the link is 43 characters instead of 64 — a difference
     * that matters when it is going into an email a person may have to retype.
     */
    public function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::KEY_BYTES)), '+/', '-_'), '=');
    }

    public function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * Whether a string could be one of ours.
     *
     * Cheap enough to run before touching the database, which keeps a flood of junk requests from
     * turning into a flood of queries.
     */
    public function looksLikeKey(string $key): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_\-]{20,128}$/', $key);
    }

    /**
     * The pretty, site-facing link — the one that goes in the email.
     *
     * With the prefix emptied no site route is registered at all, so a site link would 404. The
     * hosted link works without one, so that is the link.
     */
    public function urlForKey(string $key): string
    {
        $prefix = trim(Plugin::getInstance()->getSettings()->inviteUriPrefix, '/');

        if ($prefix === '') {
            return $this->hostedUrlForKey($key);
        }

        return UrlHelper::siteUrl("$prefix/$key");
    }

    /**
     * The link the editing page is actually served from.
     *
     * A control panel URL, because control panel field inputs need control panel asset bundles and
     * a control panel request to render at all. The page it serves has none of the control panel's
     * chrome — no nav, no breadcrumb, no way out.
     */
    public function hostedUrlForKey(string $key): string
    {
        // If something has claimed that path as a plugin handle, Craft would demand a login before
        // the controller could say otherwise. The action URL is uglier but cannot be intercepted,
        // and a working link beats a tidy one.
        if (Craft::$app->getPlugins()->getPlugin(Plugin::HOSTED_SEGMENT) !== null) {
            return UrlHelper::actionUrl('penny/hosted/index', ['key' => $key]);
        }

        return UrlHelper::cpUrl(Plugin::HOSTED_SEGMENT . "/$key");
    }

    /** Where a control panel recipient is sent once they have handed in. */
    public function hostedDoneUrl(): string
    {
        // The same interception risk as above, and the same way round it.
        if (Craft::$app->getPlugins()->getPlugin(Plugin::HOSTED_SEGMENT) !== null) {
            return UrlHelper::actionUrl('penny/hosted/done');
        }

        return UrlHelper::cpUrl(Plugin::HOSTED_SEGMENT . '/done');
    }

    // ------------------------------------------------------------------ rate limiting

    /**
     * Whether this caller has spent its budget of wrong guesses.
     *
     * Keyed on the IP, counted in a rolling hour, and only *failures* count — somebody working
     * through a legitimate invite is never throttled by their own progress.
     */
    public function tooManyAttempts(?string $ip = null): bool
    {
        $max = Plugin::getInstance()->getSettings()->maxAttemptsPerHour;

        if ($max <= 0) {
            return false;
        }

        return $this->attemptCount($ip) >= $max;
    }

    public function recordFailedAttempt(?string $ip = null): void
    {
        $key = $this->cacheKey($ip);
        $cache = Craft::$app->getCache();

        // Not atomic, and it does not need to be: the cost of an occasional lost increment under
        // concurrency is one extra guess, and the cost of a lock here would be paid by every
        // legitimate visitor.
        $cache->set($key, $this->attemptCount($ip) + 1, 3600);
    }

    public function clearAttempts(?string $ip = null): void
    {
        Craft::$app->getCache()->delete($this->cacheKey($ip));
    }

    private function attemptCount(?string $ip = null): int
    {
        return (int)Craft::$app->getCache()->get($this->cacheKey($ip));
    }

    private function cacheKey(?string $ip = null): string
    {
        // `Craft::$app->getRequest()` is a console request outside a web request, and that class
        // has no `getUserIP()` at all — so anything reachable from a console command has to ask.
        $request = Craft::$app->getRequest();
        $ip ??= ($request instanceof WebRequest ? $request->getUserIP() : null) ?? 'unknown';

        return self::RATE_LIMIT_PREFIX . md5($ip);
    }
}
