<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\enums\InviteStatus;
use justinholtweb\penny\models\AccessResult;
use justinholtweb\penny\Plugin;

/**
 * The front door.
 *
 * Every way into an invite — the pretty site link, the hosted page, the hosted save, the control
 * panel handover — comes through `resolve()`. There is no second path, which is what makes "the
 * link stopped working" a statement about one function rather than about four.
 */
class Access extends Component
{
    public function resolve(string $key): AccessResult
    {
        $plugin = Plugin::getInstance();
        $keys = $plugin->keys;

        if ($keys->tooManyAttempts()) {
            return AccessResult::deny(
                AccessResult::REASON_THROTTLED,
                Craft::t('penny', 'Too many attempts. Try again later.'),
            );
        }

        if (!$keys->looksLikeKey($key)) {
            $keys->recordFailedAttempt();

            return $this->unknown();
        }

        // A single indexed equality test on a hash: nothing here compares strings, so there is no
        // comparison whose timing could be measured to walk a key out one character at a time.
        $invite = Invite::find()
            ->keyHash($keys->hash($key))
            ->status(null)
            ->one();

        if (!$invite instanceof Invite) {
            $keys->recordFailedAttempt();

            return $this->unknown();
        }

        // A correct key, whatever state the invite is in, is not a guess.
        $keys->clearAttempts();

        if ($invite->getIsRedeemable()) {
            return AccessResult::allow($invite);
        }

        $plugin->audit->record($invite, EventType::Denied, $invite->getInviteStatus()->value);

        return match ($invite->getInviteStatus()) {
            InviteStatus::Expired => AccessResult::deny(
                AccessResult::REASON_EXPIRED,
                Craft::t('penny', 'This link has expired.'),
                $invite,
            ),
            InviteStatus::Revoked => AccessResult::deny(
                AccessResult::REASON_REVOKED,
                Craft::t('penny', 'This link has been turned off.'),
                $invite,
            ),
            default => AccessResult::deny(
                AccessResult::REASON_SUBMITTED,
                Craft::t('penny', 'This link has already been used.'),
                $invite,
            ),
        };
    }

    /**
     * The same answer for "never existed" and "malformed".
     *
     * Telling the two apart is only useful to somebody guessing.
     */
    private function unknown(): AccessResult
    {
        return AccessResult::deny(
            AccessResult::REASON_UNKNOWN,
            Craft::t('penny', 'This link is not valid.'),
        );
    }
}
