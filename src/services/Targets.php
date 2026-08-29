<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use justinholtweb\penny\db\Table;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\TargetKind;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use justinholtweb\penny\records\TargetRecord;

/**
 * Storage and edition rules for an invite's targets.
 */
class Targets extends Component
{
    /**
     * The element types an invite may point at.
     *
     * Deliberately a list rather than "anything with a field layout": every entry here has been
     * checked for whether it can be edited through a bare field layout form and whether Penny can
     * hold work in progress for it safely.
     *
     * @return array<class-string, string>
     */
    public function supportedElementTypes(): array
    {
        return [
            Entry::class => Entry::displayName(),
            GlobalSet::class => GlobalSet::displayName(),
            Category::class => Category::displayName(),
            Asset::class => Asset::displayName(),
            User::class => User::displayName(),
            Tag::class => Tag::displayName(),
        ];
    }

    /** The types Lite can point at. The rest are Pro. */
    public function liteElementTypes(): array
    {
        return [Entry::class, GlobalSet::class];
    }

    /**
     * Why this target is not allowed, or null if it is.
     *
     * Returns a sentence rather than a boolean because every caller wants to say something useful,
     * and "false" gives an author nothing to act on.
     */
    public function checkEditionSupport(Target $target): ?string
    {
        if (Plugin::getInstance()->isPro()) {
            return null;
        }

        if ($target->getKind() === TargetKind::New) {
            return Craft::t('penny', 'Creating new content is a Pro feature. Lite invites edit something that already exists.');
        }

        if (!in_array($target->elementType, $this->liteElementTypes(), true)) {
            return Craft::t('penny', '{type} targets are a Pro feature. Lite invites cover entries and globals.', [
                'type' => $target->getElementTypeLabel(),
            ]);
        }

        return null;
    }

    // ------------------------------------------------------------------ persistence

    /** @return Target[] */
    public function getTargetsForInvite(Invite $invite): array
    {
        if (!$invite->id) {
            return [];
        }

        $rows = (new Query())
            ->from([Table::TARGETS])
            ->where(['inviteId' => $invite->id])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => $this->hydrate($row, $invite), $rows);
    }

    public function getTargetById(int $id): ?Target
    {
        $row = (new Query())->from([Table::TARGETS])->where(['id' => $id])->one();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Replaces an invite's targets with the given set.
     *
     * Rows are matched by id so that a target which already has a draft or a saved result keeps
     * them across an edit — recreating the row would orphan the draft the recipient is halfway
     * through filling in.
     *
     * @param Target[] $targets
     */
    public function saveTargetsForInvite(Invite $invite, array $targets): void
    {
        $keepIds = [];
        $sortOrder = 0;

        foreach ($targets as $target) {
            $record = $target->id ? TargetRecord::findOne(['id' => $target->id, 'inviteId' => $invite->id]) : null;
            $record ??= new TargetRecord();

            $record->inviteId = $invite->id;
            $record->kind = $target->kind;
            $record->elementType = $target->elementType;
            $record->elementId = $target->elementId;
            $record->entryTypeId = $target->entryTypeId;
            $record->sectionId = $target->sectionId;
            $record->parentId = $target->parentId;
            $record->draftId = $target->draftId;
            $record->resultElementId = $target->resultElementId;
            $record->layoutElementUids = $target->layoutElementUids === null
                ? null
                : json_encode(array_values($target->layoutElementUids));
            $record->label = $target->label;
            $record->instructions = $target->instructions;
            $record->sortOrder = $sortOrder++;
            $record->dateSaved = Db::prepareDateForDb($target->dateSaved);
            $record->save(false);

            $target->id = $record->id;
            $target->uid = $record->uid;
            $keepIds[] = $record->id;
        }

        $condition = ['inviteId' => $invite->id];

        if ($keepIds) {
            $condition = ['and', $condition, ['not', ['id' => $keepIds]]];
        }

        Craft::$app->getDb()->createCommand()->delete(Table::TARGETS, $condition)->execute();
    }

    /** Writes the fields a redemption changes, without touching anything the admin owns. */
    public function markSaved(Target $target, ?int $draftId = null, ?int $resultElementId = null): void
    {
        if (!$target->id) {
            return;
        }

        $values = ['dateSaved' => Db::prepareDateForDb(new \DateTime())];

        if ($draftId !== null) {
            $values['draftId'] = $draftId;
            $target->draftId = $draftId;
        }

        if ($resultElementId !== null) {
            $values['resultElementId'] = $resultElementId;
            $target->resultElementId = $resultElementId;

            // A `new` target has no element until the recipient starts it; once it does, the
            // target points at it like any other, so a later visit resumes rather than restarts.
            if ($target->elementId === null) {
                $values['elementId'] = $resultElementId;
                $target->elementId = $resultElementId;
            }
        }

        Db::update(Table::TARGETS, $values, ['id' => $target->id], updateTimestamp: true);
    }

    private function hydrate(array $row, ?Invite $invite = null): Target
    {
        $uids = $row['layoutElementUids'] ?? null;

        $target = new Target([
            'id' => (int)$row['id'],
            'inviteId' => (int)$row['inviteId'],
            'kind' => $row['kind'],
            'elementType' => $row['elementType'],
            'elementId' => $row['elementId'] !== null ? (int)$row['elementId'] : null,
            'entryTypeId' => $row['entryTypeId'] !== null ? (int)$row['entryTypeId'] : null,
            'sectionId' => $row['sectionId'] !== null ? (int)$row['sectionId'] : null,
            'parentId' => $row['parentId'] !== null ? (int)$row['parentId'] : null,
            'draftId' => $row['draftId'] !== null ? (int)$row['draftId'] : null,
            'resultElementId' => $row['resultElementId'] !== null ? (int)$row['resultElementId'] : null,
            'layoutElementUids' => $uids !== null ? (json_decode($uids, true) ?: []) : null,
            'label' => $row['label'],
            'instructions' => $row['instructions'],
            'sortOrder' => (int)$row['sortOrder'],
            'uid' => $row['uid'],
        ]);

        $target->dateSaved = $row['dateSaved'] !== null ? DateTimeHelper::toDateTime($row['dateSaved']) : null;
        $target->siteId = $invite?->targetSiteId;

        return $target;
    }
}
