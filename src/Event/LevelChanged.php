<?php

namespace ErnestDefoe\TrustLevels\Event;

use Flarum\User\User;

/**
 * A member's level went up or down. `reason`: promoted, quiet, flags, staff, placed.
 */
class LevelChanged
{
    public function __construct(
        public User $user,
        public int $from,
        public int $to,
        public string $reason,
        public ?User $actor = null,
    ) {
    }
}
