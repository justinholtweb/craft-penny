<?php

namespace justinholtweb\penny\enums;

use Craft;

/**
 * An invite's lifecycle, derived from its dates rather than stored.
 *
 * Storing it would mean an invite that expired while nobody was looking still reads as `pending`
 * until something happens to write the row — and "the link stopped working" has to be true the
 * moment it stops working, not the next time a cron runs.
 */
enum InviteStatus: string
{
    /** Issued, never opened. */
    case Pending = 'pending';

    /** Opened, work possibly in progress, not submitted. */
    case Opened = 'opened';

    /** Submitted and applied. The link is dead. */
    case Submitted = 'submitted';

    /** Submitted, waiting for someone to approve the draft. The link is dead. */
    case AwaitingReview = 'awaitingReview';

    /** Past its expiry date without being submitted. */
    case Expired = 'expired';

    /** Turned off by hand. */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => Craft::t('penny', 'Not opened yet'),
            self::Opened => Craft::t('penny', 'In progress'),
            self::Submitted => Craft::t('penny', 'Submitted'),
            self::AwaitingReview => Craft::t('penny', 'Awaiting review'),
            self::Expired => Craft::t('penny', 'Expired'),
            self::Revoked => Craft::t('penny', 'Revoked'),
        };
    }

    /** The colour Craft's status indicators use for this state. */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'blue',
            self::Opened => 'yellow',
            self::Submitted => 'green',
            self::AwaitingReview => 'orange',
            self::Expired => 'gray',
            self::Revoked => 'red',
        };
    }

    /** Whether an invite in this state can still be opened and worked on. */
    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Opened;
    }
}
