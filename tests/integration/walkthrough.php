<?php
/**
 * Sets up a Penny fixture and prints a live invite link, so the whole thing can be walked through
 * over HTTP the way a recipient would.
 *
 *     ddev exec php /var/www/craft-penny/tests/integration/walkthrough.php setup
 *     ddev exec php /var/www/craft-penny/tests/integration/walkthrough.php check
 *     ddev exec php /var/www/craft-penny/tests/integration/walkthrough.php teardown
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\elements\User;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\models\Target;
use justinholtweb\penny\Plugin;

const HANDLE_SUFFIX = 'PennyWalk';

$command = $argv[1] ?? 'setup';
$plugin = Plugin::getInstance();
$entriesService = Craft::$app->getEntries();
$fieldsService = Craft::$app->getFields();

function findSection(): ?Section
{
    return Craft::$app->getEntries()->getSectionByHandle('sec' . HANDLE_SUFFIX);
}

if ($command === 'teardown') {
    foreach (Invite::find()->status(null)->all() as $invite) {
        Craft::$app->getElements()->deleteElement($invite, true);
    }

    if (($section = findSection()) !== null) {
        $entriesService->deleteSection($section);
    }

    // Entry types outlive their sections in Craft 5, so a teardown that only drops the section
    // leaves one behind and the next setup collides with it.
    if (($entryType = $entriesService->getEntryTypeByHandle('type' . HANDLE_SUFFIX)) !== null) {
        $entriesService->deleteEntryType($entryType);
    }

    foreach (['body' . HANDLE_SUFFIX, 'note' . HANDLE_SUFFIX] as $handle) {
        if (($field = $fieldsService->getFieldByHandle($handle)) !== null) {
            $fieldsService->deleteField($field);
        }
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    echo "Torn down.\n";
    exit(0);
}

if ($command === 'check') {
    $entry = Entry::find()->section('sec' . HANDLE_SUFFIX)->status(null)->one();

    if ($entry === null) {
        echo "No fixture entry.\n";
        exit(1);
    }

    echo "entry #{$entry->id}\n";
    echo "  title: {$entry->title}\n";
    echo "  body:  " . var_export($entry->getFieldValue('body' . HANDLE_SUFFIX), true) . "\n";
    echo "  note:  " . var_export($entry->getFieldValue('note' . HANDLE_SUFFIX), true) . "\n";

    foreach (Invite::find()->status(null)->all() as $invite) {
        echo "invite #{$invite->id} {$invite->getStatus()} targets=" . count($invite->getTargets()) . "\n";
    }

    exit(0);
}

// ---------------------------------------------------------------- setup

if (findSection() === null) {
    // Reuse rather than recreate: Craft field handles are global and a previous teardown may not
    // have got as far as dropping them.
    $body = $fieldsService->getFieldByHandle('body' . HANDLE_SUFFIX) ?? new PlainText();
    $body->name = 'Walk Body';
    $body->handle = 'body' . HANDLE_SUFFIX;
    $body->multiline = true;

    if (!$fieldsService->saveField($body)) {
        echo "Could not save the body field: " . json_encode($body->getErrors()) . "\n";
        exit(1);
    }

    $note = $fieldsService->getFieldByHandle('note' . HANDLE_SUFFIX) ?? new PlainText();
    $note->name = 'Walk Note';
    $note->handle = 'note' . HANDLE_SUFFIX;

    if (!$fieldsService->saveField($note)) {
        echo "Could not save the note field: " . json_encode($note->getErrors()) . "\n";
        exit(1);
    }

    $layout = new FieldLayout(['type' => Entry::class]);
    $tab = new FieldLayoutTab(['name' => 'Content']);
    $tab->setLayout($layout);
    $tab->setElements([new EntryTitleField(), new CustomField($body), new CustomField($note)]);
    $layout->setTabs([$tab]);

    $entryType = $entriesService->getEntryTypeByHandle('type' . HANDLE_SUFFIX) ?? new EntryType();
    $entryType->name = 'Walk Type';
    $entryType->handle = 'type' . HANDLE_SUFFIX;
    $entryType->setFieldLayout($layout);

    if (!$entriesService->saveEntryType($entryType)) {
        echo "Could not save the entry type: " . json_encode($entryType->getErrors()) . "\n";
        exit(1);
    }

    $section = new Section();
    $section->name = 'Walk Section';
    $section->handle = 'sec' . HANDLE_SUFFIX;
    $section->type = Section::TYPE_CHANNEL;
    $section->setSiteSettings([new Section_SiteSettings([
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'hasUrls' => false,
    ])]);
    $section->setEntryTypes([$entryType]);

    if (!$entriesService->saveSection($section)) {
        echo "Could not save the section: " . json_encode($section->getErrors()) . "\n";
        exit(1);
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

$section = findSection();
$entryType = $section->getEntryTypes()[0];

$entry = Entry::find()->section($section->handle)->status(null)->one();

if ($entry === null) {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->title = 'Before the invite';
    $entry->setFieldValue('body' . HANDLE_SUFFIX, 'original body');
    $entry->setFieldValue('note' . HANDLE_SUFFIX, 'admin only note');
    Craft::$app->getElements()->saveElement($entry);
}

$uids = [];

foreach ($entry->getFieldLayout()->getTabs() as $tab) {
    foreach ($tab->getElements() as $element) {
        if ($element instanceof EntryTitleField) {
            $uids['title'] = $element->uid;
        } elseif ($element instanceof CustomField && $element->attribute() === 'body' . HANDLE_SUFFIX) {
            $uids['body'] = $element->uid;
        }
    }
}

$admin = User::find()->admin()->status(User::STATUS_ACTIVE)->orderBy(['id' => SORT_ASC])->one();

$invite = $plugin->invites->create([
    'title' => 'Walkthrough invite',
    'authorId' => $admin?->id,
    'recipientName' => 'Sam',
    'message' => "Could you update the title and the body? Thanks.",
]);

$invite->setTargets([new Target([
    'kind' => 'element',
    'elementType' => Entry::class,
    'elementId' => $entry->id,
    'label' => 'Your page',
    'instructions' => 'Just the title and the body, please.',
    'layoutElementUids' => [$uids['title'], $uids['body']],
])]);

if (!$plugin->invites->save($invite)) {
    echo "Could not save the invite: " . json_encode($invite->getErrors()) . "\n";
    exit(1);
}

echo "entry: {$entry->id}\n";
echo "invite: {$invite->id}\n";
echo "key: {$invite->getPlainKey()}\n";
echo "url: {$plugin->keys->urlForKey($invite->getPlainKey())}\n";
echo "hosted: {$plugin->keys->hostedUrlForKey($invite->getPlainKey())}\n";
echo "out-of-scope field handle: note" . HANDLE_SUFFIX . "\n";
