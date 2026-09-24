<?php

namespace justinholtweb\penny\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use justinholtweb\penny\db\Table;
use DateTime;
use craft\mail\Message;
use craft\web\View;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\enums\EventType;
use justinholtweb\penny\Plugin;
use Throwable;

/**
 * The emails: the link itself, a reminder, and a nudge to whoever is waiting for the content.
 *
 * Pro only. Lite copies the link out of the control panel and sends it however it likes, which is
 * how the WordPress plugin this one is modelled on works too.
 */
class Notifications extends Component
{
    /**
     * Sends the recipient their link.
     *
     * Takes the key as an argument rather than reading it off the invite, because there is nowhere
     * to read it from — only its hash is stored, so the one moment it can be sent is the request
     * that minted it.
     */
    public function sendInvite(Invite $invite, string $key): bool
    {
        if (!$this->canSend($invite)) {
            return false;
        }

        $sent = $this->send(
            $invite,
            $invite->recipientEmail,
            Craft::t('penny', '{site}: some content needs your attention', ['site' => $invite->getSite()->getName()]),
            'invite',
            ['key' => $key],
        );

        if ($sent) {
            $invite->dateSent = new DateTime();

            Db::update(Table::INVITES, [
                'dateSent' => Db::prepareDateForDb($invite->dateSent),
            ], ['id' => $invite->id], updateTimestamp: false);

            Plugin::getInstance()->audit->record($invite, EventType::Sent, $invite->recipientEmail);
        }

        return $sent;
    }

    public function sendReminder(Invite $invite, string $key): bool
    {
        if (!$this->canSend($invite)) {
            return false;
        }

        $sent = $this->send(
            $invite,
            $invite->recipientEmail,
            Craft::t('penny', 'A reminder: {site} is still waiting', ['site' => $invite->getSite()->getName()]),
            'reminder',
            ['key' => $key],
        );

        if ($sent) {
            Plugin::getInstance()->audit->record($invite, EventType::Reminded, $invite->recipientEmail);
        }

        return $sent;
    }

    /**
     * Tells whoever is waiting that the content has arrived.
     *
     * Never fatal and never blocking: a submission that succeeded but whose notification failed is
     * still a submission, and the recipient must not be shown an error for it.
     */
    public function notifySubmission(Invite $invite): void
    {
        if (!Plugin::getInstance()->isPro()) {
            return;
        }

        $recipients = $invite->getNotifyEmails() ?: Plugin::getInstance()->getSettings()->getNotifyEmails();

        if (!$recipients) {
            return;
        }

        $subject = $invite->requireReview
            ? Craft::t('penny', 'Content submitted for review: {name}', ['name' => $invite->getUiLabel()])
            : Craft::t('penny', 'Content submitted: {name}', ['name' => $invite->getUiLabel()]);

        foreach ($recipients as $recipient) {
            $this->send($invite, $recipient, $subject, 'submitted', [
                'cpUrl' => UrlHelper::cpUrl("penny/invites/$invite->id"),
            ]);
        }
    }

    // ------------------------------------------------------------------ plumbing

    private function canSend(Invite $invite): bool
    {
        return Plugin::getInstance()->isPro() && !empty($invite->recipientEmail);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function send(Invite $invite, ?string $to, string $subject, string $template, array $variables = []): bool
    {
        // Belt and braces: a mailer handed something that is not an address throws, and a
        // notification is never worth turning a successful submission into a 500.
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $body = $this->render($invite, $template, $variables);
        } catch (Throwable $e) {
            Craft::error("Could not render the '$template' email for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }

        $message = (new Message())
            ->setTo($to)
            ->setSubject($subject)
            ->setHtmlBody($body)
            ->setTextBody(trim(strip_tags(preg_replace('/<(br|\/p|\/div|\/h[1-6])[^>]*>/i', "\n", $body) ?? $body)));

        try {
            return Craft::$app->getMailer()->send($message);
        } catch (Throwable $e) {
            Craft::error("Could not send the '$template' email for invite $invite->id: " . $e->getMessage(), Plugin::LOG_CATEGORY);

            return false;
        }
    }

    /**
     * Renders an email, preferring a site template of the same name.
     *
     * Site templates first, because the wording of a client-facing email is exactly the thing every
     * agency wants to change, and asking them to fork the plugin for it would be absurd.
     */
    private function render(Invite $invite, string $template, array $variables): string
    {
        $view = Craft::$app->getView();
        $variables += [
            'invite' => $invite,
            'settings' => Plugin::getInstance()->getSettings(),
            'url' => isset($variables['key']) ? Plugin::getInstance()->keys->urlForKey($variables['key']) : null,
        ];

        $override = "penny/emails/$template";

        if ($view->doesTemplateExist($override, View::TEMPLATE_MODE_SITE)) {
            return $view->renderTemplate($override, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderTemplate("penny/_emails/$template", $variables, View::TEMPLATE_MODE_CP);
    }
}
