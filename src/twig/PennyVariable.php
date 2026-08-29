<?php

namespace justinholtweb\penny\twig;

use craft\base\ElementInterface;
use justinholtweb\penny\elements\db\InviteQuery;
use justinholtweb\penny\elements\Invite;
use justinholtweb\penny\Plugin;

/**
 * `craft.penny` — for templates that want to say something about an invite.
 *
 * Read-only on purpose. Everything that changes an invite's state goes through the control panel,
 * the console or the recipient's own submission, so there is nothing here a template could call
 * that would burn somebody's link by accident.
 */
class PennyVariable
{
    /** A query for invites, exactly like `craft.entries`. */
    public function invites(array $criteria = []): InviteQuery
    {
        $query = Invite::find();

        if ($criteria) {
            \Craft::configure($query, $criteria);
        }

        /** @var InviteQuery $query */
        return $query;
    }

    /** Live invites covering an element — "this page is out with the client right now". */
    public function invitesFor(ElementInterface $element): InviteQuery
    {
        return Invite::find()
            ->forElementId($element->getCanonicalId())
            ->live();
    }

    /** Whether anybody currently holds a live invite to edit this element. */
    public function isOut(ElementInterface $element): bool
    {
        return $this->invitesFor($element)->exists();
    }

    /** The invite behind the current control panel session, if this is one. */
    public function currentInvite(): ?Invite
    {
        return Plugin::getInstance()->sessions->currentInvite();
    }

    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }
}
