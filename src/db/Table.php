<?php

namespace justinholtweb\penny\db;

/**
 * Penny's tables.
 *
 * Kept in one place so migrations, records and raw queries can never disagree about a name.
 */
abstract class Table
{
    public const INVITES = '{{%penny_invites}}';
    public const TARGETS = '{{%penny_targets}}';
    public const EVENTS = '{{%penny_events}}';
}
