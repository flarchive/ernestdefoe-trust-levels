<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * Docket's crowd moderation, weighed by level. Registered only while Docket is
 * enabled (Extend\Conditional), and bound in boot(), after Docket's own
 * provider has bound its Crowd (Docket is an optional dependency, so it loads first).
 */
class DocketProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        $this->container->singleton('ErnestDefoe\Docket\Crowd\Crowd', DocketCrowd::class);
    }
}
