<?php

namespace justinholtweb\penny\enums;

use Craft;

/**
 * Where an invite's recipient does the editing — or, for a view link, that there is no editing.
 */
enum Surface: string
{
    /** A standalone Penny page. No Craft user account is created. */
    case Hosted = 'hosted';

    /** A temporary, scoped control panel session. Pro only — it creates a real user. */
    case Cp = 'cp';

    /**
     * A one-time view of the element on the site itself, drafts and disabled entries included. Pro.
     * Nothing is edited; the link is spent the moment the recipient chooses to look.
     */
    case View = 'view';

    public function label(): string
    {
        return match ($this) {
            self::Hosted => Craft::t('penny', 'Penny page'),
            self::Cp => Craft::t('penny', 'Control panel'),
            self::View => Craft::t('penny', 'View once'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hosted => Craft::t('penny', 'A standalone page with only the fields you chose. No account is created.'),
            self::Cp => Craft::t('penny', 'The real control panel editor, behind a temporary account that is deleted afterwards.'),
            self::View => Craft::t('penny', 'Nothing to edit: one person sees the page on the site, once, drafts and disabled entries included.'),
        };
    }

    /** Whether the recipient edits anything at all. */
    public function isEditing(): bool
    {
        return $this !== self::View;
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
