<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Flarum\User\User;

/** Without Gatehouse there is no approval queue: the hold alone stands. */
class NoQueue implements NewMemberQueue
{
    public function hold(User $user): bool
    {
        return false;
    }
}
