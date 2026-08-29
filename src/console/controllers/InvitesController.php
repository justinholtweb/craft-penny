<?php

namespace justinholtweb\penny\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\InviteStatus;
use justinholtweb\penny\Plugin;
use yii\console\ExitCode;

/**
 * Penny from the command line.
 *
 * The reminder command is the one worth putting on cron; the rest exist because an agency handing
 * out a hundred invites should not have to click a hundred times.
 */
class InvitesController extends Controller
{
    /** Only invites expiring within this many days. Defaults to the plugin setting. */
    public ?int $days = null;

    /** Show what would happen without sending or changing anything. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'remind' => array_merge(parent::options($actionID), ['days', 'dryRun']),
            'prune' => array_merge(parent::options($actionID), ['dryRun']),
            default => parent::options($actionID),
        };
    }

    /**
     * Lists invites and their state.
     */
    public function actionIndex(?string $status = null): int
    {
        $query = Invite::find()->status($status ?? null);

        $invites = $query->all();

        if (!$invites) {
            $this->stdout("No invites.\n");

            return ExitCode::OK;
        }

        foreach ($invites as $invite) {
            $state = $invite->getInviteStatus();

            $this->stdout(sprintf('%-6s ', "#$invite->id"), Console::FG_GREY);
            $this->stdout(sprintf('%-16s ', $state->label()), $this->colorFor($state));
            $this->stdout(sprintf('%-34s ', mb_strimwidth($invite->getUiLabel(), 0, 33, '…')));
            $this->stdout(sprintf('%-28s ', mb_strimwidth($invite->getTargetSummary(), 0, 27, '…')), Console::FG_GREY);
            $this->stdout(($invite->expiryDate?->format('Y-m-d H:i') ?? '—') . "\n", Console::FG_GREY);
        }

        $this->stdout(sprintf("\n%d invites.\n", count($invites)));

        return ExitCode::OK;
    }

    /**
     * Emails a reminder for invites that are about to run out.
     *
     * Put this on cron. A reminder re-issues the link — only a hash of the original is stored, so
     * there is nothing to re-send — which means the earlier link stops working. That is deliberate:
     * one live link per invite at a time is the whole promise.
     */
    public function actionRemind(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            $this->stderr("Reminders are a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::UNAVAILABLE;
        }

        $days = $this->days ?? $plugin->getSettings()->remindDaysBefore;

        if ($days <= 0) {
            $this->stdout("Reminders are switched off (set `remindDaysBefore`, or pass --days).\n");

            return ExitCode::OK;
        }

        $cutoff = (new DateTime())->modify("+$days days");
        $sent = 0;

        foreach (Invite::find()->live()->all() as $invite) {
            if ($invite->expiryDate === null || $invite->expiryDate > $cutoff || !$invite->recipientEmail) {
                continue;
            }

            // Somebody who has not opened it yet is the person a reminder is for. Somebody midway
            // through does not need chasing, and re-issuing would take the link out from under them.
            if ($invite->dateFirstOpened !== null) {
                continue;
            }

            if ($this->dryRun) {
                $this->stdout("Would remind {$invite->recipientEmail} about #$invite->id\n");
                $sent++;
                continue;
            }

            $key = $plugin->invites->reissue($invite);

            if ($key !== null && $plugin->notifications->sendReminder($invite, $key)) {
                $this->stdout("Reminded {$invite->recipientEmail} about #$invite->id\n", Console::FG_GREEN);
                $sent++;
            } else {
                $this->stderr("Could not remind {$invite->recipientEmail} about #$invite->id\n", Console::FG_RED);
            }
        }

        $this->stdout(sprintf("\n%d reminder(s)%s.\n", $sent, $this->dryRun ? ' would be sent' : ' sent'));

        return ExitCode::OK;
    }

    /**
     * Revokes an invite, so its link stops working.
     */
    public function actionRevoke(int $inviteId): int
    {
        $invite = Plugin::getInstance()->invites->getInviteById($inviteId);

        if ($invite === null) {
            $this->stderr("No invite #$inviteId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        Plugin::getInstance()->invites->revoke($invite);
        $this->stdout("Revoked #$inviteId.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Mints a new link for an invite and prints it.
     *
     * The old link stops working, because Penny only ever has one live key per invite.
     */
    public function actionReissue(int $inviteId): int
    {
        $invite = Plugin::getInstance()->invites->getInviteById($inviteId);

        if ($invite === null) {
            $this->stderr("No invite #$inviteId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $key = Plugin::getInstance()->invites->reissue($invite);

        if ($key === null) {
            $this->stderr("Couldn’t re-issue #$inviteId.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(Plugin::getInstance()->keys->urlForKey($key) . "\n");

        return ExitCode::OK;
    }

    /**
     * Tidies up: disposes of ephemeral control panel accounts whose invites have lapsed, and
     * trims the audit trail to the retention setting.
     */
    public function actionPrune(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->dryRun) {
            $stale = 0;

            foreach (Invite::find()->status(null)->sessionUserId(['not', null])->all() as $invite) {
                if (!$invite->getIsRedeemable()) {
                    $stale++;
                }
            }

            $this->stdout("Would end $stale stale session(s) and trim the audit trail.\n");

            return ExitCode::OK;
        }

        $swept = $plugin->invites->sweepExpired();
        $plugin->audit->prune();

        $this->stdout("Ended $swept stale session(s). Audit trail trimmed.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function colorFor(InviteStatus $status): int
    {
        return match ($status) {
            InviteStatus::Submitted => Console::FG_GREEN,
            InviteStatus::AwaitingReview => Console::FG_YELLOW,
            InviteStatus::Revoked => Console::FG_RED,
            InviteStatus::Expired => Console::FG_GREY,
            default => Console::FG_CYAN,
        };
    }
}
