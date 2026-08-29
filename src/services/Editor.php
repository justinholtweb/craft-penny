<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\web\Request as WebRequest;
use craft\web\View;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\enums\TargetKind;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;
use Throwable;

/**
 * Turning a target into something a recipient can fill in, and a POST back into saved content.
 *
 * Where the element type keeps drafts, everything happens on a draft and the live element is not
 * touched until submission. That is what makes a half-finished session safe, and it is also what
 * makes review mode free: reviewing is just not applying the draft yet.
 */
class Editor extends Component
{
    /** The form field namespace for a target. Rendering and reading must agree on it exactly. */
    public function namespaceFor(Target $target): string
    {
        return "targets[$target->id]";
    }

    /** The same namespace in the dot form `getBodyParam()` wants. */
    public function paramPathFor(Target $target): string
    {
        return "targets.$target->id";
    }

    /**
     * The element the recipient actually edits.
     *
     * Creates the draft (or the brand-new entry) the first time, and finds it again on every visit
     * after that, so closing the tab loses nothing.
     */
    public function workingElement(Invite $invite, Target $target): ?ElementInterface
    {
        if ($target->getKind() === TargetKind::New && $target->draftId === null) {
            return $this->startNewElement($invite, $target);
        }

        if ($target->draftId !== null) {
            $draft = $this->findDraft($invite, $target);

            if ($draft !== null) {
                return $draft;
            }

            // The draft was deleted out from under us — applied by an admin, or garbage collected.
            // Falling through to the canonical element is better than showing an error page for a
            // state the recipient did not cause and cannot fix.
            $target->draftId = null;
        }

        $canonical = $target->getElement();

        if ($canonical === null) {
            return null;
        }

        if (!$target->getSupportsDrafts()) {
            // Globals, users and assets keep no drafts in Craft 5, so these are edited live — and
            // written only once, at submission. See `save()`.
            return $canonical;
        }

        return $this->startDraft($invite, $target, $canonical);
    }

    /**
     * Renders the fields this target exposes, and only those.
     *
     * Real Craft field HTML, produced by the layout elements themselves, so Matrix, CKEditor,
     * asset uploads and relation fields behave exactly as they do in the control panel rather than
     * being re-implemented one field type at a time.
     */
    public function renderFields(Target $target, ElementInterface $element): string
    {
        $view = Craft::$app->getView();
        $layoutElements = Plugin::getInstance()->scope->layoutElements($target);

        if (!$layoutElements) {
            return Html::tag('p', Craft::t('penny', 'There is nothing to fill in here.'), ['class' => 'penny-empty']);
        }

        return $view->namespaceInputs(function() use ($layoutElements, $element) {
            $html = '';

            foreach ($layoutElements as $layoutElement) {
                $html .= $layoutElement->formHtml($element, false) ?? '';
            }

            return $html;
        }, $this->namespaceFor($target));
    }

    /**
     * Writes a POST onto the working element.
     *
     * Nothing here trusts what was posted. The body is reduced to the scope *in place* — in place
     * rather than copied to a namespace of our own, because uploaded files arrive keyed on the
     * same namespace and rewriting it would detach every one of them from its field.
     */
    public function save(Invite $invite, Target $target, ElementInterface $element): bool
    {
        $request = Craft::$app->getRequest();

        if (!$request instanceof WebRequest) {
            return false;
        }

        $scope = Plugin::getInstance()->scope;
        $body = $request->getBodyParams();
        $posted = $body['targets'][$target->id] ?? [];

        if (!is_array($posted)) {
            return true;
        }

        $body['targets'][$target->id]['fields'] = $scope->filterFieldValues(
            $target,
            is_array($posted['fields'] ?? null) ? $posted['fields'] : [],
        );

        $request->setBodyParams($body);

        $element->setFieldValuesFromRequest($this->paramPathFor($target) . '.fields');

        foreach ($scope->filterNativeValues($target, $posted) as $attribute => $value) {
            $element->$attribute = $value;
        }

        if ($element instanceof Entry && $element->title && !$element->slug) {
            $element->slug = ElementHelper::generateSlug($element->title, null, $element->getSite()->language);
        }

        if (!Craft::$app->getElements()->saveElement($element)) {
            return false;
        }

        Plugin::getInstance()->targets->markSaved(
            $target,
            draftId: $element->draftId,
            resultElementId: $element->getCanonicalId(),
        );

        return true;
    }

    /**
     * Hands the work in.
     *
     * Applies each draft unless the invite is held for review, and reports whether everything
     * landed — a partial submission is still a submission, because the alternative is a recipient
     * stuck on a page they cannot get past for a reason that is not theirs.
     */
    public function submit(Invite $invite): bool
    {
        $allApplied = true;
        $plugin = Plugin::getInstance();

        foreach ($invite->getTargets() as $target) {
            $element = $this->workingElement($invite, $target);

            if ($element === null) {
                $allApplied = false;
                continue;
            }

            if ($invite->requireReview || $element->draftId === null) {
                // Held for review, or an element type with no drafts — either way there is nothing
                // to apply, because what is on screen is already what is stored.
                $plugin->targets->markSaved($target, resultElementId: $element->getCanonicalId());
                continue;
            }

            try {
                $applied = Craft::$app->getDrafts()->applyDraft($element);
                $plugin->targets->markSaved($target, resultElementId: $applied->id);
            } catch (Throwable $e) {
                Craft::error("Could not apply the draft for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);
                $allApplied = false;
            }
        }

        $plugin->invites->markSubmitted($invite, applied: !$invite->requireReview && $allApplied);

        return $allApplied;
    }

    // ------------------------------------------------------------------ drafts

    private function startDraft(Invite $invite, Target $target, ElementInterface $canonical): ?ElementInterface
    {
        try {
            $draft = Craft::$app->getDrafts()->createDraft(
                $canonical,
                $invite->authorId,
                Craft::t('penny', 'Penny invite: {name}', ['name' => $invite->getUiLabel()]),
                Craft::t('penny', 'Started from a one-time invite.'),
            );
        } catch (Throwable $e) {
            Craft::error("Could not start a draft for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }

        Plugin::getInstance()->targets->markSaved($target, draftId: $draft->draftId);

        return $draft;
    }

    /**
     * Creates the entry a `new` target promises, as a draft nobody can see yet.
     *
     * `markAsSaved: false` is what keeps it out of the way — Craft treats it as an unsaved draft,
     * so an invite that is never opened leaves nothing behind for an admin to tidy up.
     */
    private function startNewElement(Invite $invite, Target $target): ?ElementInterface
    {
        if ($target->elementType !== Entry::class || !$target->entryTypeId) {
            return null;
        }

        $entryType = Craft::$app->getEntries()->getEntryTypeById($target->entryTypeId);
        $section = $target->sectionId ? Craft::$app->getEntries()->getSectionById($target->sectionId) : null;

        if (!$entryType || !$section) {
            return null;
        }

        $entry = Craft::createObject(Entry::class);
        $entry->siteId = $target->siteId ?? $invite->targetSiteId;
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->slug = ElementHelper::tempSlug();

        if ($section->maxAuthors !== 0 && $invite->authorId) {
            $entry->setAuthorIds([$invite->authorId]);
        }

        if ($target->parentId) {
            $entry->setParentId($target->parentId);
        }

        // Off until somebody decides otherwise. An invite that creates content the recipient never
        // finished must not put a blank entry on the site.
        $entry->enabled = false;
        $entry->setEnabledForSite(true);

        $entry->setScenario(Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $invite->authorId, markAsSaved: false)) {
            Craft::error("Could not start the new entry for invite $invite->id.", Plugin::LOG_CATEGORY);

            return null;
        }

        Plugin::getInstance()->targets->markSaved(
            $target,
            draftId: $entry->draftId,
            resultElementId: $entry->getCanonicalId(),
        );

        Plugin::getInstance()->audit->record($invite, EventType::Saved, Craft::t('penny', 'Started a new {type}', [
            'type' => $entryType->name,
        ]));

        return $entry;
    }

    private function findDraft(Invite $invite, Target $target): ?ElementInterface
    {
        /** @var class-string<ElementInterface> $type */
        $type = $target->elementType;

        if (!class_exists($type)) {
            return null;
        }

        $draft = $type::find()
            ->draftId($target->draftId)
            ->siteId($target->siteId ?? $invite->targetSiteId)
            ->status(null)
            ->one();

        return $draft instanceof ElementInterface ? $draft : null;
    }

    /** Whether this target keeps its work in a draft, or writes straight to the element. */
    public function isProgressive(Target $target): bool
    {
        return $target->getSupportsDrafts();
    }

    /**
     * The view has to be in control panel template mode for field HTML to resolve its templates.
     *
     * Field inputs render control panel templates by name, and a site request's view looks for
     * those under the site's template root, where they do not exist.
     */
    public function withCpTemplateMode(callable $callback): mixed
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            return $callback();
        } finally {
            $view->setTemplateMode($mode);
        }
    }
}
