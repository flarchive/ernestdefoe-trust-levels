<?php

namespace ErnestDefoe\TrustLevels\Access;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Who the limits never apply to: admins, holders of the bypass permission
 * (moderators by default) and the groups the admin lists. Reads only what the
 * actor already has loaded.
 */
class Exempt
{
    public function __construct(private Config $config, private SettingsRepositoryInterface $settings)
    {
    }

    public function is(User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        if ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-trust-levels.bypassLimits')) {
            return true;
        }

        $groups = $this->config->get('exempt_groups');

        return $groups && $actor->groups->whereIn('id', $groups)->isNotEmpty();
    }

    /**
     * The level limits and policies go by. A member who was here before the
     * install and hasn't been placed yet (the first check hasn't reached them)
     * counts as Basic, so enabling the extension never puts the whole forum
     * under New-member limits for an hour.
     */
    public function level(User $actor): int
    {
        return $actor->exists ? $this->effective((int) $actor->getAttribute('trust_level'), $actor->getAttribute('trust_level_at'), $actor->joined_at) : 0;
    }

    /** The same, from raw columns (for rows read without a model). */
    public function effective(int $level, mixed $levelAt, mixed $joinedAt): int
    {
        if ($level === 0 && ! $levelAt) {
            $installed = $this->settings->get(Config::INSTALLED);

            if (! $installed || ! $joinedAt || Carbon::parse($joinedAt)->lt(Carbon::parse($installed))) {
                return 1;
            }
        }

        return $level;
    }

    public static function silenced(User $actor): bool
    {
        return (bool) $actor->getAttribute('trust_silenced_at');
    }
}
