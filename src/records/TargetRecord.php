<?php

namespace justinholtweb\penny\records;

use craft\db\ActiveRecord;
use justinholtweb\penny\db\Table;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $inviteId
 * @property string $kind
 * @property string $elementType
 * @property int|null $elementId
 * @property int|null $entryTypeId
 * @property int|null $sectionId
 * @property int|null $parentId
 * @property int|null $draftId
 * @property int|null $resultElementId
 * @property string|null $layoutElementUids
 * @property string|null $label
 * @property string|null $instructions
 * @property int $sortOrder
 * @property \DateTime|null $dateSaved
 */
class TargetRecord extends ActiveRecord
{
    public const TABLE = Table::TARGETS;

    public static function tableName(): string
    {
        return self::TABLE;
    }

    public function getInvite(): ActiveQueryInterface
    {
        return $this->hasOne(InviteRecord::class, ['id' => 'inviteId']);
    }
}
