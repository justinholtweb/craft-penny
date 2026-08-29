<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\penny\db\Table;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\Plugin;
use Throwable;

/**
 * The lifecycle of an invite: minting, re-issuing, opening, submitting, revoking.
 *
 * Every state change goes through here, because every one of them also has to write an audit row,
 * and a state change that is invisible to the audit trail is the one that will be argued about.
 */
class Invites extends Component
{
    public function getInviteById(int $id): ?Invite
    {
        $invite = Invite::find()->id($id)->status(null)->one();

        return $invite instanceof Invite ? $invite : null;
    }

    /**
     * Fills in the things an invite needs but nobody wants to type, and mints its key.
     *
     * The plain key lives on the returned element for this request only — long enough for the
     * "copy this link" screen and for the email — and is never written anywhere.
     */
    public function create(array $attributes = []): Invite
    {
        $settings = Plugin::getInstance()->getSettings();
        $invite = new Invite();

        $invite->targetSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $invite->surface = $settings->defaultSurface;
        $invite->authorId = Craft::$app->getUser()->getIdentity()?->id;
        $invite->setNotifyEmails($settings->getNotifyEmails());

        if ($settings->defaultExpiryDays > 0) {
            $invite->expiryDate = (new DateTime())->modify("+{$settings->defaultExpiryDays} days");
        }

        Craft::configure($invite, $attributes);
        $this->issueKey($invite);

        return $invite;
    }

    /** Puts a fresh key on an invite, in memory. The caller saves. */
    public function issueKey(Invite $invite): string
    {
        $keys = Plugin::getInstance()->keys;
        $key = $keys->mint();

        $invite->keyHash = $keys->hash($key);
        $invite->keyIssuedAt = new DateTime();
        $invite->setPlainKey($key);

        return $key;
    }

    public function save(Invite $invite, bool $runValidation = true): bool
    {
        $isNew = !$invite->id;

        if (!Craft::$app->getElements()->saveElement($invite, $runValidation)) {
            return false;
        }

        if ($isNew) {
            Plugin::getInstance()->audit->record($invite, EventType::Created);
        }

        return true;
    }

    /**
     * Mints a new key and kills the old one.
     *
     * This is the only way to get a link out of Penny a second time, because only the hash is
     * stored. It is also what a reminder does — a reminder that re-sent the original key would
     * mean the key had to be retrievable, and then a database dump would be a set of live links.
     */
    public function reissue(Invite $invite): ?string
    {
        if (!$invite->getIsRedeemable() && $invite->dateRevoked === null && $invite->dateSubmitted !== null) {
            return null;
        }

        $key = $this->issueKey($invite);
        $invite->dateRevoked = null;

        if (!$this->save($invite, runValidation: false)) {
            return null;
        }

        Plugin::getInstance()->audit->record($invite, EventType::Reissued);

        return $key;
    }

    /**
     * Notes that somebody has the link open.
     *
     * Only the first visit is audited. A recipient who works through a long form over an afternoon
     * will load the page a dozen times, and an audit trail that records all of them buries the
     * events an admin actually reads it for.
     */
    public function markOpened(Invite $invite): void
    {
        if ($invite->dateFirstOpened !== null) {
            return;
        }

        $invite->dateFirstOpened = new DateTime();

        // Written straight to the row rather than through a full element save: it must not be able
        // to fail validation on unrelated fields, and it must not bump anything an admin would
        // read as "somebody edited this invite".
        Db::update(Table::INVITES, [
            'dateFirstOpened' => Db::prepareDateForDb($invite->dateFirstOpened),
        ], ['id' => $invite->id], updateTimestamp: false);

        Plugin::getInstance()->audit->record($invite, EventType::Opened);
    }

    /**
     * The link is now spent.
     *
     * `dateSubmitted` alone means "the recipient is finished"; `dateApplied` means "and it is
     * live". Review mode is the gap between the two, and the gap is what the CP shows an admin.
     */
    public function markSubmitted(Invite $invite, bool $applied): void
    {
        $now = new DateTime();
        $invite->dateSubmitted = $now;
        $values = ['dateSubmitted' => Db::prepareDateForDb($now)];

        if ($applied) {
            $invite->dateApplied = $now;
            $values['dateApplied'] = Db::prepareDateForDb($now);
        }

        Db::update(Table::INVITES, $values, ['id' => $invite->id], updateTimestamp: false);

        Plugin::getInstance()->audit->record($invite, EventType::Submitted);

        if ($applied) {
            Plugin::getInstance()->audit->record($invite, EventType::Applied);
        }

        // A control panel session outlives its usefulness the moment the work is handed in.
        $this->endSession($invite);
    }

    /** An admin has approved a submission that was held for review. */
    public function markApplied(Invite $invite): void
    {
        $invite->dateApplied = new DateTime();

        Db::update(Table::INVITES, [
            'dateApplied' => Db::prepareDateForDb($invite->dateApplied),
        ], ['id' => $invite->id], updateTimestamp: false);

        Plugin::getInstance()->audit->record($invite, EventType::Applied);
    }

    public function revoke(Invite $invite, bool $save = true): bool
    {
        $this->endSession($invite);

        if (!$save) {
            return true;
        }

        $invite->dateRevoked = new DateTime();

        Db::update(Table::INVITES, [
            'dateRevoked' => Db::prepareDateForDb($invite->dateRevoked),
        ], ['id' => $invite->id], updateTimestamp: false);

        Plugin::getInstance()->audit->record($invite, EventType::Revoked);

        return true;
    }

    /**
     * Sweeps invites that have run out of time.
     *
     * Expiry itself needs no sweep — the status is derived, so an expired link stops working the
     * second it expires. This exists for the thing expiry *cannot* do on its own: an ephemeral
     * control panel user whose invite lapsed while they were not looking at it.
     */
    public function sweepExpired(): int
    {
        $swept = 0;

        $invites = Invite::find()
            ->status(null)
            ->surface(Surface::Cp)
            ->sessionUserId(['not', null])
            ->all();

        foreach ($invites as $invite) {
            if ($invite->getIsRedeemable()) {
                continue;
            }

            $this->endSession($invite);
            Plugin::getInstance()->audit->record($invite, EventType::Expired);
            $swept++;
        }

        return $swept;
    }

    /**
     * Tears down an invite's control panel session, if it has one.
     *
     * Never fatal: revocation is often the response to something already having gone wrong, and a
     * failure to delete a suspended user must not stop the invite being turned off.
     */
    public function endSession(Invite $invite): void
    {
        if ($invite->getSurface() !== Surface::Cp || !$invite->sessionUserId) {
            return;
        }

        try {
            Plugin::getInstance()->sessions->revokeFor($invite);
        } catch (Throwable $e) {
            Craft::error("Could not end the session for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);
        }
    }
}
