<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Flarum\User\User;

/**
 * Where a brand-new member put on hold for spam goes back for approval:
 * Gatehouse's queue when it's enabled (GatehouseQueue), nowhere otherwise.
 */
interface NewMemberQueue
{
    /** @return bool whether the member is now waiting in a queue */
    public function hold(User $user): bool;
}
