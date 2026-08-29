<?php

namespace justinholtweb\penny\models;

use craft\base\Model;
use craft\elements\Asset;
use Craft;

/**
 * Per-invite dressing for the hosted page (Pro).
 *
 * Stored as JSON on the invite rather than as columns: it is presentation, it will grow, and none
 * of it is ever queried.
 */
class Branding extends Model
{
    public ?int $logoAssetId = null;
    public ?string $accentColor = null;
    public ?string $heading = null;
    public ?string $signOff = null;

    protected function defineRules(): array
    {
        return [
            [['logoAssetId'], 'integer'],
            [['accentColor'], 'match', 'pattern' => '/^#?[0-9A-Fa-f]{6}$/', 'skipOnEmpty' => true],
            [['heading', 'signOff'], 'string'],
        ];
    }

    public function getLogo(): ?Asset
    {
        if (!$this->logoAssetId) {
            return null;
        }

        $asset = Craft::$app->getElements()->getElementById($this->logoAssetId, Asset::class);

        return $asset instanceof Asset ? $asset : null;
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return array_filter([
            'logoAssetId' => $this->logoAssetId,
            'accentColor' => $this->accentColor,
            'heading' => $this->heading,
            'signOff' => $this->signOff,
        ], fn($value) => $value !== null && $value !== '');
    }
}
