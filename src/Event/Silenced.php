<?php

namespace ErnestDefoe\TrustLevels\Event;

use Flarum\User\User;

class Silenced
{
    public function __construct(public User $user, public ?int $postId = null)
    {
    }
}
