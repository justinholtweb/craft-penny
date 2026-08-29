<?php

namespace justinholtweb\penny\models;

use craft\base\Model;
use justinholtweb\penny\elements\Invite;

/**
 * The answer to "does this key open anything".
 *
 * A result object rather than an exception because the unhappy path is the common one — links get
 * opened twice, forwarded, and clicked a week late — and every one of those deserves a page that
 * explains itself rather than a stack trace or a bare 404.
 */
class AccessResult extends Model
{
    public const REASON_UNKNOWN = 'unknown';
    public const REASON_THROTTLED = 'throttled';
    public const REASON_EXPIRED = 'expired';
    public const REASON_SUBMITTED = 'submitted';
    public const REASON_REVOKED = 'revoked';
    public const REASON_MISCONFIGURED = 'misconfigured';

    public bool $ok = false;
    public ?Invite $invite = null;
    public ?string $reason = null;
    public ?string $message = null;

    public static function allow(Invite $invite): self
    {
        return new self(['ok' => true, 'invite' => $invite]);
    }

    public static function deny(string $reason, string $message, ?Invite $invite = null): self
    {
        return new self(['ok' => false, 'reason' => $reason, 'message' => $message, 'invite' => $invite]);
    }

    /**
     * Whether the caller should be told *why*, or only that it did not work.
     *
     * A key that matches nothing gets a deliberately vague answer: confirming that a given string
     * is a real invite which happens to be spent is more than an unknown caller needs to know.
     */
    public function getIsSpecific(): bool
    {
        return $this->invite !== null;
    }
}
