<?php

namespace justinholtweb\penny\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use craft\records\Site;
use craft\records\User;
use justinholtweb\penny\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $keyHash
 * @property \DateTime|null $keyIssuedAt
 * @property string $surface
 * @property string|null $recipientName
 * @property string|null $recipientEmail
 * @property string|null $message
 * @property \DateTime|null $expiryDate
 * @property \DateTime|null $dateSent
 * @property \DateTime|null $dateFirstOpened
 * @property \DateTime|null $dateSubmitted
 * @property \DateTime|null $dateApplied
 * @property \DateTime|null $dateRevoked
 * @property bool $requireReview
 * @property string|null $notifyEmails
 * @property string|null $branding
 * @property int|null $authorId
 * @property int|null $sessionUserId
 * @property int $targetSiteId
 */
class InviteRecord extends ActiveRecord
{
    public const TABLE = Table::INVITES;

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    public function getAuthor(): ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'authorId']);
    }

    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'targetSiteId']);
    }
}
