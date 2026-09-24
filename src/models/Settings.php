<?php

namespace justinholtweb\penny\models;

use Craft;
use craft\base\Model;

/**
 * Penny's settings.
 *
 * Nothing here is `required`. A fresh install must be able to save any setting before every other
 * one has been filled in, and a `required` rule fails `savePluginSettings()` wholesale.
 */
class Settings extends Model
{
    /**
     * The first segment of the pretty invite URL — `/<prefix>/<key>`.
     *
     * That URL only validates the key and redirects; the editing page itself is served from a
     * control panel route, because CP field inputs need CP asset bundles to work.
     */
    public string $inviteUriPrefix = 'penny';

    /** How many days an invite lasts by default. */
    public int $defaultExpiryDays = 14;

    /** Which surface new invites start on. */
    public string $defaultSurface = 'hosted';

    /**
     * The user whose identity the hosted page runs under, in memory, so that field inputs which
     * read the current user (element selects, asset uploads) work.
     *
     * Empty means the invite's author. What the recipient may do is bounded by the invite's scope,
     * not by this identity — it exists so the field HTML can render at all.
     */
    public ?int $actingUserId = null;

    /** How long a Pro control-panel session lasts before it has to be re-opened, in seconds. */
    public int $cpSessionDuration = 3600;

    /**
     * Whether to delete the ephemeral control-panel user once its invite is finished.
     *
     * Off keeps it, suspended, which is occasionally what an audit wants. On is the default,
     * because these are real Craft users and Solo licences count them.
     */
    public bool $deleteSessionUsers = true;

    /** Whether Penny emails the link itself when an invite is created (Pro). */
    public bool $sendOnCreate = true;

    /** Days before expiry to send a reminder, when `penny/invites/remind` runs. 0 disables. */
    public int $remindDaysBefore = 3;

    /** Who hears about submissions, when the invite does not say. One per line. */
    public string $notifyEmails = '';

    /** How long the audit trail is kept, in days. 0 keeps it forever. */
    public int $keepEventsDays = 365;

    /** How many failed key lookups one IP may make per hour before it is turned away. */
    public int $maxAttemptsPerHour = 20;

    /** Default accent colour for the hosted page (Pro branding overrides it per invite). */
    public string $accentColor = '#ED8228';

    /** Optional logo shown at the top of the hosted page. */
    public ?int $logoAssetId = null;

    /** Heading shown above the hosted form when an invite does not set its own. */
    public string $hostedHeading = '';

    protected function defineRules(): array
    {
        return [
            [['inviteUriPrefix', 'defaultSurface'], 'string'],
            [['inviteUriPrefix'], 'match', 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9\-_\/]*$/', 'message' => Craft::t('penny', 'Use letters, numbers, hyphens, underscores and slashes.')],
            [['defaultSurface'], 'in', 'range' => ['hosted', 'cp']],
            [['defaultExpiryDays', 'cpSessionDuration', 'remindDaysBefore', 'keepEventsDays', 'maxAttemptsPerHour'], 'integer', 'min' => 0],
            [['actingUserId', 'logoAssetId'], 'integer'],
            [['deleteSessionUsers', 'sendOnCreate'], 'boolean'],
            [['accentColor'], 'match', 'pattern' => '/^#?[0-9A-Fa-f]{6}$/', 'message' => Craft::t('penny', 'Enter a six-digit hex colour.')],
            [['notifyEmails', 'hostedHeading'], 'safe'],
        ];
    }

    /**
     * The accent colour, always with its hash.
     *
     * Craft's colour input posts `ED8228`, not `#ED8228`, so a template that interpolates the raw
     * value straight into CSS produces a rule the browser drops.
     */
    public function getAccentColor(): string
    {
        $value = trim($this->accentColor);

        if ($value === '') {
            return '#ED8228';
        }

        return str_starts_with($value, '#') ? $value : '#' . $value;
    }

    /** @return string[] */
    public function getNotifyEmails(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', $this->notifyEmails) ?: [],
        )));
    }
}
