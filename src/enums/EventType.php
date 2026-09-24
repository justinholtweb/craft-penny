<?php

namespace justinholtweb\penny\enums;

use Craft;

/**
 * What the audit trail records.
 */
enum EventType: string
{
    case Created = 'created';
    case Sent = 'sent';
    case Reminded = 'reminded';
    case Reissued = 'reissued';
    case Opened = 'opened';
    case Saved = 'saved';
    case Submitted = 'submitted';
    case Applied = 'applied';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Denied = 'denied';

    public function label(): string
    {
        return match ($this) {
            self::Created => Craft::t('penny', 'Invite created'),
            self::Sent => Craft::t('penny', 'Link sent'),
            self::Reminded => Craft::t('penny', 'Reminder sent'),
            self::Reissued => Craft::t('penny', 'Link re-issued'),
            self::Opened => Craft::t('penny', 'Link opened'),
            self::Saved => Craft::t('penny', 'Progress saved'),
            self::Submitted => Craft::t('penny', 'Submitted'),
            self::Applied => Craft::t('penny', 'Changes applied'),
            self::Revoked => Craft::t('penny', 'Revoked'),
            self::Expired => Craft::t('penny', 'Expired'),
            self::Denied => Craft::t('penny', 'Access denied'),
        };
    }
}
