<?php

namespace justinholtweb\penny\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\fieldlayoutelements\BaseField;
use craft\models\FieldLayout;
use DateTime;
use justinholtweb\penny\enums\TargetKind;

/**
 * One thing an invite lets its recipient work on.
 *
 * A target is either an element that already exists, or an instruction to create one. Either way
 * it carries the *scope* — which field layout elements the recipient may see and write — and that
 * list is the only authority on the subject. See services\Scope.
 */
class Target extends Model
{
    public ?int $id = null;
    public ?int $inviteId = null;
    public string $kind = TargetKind::Element->value;

    /** @var class-string<ElementInterface> */
    public string $elementType = Entry::class;

    public ?int $elementId = null;
    public ?int $entryTypeId = null;
    public ?int $sectionId = null;
    public ?int $parentId = null;
    public ?int $draftId = null;
    public ?int $resultElementId = null;

    /**
     * Field layout element UIDs the recipient may edit, or null for the whole layout.
     *
     * UIDs, not field handles, because that is the one identifier that covers native fields (title,
     * slug) and custom fields alike, is what Craft's own conditional-field logic keys on, and
     * survives a field being renamed.
     *
     * @var string[]|null
     */
    public ?array $layoutElementUids = null;

    public ?string $label = null;
    public ?string $instructions = null;
    public int $sortOrder = 0;
    public ?DateTime $dateSaved = null;
    public ?string $uid = null;

    /** Set by the owning invite so a target can reach the site it is edited in. */
    public ?int $siteId = null;

    private ?ElementInterface $_element = null;
    private bool $_elementLoaded = false;

    public function attributeLabels(): array
    {
        return [
            'label' => Craft::t('penny', 'Label'),
            'instructions' => Craft::t('penny', 'Instructions'),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['elementType', 'kind'], 'required'],
            [['kind'], 'in', 'range' => array_map(fn(TargetKind $k) => $k->value, TargetKind::cases())],
            [['elementId', 'entryTypeId', 'sectionId', 'parentId', 'draftId', 'resultElementId', 'sortOrder'], 'integer'],
            [['label', 'instructions'], 'string'],
            [['layoutElementUids'], 'each', 'rule' => ['string']],
            [['elementId'], 'required', 'when' => fn(self $t) => $t->getKind() === TargetKind::Element,
                'message' => Craft::t('penny', 'Choose something for the recipient to edit.')],
            [['entryTypeId'], 'required', 'when' => fn(self $t) => $t->getKind() === TargetKind::New,
                'message' => Craft::t('penny', 'Choose what the recipient should create.')],
        ];
    }

    public function getKind(): TargetKind
    {
        return TargetKind::tryFrom($this->kind) ?? TargetKind::Element;
    }

    public function getIsNew(): bool
    {
        return $this->getKind() === TargetKind::New;
    }

    /**
     * The canonical element this target points at, or null.
     *
     * Null for a `new` target that has not been started, and also for an element that has since
     * been deleted — which is a state the CP has to be able to show rather than fatal on.
     */
    public function getElement(): ?ElementInterface
    {
        if ($this->_elementLoaded) {
            return $this->_element;
        }

        $this->_elementLoaded = true;

        if (!$this->elementId || !class_exists($this->elementType)) {
            return $this->_element = null;
        }

        $this->_element = Craft::$app->getElements()->getElementById(
            $this->elementId,
            $this->elementType,
            $this->siteId,
        );

        return $this->_element;
    }

    /** Whether the element type keeps drafts, and so whether work in progress can be held safely. */
    public function getSupportsDrafts(): bool
    {
        /** @var class-string<ElementInterface> $type */
        $type = $this->elementType;

        return class_exists($type) && $type::hasDrafts();
    }

    public function getElementTypeLabel(): string
    {
        /** @var class-string<ElementInterface> $type */
        $type = $this->elementType;

        return class_exists($type) ? $type::displayName() : $this->elementType;
    }

    /**
     * What the recipient is asked to do with this target.
     */
    public function getDisplayLabel(): string
    {
        if ($this->label !== null && trim($this->label) !== '') {
            return $this->label;
        }

        if ($this->getIsNew()) {
            return Craft::t('penny', 'New {type}', ['type' => strtolower($this->getElementTypeLabel())]);
        }

        return (string)($this->getElement()?->getUiLabel() ?? Craft::t('penny', 'Missing {type}', [
            'type' => strtolower($this->getElementTypeLabel()),
        ]));
    }

    /**
     * The field layout the scope is expressed against.
     *
     * For a `new` entry target that is the entry type's layout; for anything else it is the
     * element's own, which is the only one that can be trusted to match what will be saved.
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if ($this->getIsNew()) {
            if (!$this->entryTypeId) {
                return null;
            }

            return Craft::$app->getEntries()->getEntryTypeById($this->entryTypeId)?->getFieldLayout();
        }

        return $this->getElement()?->getFieldLayout();
    }

    /**
     * The layout elements this target actually exposes, in layout order.
     *
     * Only `BaseField` descendants: a layout may also hold headings, tips, templates and line
     * breaks, and none of those are things a recipient can fill in or a save can write.
     *
     * @return BaseField[]
     */
    public function getScopedLayoutElements(): array
    {
        $layout = $this->getFieldLayout();

        if (!$layout) {
            return [];
        }

        $scoped = [];

        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $layoutElement) {
                if (!$layoutElement instanceof BaseField) {
                    continue;
                }

                if ($this->layoutElementUids !== null && !in_array($layoutElement->uid, $this->layoutElementUids, true)) {
                    continue;
                }

                $scoped[] = $layoutElement;
            }
        }

        return $scoped;
    }

    /** Whether this target exposes its element's whole field layout. */
    public function getIsWholeLayout(): bool
    {
        return $this->layoutElementUids === null;
    }
}
