<?php

namespace ErnestDefoe\TrustLevels\Access;

use Flarum\Database\AbstractModel;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Trust level limits on abilities with no model (starting a discussion, uploading). Only ever FORCE_DENY or nothing: see Restrictions. */
class GlobalTrustPolicy extends AbstractPolicy
{
    public function __construct(private Restrictions $restrictions)
    {
    }

    public function can(User $actor, string $ability, string|AbstractModel|null $model = null): ?string
    {
        return $this->restrictions->denies($actor, $ability) ? $this->forceDeny() : null;
    }
}
