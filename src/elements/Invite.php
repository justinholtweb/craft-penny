<?php

namespace justinholtweb\penny\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DateTime;
use justinholtweb\penny\elements\db\InviteQuery;
use justinholtweb\penny\enums\InviteStatus;
use justinholtweb\penny\enums\Surface;
use justinholtweb\penny\models\Branding;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use justinholtweb\penny\records\InviteRecord;

/**
 * A one-time content invite.
 *
 * An element rather than a plain record, so the control panel index, search, sources, sorting and
 * soft-delete all come free — and so that an invite can be related to and queried from Twig the
 * same way everything else in Craft is.
 */
class Invite extends Element
{
    /** @var string SHA-256 of the key. The key itself is never stored. */
    public string $keyHash = '';

    public ?DateTime $keyIssuedAt = null;
    public string $surface = 'hosted';
    public ?string $recipientName = null;
    public ?string $recipientEmail = null;
    public ?string $message = null;
    public ?DateTime $expiryDate = null;
    public ?DateTime $dateSent = null;
    public ?DateTime $dateFirstOpened = null;
    public ?DateTime $dateSubmitted = null;
    public ?DateTime $dateApplied = null;
    public ?DateTime $dateRevoked = null;
    public bool $requireReview = false;
    public ?int $authorId = null;
    public ?int $sessionUserId = null;
    public ?int $targetSiteId = null;

    /** @var string[] */
    private array $_notifyEmails = [];

    private ?Branding $_branding = null;

    /** @var Target[]|null */
    private ?array $_targets = null;

    /**
     * The plain key, in memory only, for the one request that minted it.
     *
     * This is how the "here is the link, copy it now" screen gets something to show. It is never
     * written anywhere, which is the whole point.
     */
    private ?string $_plainKey = null;

    public static function displayName(): string
    {
        return Craft::t('penny', 'Invite');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('penny', 'Invites');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('penny', 'invite');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('penny', 'invites');
    }

    public static function refHandle(): ?string
    {
        return 'invite';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    public static function find(): ElementQueryInterface
    {
        return new InviteQuery(static::class);
    }

    public static function statuses(): array
    {
        $statuses = [];

        foreach (InviteStatus::cases() as $case) {
            $statuses[$case->value] = ['label' => $case->label(), 'color' => $case->color()];
        }

        return $statuses;
    }

    /**
     * The lifecycle, derived rather than stored.
     *
     * Storing it would mean an invite that expired while nobody was looking still reads as
     * "not opened yet" until something happens to write the row — and "the link stopped working"
     * has to be true the moment it stops working, not the next time a cron runs.
     */
    public function getStatus(): ?string
    {
        return $this->getInviteStatus()->value;
    }

    public function getInviteStatus(): InviteStatus
    {
        if ($this->dateRevoked !== null) {
            return InviteStatus::Revoked;
        }

        if ($this->dateApplied !== null) {
            return $this->getSurface() === Surface::View ? InviteStatus::Viewed : InviteStatus::Submitted;
        }

        if ($this->dateSubmitted !== null) {
            return InviteStatus::AwaitingReview;
        }

        if ($this->getHasExpired()) {
            return InviteStatus::Expired;
        }

        return $this->dateFirstOpened !== null ? InviteStatus::Opened : InviteStatus::Pending;
    }

    public function getHasExpired(): bool
    {
        return $this->expiryDate !== null && $this->expiryDate->getTimestamp() < time();
    }

    /** Whether the link still opens. The single question every redemption asks. */
    public function getIsRedeemable(): bool
    {
        return $this->getInviteStatus()->isLive();
    }

    public function getSurface(): Surface
    {
        return Surface::tryFrom($this->surface) ?? Surface::Hosted;
    }

    // ------------------------------------------------------------------ related data

    public function getSite(): Site
    {
        return Craft::$app->getSites()->getSiteById($this->targetSiteId)
            ?? Craft::$app->getSites()->getPrimarySite();
    }

    public function getAuthor(): ?User
    {
        return $this->authorId ? Craft::$app->getUsers()->getUserById($this->authorId) : null;
    }

    /** @return Target[] */
    public function getTargets(): array
    {
        if ($this->_targets === null) {
            $this->_targets = $this->id ? Plugin::getInstance()->targets->getTargetsForInvite($this) : [];
        }

        return $this->_targets;
    }

    /** @param Target[]|array<array<string, mixed>>|null $targets */
    public function setTargets(?array $targets): void
    {
        if ($targets === null) {
            $this->_targets = null;
            return;
        }

        $models = [];
        $sortOrder = 0;

        foreach ($targets as $target) {
            if (!$target instanceof Target) {
                $target = new Target($target);
            }

            $target->inviteId = $this->id;
            $target->siteId = $this->targetSiteId;
            $target->sortOrder = $sortOrder++;
            $models[] = $target;
        }

        $this->_targets = $models;
    }

    /** @return string[] */
    public function getNotifyEmails(): array
    {
        return $this->_notifyEmails;
    }

    /**
     * @param string[]|string|null $value
     */
    public function setNotifyEmails(array|string|null $value): void
    {
        if (is_string($value)) {
            // This setter is fed from two directions: a textarea, one address per line, and the
            // JSON the column holds when an element query hydrates the invite. Treating the second
            // as the first turns `[]` into an address called "[]" and hands it to the mailer.
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : (preg_split('/[\r\n,]+/', $value) ?: []);
        }

        $this->_notifyEmails = array_values(array_filter(
            array_map('trim', array_filter($value ?? [], 'is_string')),
        ));
    }

    public function getBranding(): Branding
    {
        return $this->_branding ??= new Branding();
    }

    public function setBranding(Branding|array|string|null $value): void
    {
        if ($value instanceof Branding) {
            $this->_branding = $value;
            return;
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        $this->_branding = new Branding($value ?? []);
    }

    public function setPlainKey(?string $key): void
    {
        $this->_plainKey = $key;
    }

    /** The key, if this request is the one that minted it. Null every other time, on purpose. */
    public function getPlainKey(): ?string
    {
        return $this->_plainKey;
    }

    /** The link to hand over, if this request minted the key. */
    public function getInviteUrl(): ?string
    {
        return $this->_plainKey === null ? null : Plugin::getInstance()->keys->urlForKey($this->_plainKey, $this);
    }

    // ------------------------------------------------------------------ validation

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title'], 'required'];
        $rules[] = [['surface'], 'in', 'range' => array_map(fn(Surface $s) => $s->value, Surface::cases())];
        $rules[] = [['recipientEmail'], 'email'];
        $rules[] = [['targetSiteId'], 'required'];
        $rules[] = [['expiryDate'], 'validateExpiryDate'];
        $rules[] = [['targets'], 'validateTargets', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateExpiryDate(): void
    {
        // Only when it is being set or moved, so that opening an old invite's edit screen to read
        // its audit trail does not fail validation on a deadline that has already passed.
        if ($this->expiryDate === null || $this->dateSubmitted !== null || $this->dateRevoked !== null) {
            return;
        }

        if (!$this->id && $this->expiryDate->getTimestamp() < time()) {
            $this->addError('expiryDate', Craft::t('penny', 'The expiry date has already passed.'));
        }
    }

    /**
     * Every target has to be real, in scope, and allowed by the edition.
     */
    public function validateTargets(): void
    {
        $targets = $this->getTargets();

        if (!$targets) {
            $this->addError('targets', Craft::t('penny', 'Add at least one thing for the recipient to work on.'));
            return;
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() && count($targets) > 1) {
            $this->addError('targets', Craft::t('penny', 'Penny Lite allows one target per invite. Upgrade to Pro for more.'));
        }

        foreach ($targets as $i => $target) {
            $target->siteId = $this->targetSiteId;

            if (!$target->validate()) {
                foreach ($target->getFirstErrors() as $error) {
                    $this->addError('targets', Craft::t('penny', 'Target {n}: {error}', ['n' => $i + 1, 'error' => $error]));
                }

                continue;
            }

            $problem = $plugin->targets->checkEditionSupport($target);

            if ($problem !== null) {
                $this->addError('targets', $problem);
            }
        }

        if ($this->getSurface() === Surface::Cp && !$plugin->isPro()) {
            $this->addError('surface', Craft::t('penny', 'Control panel sessions are a Pro feature.'));
        }

        if ($this->getSurface() === Surface::View) {
            $this->validateViewTarget($targets);
        }
    }

    /**
     * A view link shows one page, and it has to be a page.
     *
     * One target, because the link lands on exactly one URL; an existing element, because there is
     * nothing to look at in an entry nobody has created yet; and one with a URL on the site, because
     * that is where it is shown.
     *
     * @param Target[] $targets
     */
    private function validateViewTarget(array $targets): void
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->addError('surface', Craft::t('penny', 'View links are a Pro feature.'));

            return;
        }

        if (count($targets) !== 1) {
            $this->addError('targets', Craft::t('penny', 'A view link shows one thing. Remove the others, or make an invite per page.'));

            return;
        }

        $target = $targets[0];

        if ($target->getIsNew()) {
            $this->addError('targets', Craft::t('penny', 'A view link needs something that already exists.'));

            return;
        }

        $element = Plugin::getInstance()->views->elementFor($this, $target);

        if ($element !== null && $element->getUrl() === null) {
            $this->addError('targets', Craft::t('penny', '“{title}” has no page on this site to show.', [
                'title' => $element->getUiLabel(),
            ]));
        }
    }

    // ------------------------------------------------------------------ persistence

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = $isNew ? new InviteRecord() : (InviteRecord::findOne($this->id) ?? new InviteRecord());
            $record->id = $this->id;
            $record->keyHash = $this->keyHash;
            $record->keyIssuedAt = Db::prepareDateForDb($this->keyIssuedAt);
            $record->surface = $this->surface;
            $record->recipientName = $this->recipientName;
            $record->recipientEmail = $this->recipientEmail;
            $record->message = $this->message;
            $record->expiryDate = Db::prepareDateForDb($this->expiryDate);
            $record->dateSent = Db::prepareDateForDb($this->dateSent);
            $record->dateFirstOpened = Db::prepareDateForDb($this->dateFirstOpened);
            $record->dateSubmitted = Db::prepareDateForDb($this->dateSubmitted);
            $record->dateApplied = Db::prepareDateForDb($this->dateApplied);
            $record->dateRevoked = Db::prepareDateForDb($this->dateRevoked);
            $record->requireReview = $this->requireReview;
            $record->notifyEmails = json_encode($this->getNotifyEmails());
            $record->branding = json_encode($this->getBranding()->toJson());
            $record->authorId = $this->authorId;
            $record->sessionUserId = $this->sessionUserId;
            $record->targetSiteId = $this->targetSiteId;
            $record->save(false);

            if ($this->_targets !== null) {
                Plugin::getInstance()->targets->saveTargetsForInvite($this, $this->_targets);
            }
        }

        parent::afterSave($isNew);
    }

    /**
     * A deleted invite must stop working immediately, not when the trash is emptied.
     *
     * Soft delete leaves the row — and therefore the key hash — perfectly intact, so without this
     * a "deleted" link keeps letting somebody in until garbage collection gets round to it.
     */
    public function afterDelete(): void
    {
        Plugin::getInstance()->invites->revoke($this, save: false);

        Db::update(InviteRecord::TABLE, [
            'dateRevoked' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $this->id], updateTimestamp: false);

        parent::afterDelete();
    }

    // ------------------------------------------------------------------ permissions

    public function canView(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(mixed $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canDuplicate(mixed $user): bool
    {
        return false;
    }

    public function canCreateDrafts(mixed $user): bool
    {
        return false;
    }

    public function getCpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("penny/invites/$this->id");
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('penny', 'Invite {id}', ['id' => $this->id]);
    }

    // ------------------------------------------------------------------ index

    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('penny', 'All invites'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            ['heading' => Craft::t('penny', 'Status')],
        ];

        foreach ([InviteStatus::Pending, InviteStatus::Opened, InviteStatus::AwaitingReview, InviteStatus::Submitted, InviteStatus::Viewed, InviteStatus::Expired, InviteStatus::Revoked] as $status) {
            $sources[] = [
                'key' => "status:$status->value",
                'label' => $status->label(),
                'criteria' => ['status' => $status->value],
                'defaultSort' => ['dateCreated', 'desc'],
            ];
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('penny', 'Invite')],
            'recipient' => ['label' => Craft::t('penny', 'Recipient')],
            'targetSummary' => ['label' => Craft::t('penny', 'Covers')],
            'surface' => ['label' => Craft::t('penny', 'Surface')],
            'expiryDate' => ['label' => Craft::t('penny', 'Expires')],
            'dateFirstOpened' => ['label' => Craft::t('penny', 'Opened')],
            'dateSubmitted' => ['label' => Craft::t('penny', 'Submitted')],
            'targetSite' => ['label' => Craft::t('penny', 'Site')],
            'author' => ['label' => Craft::t('penny', 'Created by')],
            'dateCreated' => ['label' => Craft::t('penny', 'Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['recipient', 'targetSummary', 'expiryDate', 'dateSubmitted'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('penny', 'Invite'),
            'recipientEmail' => Craft::t('penny', 'Recipient'),
            'expiryDate' => Craft::t('penny', 'Expires'),
            'dateFirstOpened' => Craft::t('penny', 'Opened'),
            'dateSubmitted' => Craft::t('penny', 'Submitted'),
            'dateCreated' => Craft::t('penny', 'Created'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'recipientName', 'recipientEmail', 'message'];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'recipient' => $this->recipientEmailHtml(),
            'targetSummary' => Html::encode($this->getTargetSummary()),
            'surface' => Html::encode($this->getSurface()->label()),
            'targetSite' => Html::encode($this->getSite()->name),
            'author' => $this->getAuthor() ? Cp::elementChipHtml($this->getAuthor()) : '',
            'expiryDate' => $this->expiryDateHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    private function recipientEmailHtml(): string
    {
        if (!$this->recipientEmail) {
            return $this->recipientName ? Html::encode($this->recipientName) : '';
        }

        $label = $this->recipientName ? "$this->recipientName <$this->recipientEmail>" : $this->recipientEmail;

        return Html::tag('span', Html::encode($label), ['title' => $this->recipientEmail]);
    }

    private function expiryDateHtml(): string
    {
        if ($this->expiryDate === null) {
            return Html::tag('span', Craft::t('penny', 'No expiry'), ['class' => 'light']);
        }

        $formatted = Craft::$app->getFormatter()->asDatetime($this->expiryDate, 'short');

        if ($this->getHasExpired()) {
            return Html::tag('span', Html::encode($formatted), ['class' => 'error']);
        }

        return Html::tag('span', Html::encode($formatted), [
            'title' => DateTimeHelper::humanDuration($this->expiryDate->getTimestamp() - time(), false),
        ]);
    }

    /** A one-line answer to "what does this let them do", for the index. */
    public function getTargetSummary(): string
    {
        $targets = $this->getTargets();

        if (!$targets) {
            return Craft::t('penny', 'Nothing');
        }

        $first = $targets[0]->getDisplayLabel();

        if (count($targets) === 1) {
            return $first;
        }

        return Craft::t('penny', '{first} and {n} more', ['first' => $first, 'n' => count($targets) - 1]);
    }
}
