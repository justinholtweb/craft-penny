<?php

namespace justinholtweb\penny\elements\db;

use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use DateTime;
use justinholtweb\penny\db\Table;
use justinholtweb\penny\enums\InviteStatus;
use justinholtweb\penny\enums\Surface;

/**
 * @method \justinholtweb\penny\elements\Invite[] all($db = null)
 * @method \justinholtweb\penny\elements\Invite|null one($db = null)
 */
class InviteQuery extends ElementQuery
{
    public mixed $surface = null;
    public mixed $recipientEmail = null;
    public mixed $targetSiteId = null;
    public mixed $sessionUserId = null;
    public mixed $keyHash = null;

    /** Invites whose scope includes a particular element. */
    public ?int $forElementId = null;

    /** Invites that can still be opened, whatever the reason they might not be. */
    public ?bool $live = null;

    protected array $defaultOrderBy = ['penny_invites.dateCreated' => SORT_DESC];

    public function surface(mixed $value): static
    {
        $this->surface = $value instanceof Surface ? $value->value : $value;

        return $this;
    }

    public function recipientEmail(mixed $value): static
    {
        $this->recipientEmail = $value;

        return $this;
    }

    public function targetSiteId(mixed $value): static
    {
        $this->targetSiteId = $value;

        return $this;
    }

    public function sessionUserId(mixed $value): static
    {
        $this->sessionUserId = $value;

        return $this;
    }

    public function keyHash(mixed $value): static
    {
        $this->keyHash = $value;

        return $this;
    }

    public function forElementId(?int $value): static
    {
        $this->forElementId = $value;

        return $this;
    }

    public function live(?bool $value = true): static
    {
        $this->live = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->live === true) {
            // "Live" is the union of the two statuses that can still be opened, not a column.
            $this->subQuery->andWhere([
                'and',
                ['penny_invites.dateRevoked' => null],
                ['penny_invites.dateSubmitted' => null],
                $this->notExpiredCondition(),
            ]);
        }

        $this->joinElementTable('penny_invites');

        $this->query->select([
            'penny_invites.keyHash',
            'penny_invites.keyIssuedAt',
            'penny_invites.surface',
            'penny_invites.recipientName',
            'penny_invites.recipientEmail',
            'penny_invites.message',
            'penny_invites.expiryDate',
            'penny_invites.dateSent',
            'penny_invites.dateFirstOpened',
            'penny_invites.dateSubmitted',
            'penny_invites.dateApplied',
            'penny_invites.dateRevoked',
            'penny_invites.requireReview',
            'penny_invites.notifyEmails',
            'penny_invites.branding',
            'penny_invites.authorId',
            'penny_invites.sessionUserId',
            'penny_invites.targetSiteId',
        ]);

        if ($this->surface !== null) {
            $this->subQuery->andWhere(Db::parseParam('penny_invites.surface', $this->surface));
        }

        if ($this->recipientEmail !== null) {
            $this->subQuery->andWhere(Db::parseParam('penny_invites.recipientEmail', $this->recipientEmail));
        }

        if ($this->targetSiteId !== null) {
            $this->subQuery->andWhere(Db::parseParam('penny_invites.targetSiteId', $this->targetSiteId));
        }

        if ($this->sessionUserId !== null) {
            $this->subQuery->andWhere(Db::parseParam('penny_invites.sessionUserId', $this->sessionUserId));
        }

        if ($this->keyHash !== null) {
            $this->subQuery->andWhere(Db::parseParam('penny_invites.keyHash', $this->keyHash));
        }

        if ($this->forElementId !== null) {
            $this->subQuery->andWhere([
                'penny_invites.id' => (new \craft\db\Query())
                    ->select(['inviteId'])
                    ->from([Table::TARGETS])
                    ->where(['elementId' => $this->forElementId]),
            ]);
        }

        return parent::beforePrepare();
    }

    /**
     * @throws QueryAbortedException
     */
    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            InviteStatus::Pending->value => [
                'and',
                ['penny_invites.dateRevoked' => null],
                ['penny_invites.dateSubmitted' => null],
                ['penny_invites.dateFirstOpened' => null],
                $this->notExpiredCondition(),
            ],
            InviteStatus::Opened->value => [
                'and',
                ['penny_invites.dateRevoked' => null],
                ['penny_invites.dateSubmitted' => null],
                ['not', ['penny_invites.dateFirstOpened' => null]],
                $this->notExpiredCondition(),
            ],
            InviteStatus::Submitted->value => [
                'and',
                ['penny_invites.dateRevoked' => null],
                ['not', ['penny_invites.dateApplied' => null]],
            ],
            InviteStatus::AwaitingReview->value => [
                'and',
                ['penny_invites.dateRevoked' => null],
                ['not', ['penny_invites.dateSubmitted' => null]],
                ['penny_invites.dateApplied' => null],
            ],
            InviteStatus::Expired->value => [
                'and',
                ['penny_invites.dateRevoked' => null],
                ['penny_invites.dateSubmitted' => null],
                ['not', ['penny_invites.expiryDate' => null]],
                ['<', 'penny_invites.expiryDate', Db::prepareDateForDb(new DateTime())],
            ],
            InviteStatus::Revoked->value => ['not', ['penny_invites.dateRevoked' => null]],
            default => parent::statusCondition($status),
        };
    }

    /**
     * An invite with no expiry date is not expired, so this is deliberately an OR rather than a
     * plain comparison — SQL comparisons against NULL are never true, and writing it the obvious
     * way silently hides every invite that was created without a deadline.
     */
    private function notExpiredCondition(): array
    {
        return [
            'or',
            ['penny_invites.expiryDate' => null],
            ['>=', 'penny_invites.expiryDate', Db::prepareDateForDb(new DateTime())],
        ];
    }
}
