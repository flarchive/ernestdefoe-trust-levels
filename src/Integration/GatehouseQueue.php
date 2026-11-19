<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Ernestdefoe\Gatehouse\Listener\HoldNewMember;
use Flarum\User\User;

/**
 * Gatehouse's own hold, so its queue, pending count and moderator alerts all
 * follow (however Gatehouse stores it). The spammer isn't mailed about it.
 * Bound only while Gatehouse is enabled (GatehouseProvider).
 */
class GatehouseQueue implements NewMemberQueue
{
    public function __construct(private HoldNewMember $gatehouse)
    {
    }

    public function hold(User $user): bool
    {
        $this->gatehouse->hold($user, false);

        return true;
    }
}
