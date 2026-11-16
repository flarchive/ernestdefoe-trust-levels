<?php

namespace ErnestDefoe\TrustLevels;

use ErnestDefoe\TrustLevels\Http\RememberActor;
use ErnestDefoe\TrustLevels\Tracking\Tracker;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Config::class);
        $this->container->singleton(RememberActor::class);
    }

    public function boot(ExtensionManager $extensions): void
    {
        // A visit: core's last-seen write, the first one each day.
        User::saving(fn (User $user) => $this->container->make(Tracker::class)->saving($user));

        // Docket's crowd moderation, weighed by level. Bound after Docket's own
        // provider (Docket is an optional dependency, so it boots first).
        if ($extensions->isEnabled('ernestdefoe-docket') && class_exists('ErnestDefoe\Docket\Crowd\Crowd')) {
            $this->container->singleton('ErnestDefoe\Docket\Crowd\Crowd', Integration\DocketCrowd::class);
        }
    }
}
