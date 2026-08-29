<?php

namespace justinholtweb\penny\enums;

use Craft;

/**
 * Where an invite's recipient does the editing.
 */
enum Surface: string
{
    /** A standalone Penny page. No Craft user account is created. */
    case Hosted = 'hosted';

    /** A temporary, scoped control panel session. Pro only — it creates a real user. */
    case Cp = 'cp';

    public function label(): string
    {
        return match ($this) {
            self::Hosted => Craft::t('penny', 'Penny page'),
            self::Cp => Craft::t('penny', 'Control panel'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hosted => Craft::t('penny', 'A standalone page with only the fields you chose. No account is created.'),
            self::Cp => Craft::t('penny', 'The real control panel editor, behind a temporary account that is deleted afterwards.'),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
