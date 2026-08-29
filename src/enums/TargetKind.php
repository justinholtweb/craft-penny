<?php

namespace justinholtweb\penny\enums;

use Craft;

/**
 * Whether a target points at something that exists, or at something the recipient will create.
 */
enum TargetKind: string
{
    case Element = 'element';
    case New = 'new';

    public function label(): string
    {
        return match ($this) {
            self::Element => Craft::t('penny', 'Edit something that exists'),
            self::New => Craft::t('penny', 'Create something new'),
        };
    }
}
