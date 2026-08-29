<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Request as WebRequest;
use DateTime;
use justinholtweb\penny\db\Table;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\Plugin;
use justinholtweb\penny\records\EventRecord;

/**
 * The audit trail.
 *
 * Append-only, and deliberately dumb: it records what happened, when, and roughly who from. It
 * never records the key — an audit trail that leaks working credentials is worse than none.
 */
class Audit extends Component
{
    public function record(Invite $invite, EventType $type, ?string $detail = null): void
    {
        if (!$invite->id) {
            return;
        }

        $request = Craft::$app->getRequest();
        $isWeb = $request instanceof WebRequest;

        $record = new EventRecord();
        $record->inviteId = $invite->id;
        $record->type = $type->value;
        $record->detail = $detail;
        $record->ip = $isWeb ? $request->getUserIP() : null;

        // Hashed, not stored: it is enough to tell two visitors apart and to notice a link being
        // opened from somewhere new, and it is not enough to be a tracking record.
        $userAgent = $isWeb ? $request->getUserAgent() : null;
        $record->userAgentHash = $userAgent !== null ? hash('sha256', $userAgent) : null;

        $record->save(false);
    }

    /**
     * @return array<int, array{type: EventType, detail: string|null, ip: string|null, date: DateTime}>
     */
    public function eventsForInvite(Invite $invite, int $limit = 200): array
    {
        if (!$invite->id) {
            return [];
        }

        $rows = (new Query())
            ->select(['type', 'detail', 'ip', 'dateCreated'])
            ->from([Table::EVENTS])
            ->where(['inviteId' => $invite->id])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $events = [];

        foreach ($rows as $row) {
            $type = EventType::tryFrom($row['type']);

            if ($type === null) {
                continue;
            }

            $events[] = [
                'type' => $type,
                'detail' => $row['detail'],
                'ip' => $row['ip'],
                'date' => DateTimeHelper::toDateTime($row['dateCreated']),
            ];
        }

        return $events;
    }

    /**
     * Drops audit rows older than the retention setting.
     *
     * Runs from garbage collection rather than on a schedule of its own, because Craft already has
     * a place where "things that need occasionally tidying" happen.
     */
    public function prune(): void
    {
        $days = Plugin::getInstance()->getSettings()->keepEventsDays;

        if ($days <= 0) {
            return;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        Craft::$app->getDb()->createCommand()
            ->delete(Table::EVENTS, ['<', 'dateCreated', Db::prepareDateForDb($cutoff)])
            ->execute();
    }
}
