<?php

namespace justinholtweb\penny;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\events\AuthorizationCheckEvent;
use craft\events\DefineHtmlEvent;
use craft\helpers\Html;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Json;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\models\Settings;
use justinholtweb\penny\services\Access;
use justinholtweb\penny\services\Audit;
use justinholtweb\penny\services\Editor;
use justinholtweb\penny\services\Invites;
use justinholtweb\penny\services\Keys;
use justinholtweb\penny\services\Notifications;
use justinholtweb\penny\services\Scope;
use justinholtweb\penny\services\Sessions;
use justinholtweb\penny\services\Targets;
use justinholtweb\penny\services\Views;
use justinholtweb\penny\twig\PennyVariable;
use justinholtweb\penny\web\assets\cp\CpAsset;
use yii\base\Event;

/**
 * Penny — one-time content invites for Craft.
 *
 * @property-read Access $access
 * @property-read Audit $audit
 * @property-read Editor $editor
 * @property-read Invites $invites
 * @property-read Keys $keys
 * @property-read Notifications $notifications
 * @property-read Scope $scope
 * @property-read Sessions $sessions
 * @property-read Targets $targets
 * @property-read Views $views
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'penny:viewInvites';
    public const PERMISSION_MANAGE = 'penny:manageInvites';
    public const PERMISSION_DELETE = 'penny:deleteInvites';
    public const PERMISSION_REVIEW = 'penny:reviewSubmissions';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'penny';

    /**
     * The control panel path the hosted editing page is served from.
     *
     * Not `penny`: Craft requires a login for any non-action control panel URL whose first segment
     * is a plugin handle, before the controller gets a say. See `registerRoutes()`.
     */
    public const HOSTED_SEGMENT = 'penny-invite';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'access' => Access::class,
                'audit' => Audit::class,
                'editor' => Editor::class,
                'invites' => Invites::class,
                'keys' => Keys::class,
                'notifications' => Notifications::class,
                'scope' => Scope::class,
                'sessions' => Sessions::class,
                'targets' => Targets::class,
                'views' => Views::class,
            ],
        ];
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();
        $this->registerSessionGuard();
        $this->registerShareOnce();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('penny', 'Penny');

        $item['subnav'] = [
            'invites' => [
                'label' => Craft::t('penny', 'Invites'),
                'url' => 'penny/invites',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('penny', 'Settings'),
                'url' => 'settings/plugins/penny',
            ];
        }

        return $item;
    }

    /**
     * Disposes of every temporary control panel account before the tables that know about them go.
     *
     * The install migration drops the invites table, and after that nothing records which users
     * Penny made — they would be left behind as ordinary accounts with no password and no owner.
     */
    protected function beforeUninstall(): void
    {
        foreach (Invite::find()->status(null)->sessionUserId(['not', null])->all() as $invite) {
            $this->invites->endSession($invite);
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('penny/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    // ------------------------------------------------------------------ registration

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Invite::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'penny' => 'penny/invites/index',
                'penny/invites' => 'penny/invites/index',
                'penny/invites/new' => 'penny/invites/edit',
                'penny/invites/<inviteId:\d+>' => 'penny/invites/edit',
                'penny/invites/<inviteId:\d+>/link' => 'penny/invites/link',

                // The hosted editing surface. A control panel route, because control panel field
                // inputs need control panel asset bundles and a control panel request to render at
                // all — but served with none of the control panel's chrome.
                //
                // Deliberately *not* under `penny/…`. Craft demands a login for any non-action
                // control panel URL whose first segment is a plugin handle, and it does so in
                // `Application::handleRequest()` — before the controller is reached and therefore
                // before `$allowAnonymous` is ever consulted. A first segment that is nobody's
                // handle skips that gate and lets the controller answer for itself.
                // Where a control panel recipient lands after handing in, already signed out. Before
                // the key rule, which would otherwise read "done" as a (malformed) key.
                self::HOSTED_SEGMENT . '/done' => 'penny/hosted/done',
                self::HOSTED_SEGMENT . '/<key:[A-Za-z0-9_\-]+>' => 'penny/hosted/index',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $prefix = trim($this->getSettings()->inviteUriPrefix, '/');

            if ($prefix === '') {
                return;
            }

            // The pretty link that goes in the email. It checks the key and forwards; the editing
            // itself happens on the control panel route above.
            $event->rules["$prefix/<key:[A-Za-z0-9_\-]+>"] = 'penny/gateway/open';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('penny', 'Penny'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('penny', 'View invites'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('penny', 'Create and edit invites'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('penny', 'Delete and revoke invites'),
                            ],
                            self::PERMISSION_REVIEW => [
                                'label' => Craft::t('penny', 'Approve submitted content'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('penny', PennyVariable::class);
        });
    }

    /**
     * Expiry needs no sweep — an invite's status is derived, so a lapsed link stops working the
     * moment it lapses. This is for the things expiry cannot do on its own: disposing of an
     * ephemeral control panel account whose invite ran out while nobody was looking, and keeping
     * the audit trail from growing forever.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->invites->sweepExpired();
            $this->audit->prune();
        });
    }

    /**
     * "Share once", beside Save on anything with a page on the site.
     *
     * Craft's own Share button makes a link that anyone can open until its token runs out. This one
     * makes a view invite: one person, once, in one browser. It lives on the edit screen because
     * that is where somebody is when they think "I need the client to look at this".
     */
    private function registerShareOnce(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event) {
            /** @var ElementInterface $element */
            $element = $event->sender;
            $user = Craft::$app->getUser();

            if (
                !$this->isPro()
                || $element instanceof Invite
                || !$element::hasUris()
                || $element->getIsRevision()
                || $this->sessions->currentInvite() !== null
                || !$user->checkPermission(self::PERMISSION_MANAGE)
                || $element->getUrl() === null
            ) {
                return;
            }

            // A provisional draft is somebody's unsaved edits, not something to show a client —
            // share what is saved. A real draft is shared as that draft.
            $draftId = $element->getIsDraft() && !$element->isProvisionalDraft ? $element->draftId : null;

            Craft::$app->getView()->registerAssetBundle(CpAsset::class);

            $event->html .= Html::button(Craft::t('penny', 'Share once'), [
                'type' => 'button',
                'class' => ['btn', 'penny-share-once'],
                'title' => Craft::t('penny', 'A link that opens once, for one person, in one browser.'),
                'data' => [
                    'element-type' => $element::class,
                    'canonical-id' => $element->getCanonicalId(),
                    'site-id' => $element->siteId,
                    'draft-id' => $draftId,
                ],
            ]);
        });
    }

    /**
     * The guard on the Pro control panel surface.
     *
     * Permissions get the recipient onto the right screen; they cannot express "this entry and no
     * other", because Craft's permissions are section-shaped and Penny's scope is element-shaped.
     * This closes that gap on the way into the database, which is the only place it can be closed
     * honestly — a hidden field or a disabled input is a suggestion, not a boundary.
     */
    private function registerSessionGuard(): void
    {
        // Craft's own authorization hook. Denying here is what makes the control panel *behave* —
        // the editor refuses the element outright rather than accepting an edit and discarding it.
        foreach ([
            Elements::EVENT_AUTHORIZE_VIEW,
            Elements::EVENT_AUTHORIZE_SAVE,
            Elements::EVENT_AUTHORIZE_DELETE,
            Elements::EVENT_AUTHORIZE_DUPLICATE,
            Elements::EVENT_AUTHORIZE_CREATE_DRAFTS,
        ] as $eventName) {
            Event::on(Elements::class, $eventName, function(AuthorizationCheckEvent $event) use ($eventName) {
                if ($this->sessions->currentInvite() === null || $event->element === null) {
                    return;
                }

                // An invite is for filling things in. Deleting or duplicating the element — or the
                // draft holding the recipient's own work — is never part of it.
                if (in_array($eventName, [Elements::EVENT_AUTHORIZE_DELETE, Elements::EVENT_AUTHORIZE_DUPLICATE], true)) {
                    $event->authorized = false;

                    return;
                }

                if (!$this->sessions->currentSessionAllows($event->element)) {
                    $event->authorized = false;

                    return;
                }

                // Refusing the live copy is also what takes *Apply draft* off the screen.
                if ($eventName === Elements::EVENT_AUTHORIZE_SAVE && $this->sessions->refusesCanonicalSave($event->element)) {
                    $event->authorized = false;
                }
            });
        }

        // And the boundary itself, on the way into the database.
        //
        // Deliberately not `Elements::EVENT_BEFORE_SAVE_ELEMENT`: Craft fires that event and never
        // reads it back, so `$event->isValid = false` there is a guard that silently does nothing.
        // `Element::EVENT_BEFORE_SAVE` is the one whose answer `saveElement()` actually honours.
        Event::on(Element::class, Element::EVENT_BEFORE_SAVE, function(ModelEvent $event) {
            $invite = $this->sessions->currentInvite();

            if ($invite === null) {
                return;
            }

            /** @var ElementInterface $element */
            $element = $event->sender;

            if (!$this->sessions->currentSessionAllows($element)) {
                Craft::warning(
                    sprintf('Blocked a save of %s #%s from invite %d, which is not in its scope.', $element::class, $element->id ?? 'new', $invite->id),
                    self::LOG_CATEGORY,
                );

                $event->isValid = false;

                return;
            }

            if ($this->sessions->refusesCanonicalSave($element)) {
                Craft::warning(
                    sprintf('Blocked publishing %s #%s from invite %d before it was handed in.', $element::class, $element->id ?? 'new', $invite->id),
                    self::LOG_CATEGORY,
                );

                $event->isValid = false;

                return;
            }

            // The element is in scope; its individual fields may not be.
            $this->sessions->enforceFieldScope($element);
        });

        // Strips the control panel down to the job at hand. Cosmetic — the guard above is what
        // makes it safe — but a recipient handed the full nav will click it, and then be told no.
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            if (!Craft::$app->getRequest()->getIsCpRequest() || $this->sessions->currentInvite() === null) {
                return;
            }

            $invite = $this->sessions->currentInvite();
            $view = Craft::$app->getView();
            $view->registerAssetBundle(CpAsset::class);

            // The class goes on from here rather than from the bundle's own script, because the
            // control panel's body tag is Craft's and there is nowhere to hang an attribute.
            $view->registerJs("document.body.classList.add('penny-invited-session');", View::POS_END);

            $css = $this->sessions->hiddenFieldCss($invite);

            if ($css !== null) {
                $view->registerCss($css);
            }

            // Without this the recipient has no way to say they are finished, and the account —
            // and the live link — would sit there until somebody remembered to revoke it.
            $view->registerJs(sprintf(
                'window.PennyHandIn && window.PennyHandIn(%s);',
                Json::encode($this->sessions->handInBarConfig($invite)),
            ), View::POS_END);
        });
    }
}
