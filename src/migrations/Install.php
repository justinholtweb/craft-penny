<?php

namespace justinholtweb\penny\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\records\EventRecord;
use justinholtweb\penny\records\InviteRecord;
use justinholtweb\penny\records\TargetRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        // Element rows first: the invites table hangs off `elements.id`, so dropping it while the
        // element rows survive leaves rows nothing can reach and garbage collection cannot see.
        $this->delete(CraftTable::ELEMENTS, ['type' => Invite::class]);

        $this->dropTableIfExists(EventRecord::TABLE);
        $this->dropTableIfExists(TargetRecord::TABLE);
        $this->dropTableIfExists(InviteRecord::TABLE);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(InviteRecord::TABLE, [
            'id' => $this->integer()->notNull(),

            // Only the hash of the key is ever stored, so a database dump does not contain a
            // working link. See services\Keys.
            'keyHash' => $this->char(64)->notNull(),
            'keyIssuedAt' => $this->dateTime(),

            'surface' => $this->string(16)->notNull()->defaultValue('hosted'),
            'recipientName' => $this->string(),
            'recipientEmail' => $this->string(),
            'message' => $this->text(),

            'expiryDate' => $this->dateTime(),
            'dateSent' => $this->dateTime(),
            'dateFirstOpened' => $this->dateTime(),
            'dateSubmitted' => $this->dateTime(),

            // Separate from `dateSubmitted` so that "submitted, waiting for someone to approve the
            // draft" is a state the index can filter on rather than one an admin has to infer.
            'dateApplied' => $this->dateTime(),

            'dateRevoked' => $this->dateTime(),

            'requireReview' => $this->boolean()->notNull()->defaultValue(false),
            'notifyEmails' => $this->text(),
            'branding' => $this->text(),

            'authorId' => $this->integer(),
            'sessionUserId' => $this->integer(),

            // Not `siteId`: craft\base\Element already owns that property, and this is a
            // different thing — the site whose content the invite edits.
            'targetSiteId' => $this->integer()->notNull(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createTable(TargetRecord::TABLE, [
            'id' => $this->primaryKey(),
            'inviteId' => $this->integer()->notNull(),
            'kind' => $this->string(16)->notNull()->defaultValue('element'),

            'elementType' => $this->string()->notNull(),
            'elementId' => $this->integer(),

            // `new` targets only — what to create, and where to hang it.
            'entryTypeId' => $this->integer(),
            'sectionId' => $this->integer(),
            'parentId' => $this->integer(),

            // Where in-progress work lives, when the element type supports drafts.
            'draftId' => $this->integer(),
            'resultElementId' => $this->integer(),

            // The scope: field layout element UIDs. NULL means the whole layout.
            'layoutElementUids' => $this->text(),

            'label' => $this->string(),
            'instructions' => $this->text(),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateSaved' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(EventRecord::TABLE, [
            'id' => $this->primaryKey(),
            'inviteId' => $this->integer()->notNull(),
            'type' => $this->string(24)->notNull(),
            'detail' => $this->text(),

            // Recorded for the audit trail. Never the key.
            'ip' => $this->string(45),
            'userAgentHash' => $this->char(64),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // Unique, because redemption is a single indexed equality test on the hash — which is also
        // why there is no string comparison here whose timing could leak anything.
        $this->createIndex(null, InviteRecord::TABLE, ['keyHash'], true);
        $this->createIndex(null, InviteRecord::TABLE, ['targetSiteId'], false);
        $this->createIndex(null, InviteRecord::TABLE, ['expiryDate'], false);
        $this->createIndex(null, InviteRecord::TABLE, ['sessionUserId'], false);

        $this->createIndex(null, TargetRecord::TABLE, ['inviteId', 'sortOrder'], false);
        $this->createIndex(null, TargetRecord::TABLE, ['elementId'], false);
        $this->createIndex(null, TargetRecord::TABLE, ['draftId'], false);

        $this->createIndex(null, EventRecord::TABLE, ['inviteId', 'dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, InviteRecord::TABLE, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, InviteRecord::TABLE, ['targetSiteId'], CraftTable::SITES, ['id'], 'CASCADE');
        $this->addForeignKey(null, InviteRecord::TABLE, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');

        // Deliberately SET NULL rather than CASCADE: deleting the ephemeral user must not take the
        // invite and its audit trail with it, and revocation deletes that user by design.
        $this->addForeignKey(null, InviteRecord::TABLE, ['sessionUserId'], CraftTable::USERS, ['id'], 'SET NULL');

        $this->addForeignKey(null, TargetRecord::TABLE, ['inviteId'], InviteRecord::TABLE, ['id'], 'CASCADE');

        // No foreign key on elementId/draftId/resultElementId. A target may point at an element
        // that is later deleted, and the invite should then say so rather than vanish; the record
        // is resolved through an element query, which excludes trashed rows anyway.
        $this->addForeignKey(null, EventRecord::TABLE, ['inviteId'], InviteRecord::TABLE, ['id'], 'CASCADE');
    }
}
