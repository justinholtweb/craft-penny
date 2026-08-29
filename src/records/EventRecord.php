<?php

namespace justinholtweb\penny\records;

use craft\db\ActiveRecord;
use justinholtweb\penny\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $inviteId
 * @property string $type
 * @property string|null $detail
 * @property string|null $ip
 * @property string|null $userAgentHash
 */
class EventRecord extends ActiveRecord
{
    public const TABLE = Table::EVENTS;

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public function getInvite(): ActiveQueryInterface
    {
        return $this->hasOne(InviteRecord::class, ['id' => 'inviteId']);
    }
}
