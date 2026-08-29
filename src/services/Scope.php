<?php

namespace justinholtweb\penny\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\fieldlayoutelements\BaseField;
use craft\fieldlayoutelements\CustomField;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\models\Target;

/**
 * What an invite may touch.
 *
 * This is the only authority on the subject. The hosted renderer, the hosted save, the control
 * panel session's permission set and the control panel save guard all ask it, so a surface can
 * never show a field the save would reject — and, the part that matters, a save can never write a
 * field the surface never showed, whatever arrives in the POST.
 */
class Scope extends Component
{
    /**
     * Native attributes Penny will not write, whatever a field layout says.
     *
     * These are the ones that hand out access rather than hold content. A user's field layout can
     * legitimately contain an email or username field — an admin editing their own profile needs
     * it — but an invite writing one is an account takeover with extra steps, so the answer here
     * is no rather than "no unless somebody ticks the box".
     *
     * @var string[]
     */
    private const NEVER_WRITABLE = [
        'admin',
        'permissions',
        'groups',
        'email',
        'unverifiedEmail',
        'username',
        'password',
        'newPassword',
        'currentPassword',
        'passwordResetRequired',
        'suspended',
        'pending',
        'locked',
    ];

    /**
     * The target this invite has for the given element, or null if it has none.
     *
     * Matches on the canonical id, so a draft and the entry it came from are the same thing as far
     * as scope is concerned — otherwise the recipient could edit their own draft but the guard
     * would not recognise the element they were editing.
     */
    public function targetFor(Invite $invite, ElementInterface $element): ?Target
    {
        $canonicalId = $element->getCanonicalId();
        $draftId = $element->draftId;

        foreach ($invite->getTargets() as $target) {
            if ($target->elementId !== null && $target->elementId === $canonicalId) {
                return $target;
            }

            if ($draftId !== null && $target->draftId === $draftId) {
                return $target;
            }

            if ($target->resultElementId !== null && $target->resultElementId === $canonicalId) {
                return $target;
            }
        }

        return null;
    }

    public function allows(Invite $invite, ElementInterface $element): bool
    {
        return $this->targetFor($invite, $element) !== null;
    }

    /**
     * The layout elements a target exposes, minus anything Penny refuses to write.
     *
     * Filtered here rather than only at save time so that the control panel never offers an admin
     * a checkbox for a field that would be silently dropped.
     *
     * @return BaseField[]
     */
    public function layoutElements(Target $target): array
    {
        return array_values(array_filter(
            $target->getScopedLayoutElements(),
            fn(BaseField $layoutElement) => $this->isWritable($layoutElement),
        ));
    }

    /**
     * Every layout element an admin may choose from, in tab order.
     *
     * @return array<string, BaseField[]> tab name => layout elements
     */
    public function selectableLayoutElements(Target $target): array
    {
        $layout = $target->getFieldLayout();

        if (!$layout) {
            return [];
        }

        $byTab = [];

        foreach ($layout->getTabs() as $tab) {
            $elements = [];

            foreach ($tab->getElements() as $layoutElement) {
                if ($layoutElement instanceof BaseField && $this->isWritable($layoutElement)) {
                    $elements[] = $layoutElement;
                }
            }

            if ($elements) {
                $byTab[$tab->name] = $elements;
            }
        }

        return $byTab;
    }

    /**
     * Custom field handles in scope.
     *
     * @return string[]
     */
    public function fieldHandles(Target $target): array
    {
        $handles = [];

        foreach ($this->layoutElements($target) as $layoutElement) {
            if ($layoutElement instanceof CustomField) {
                $handle = $layoutElement->attribute();

                if ($handle !== '') {
                    $handles[] = $handle;
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Native element attributes in scope — title, slug, alt, and their kind.
     *
     * @return string[]
     */
    public function nativeAttributes(Target $target): array
    {
        $attributes = [];

        foreach ($this->layoutElements($target) as $layoutElement) {
            if (!$layoutElement instanceof CustomField) {
                $attribute = $layoutElement->attribute();

                if ($attribute !== '' && !in_array($attribute, self::NEVER_WRITABLE, true)) {
                    $attributes[] = $attribute;
                }
            }
        }

        return array_values(array_unique($attributes));
    }

    /**
     * Reduces a posted set of custom field values to the ones in scope.
     *
     * Silently, not with an error: a hostile POST and a tab left open across a scope change look
     * identical from here, and only one of them deserves to be told anything.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public function filterFieldValues(Target $target, array $posted): array
    {
        return array_intersect_key($posted, array_flip($this->fieldHandles($target)));
    }

    /**
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    public function filterNativeValues(Target $target, array $posted): array
    {
        return array_intersect_key($posted, array_flip($this->nativeAttributes($target)));
    }

    /**
     * Whether Penny will render and write this layout element at all.
     */
    private function isWritable(BaseField $layoutElement): bool
    {
        if ($layoutElement instanceof CustomField) {
            return true;
        }

        return !in_array($layoutElement->attribute(), self::NEVER_WRITABLE, true);
    }

    /**
     * Whether an element type is one whose native attributes deserve the extra suspicion.
     *
     * Only used for the warning the control panel shows; the denylist above applies everywhere.
     */
    public function isSensitiveType(string $elementType): bool
    {
        return is_a($elementType, User::class, true);
    }

    /** @return string[] */
    public function neverWritableAttributes(): array
    {
        return self::NEVER_WRITABLE;
    }
}
