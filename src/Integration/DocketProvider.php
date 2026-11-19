<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\TrustLevels\Access\Exempt;
use Flarum\Foundation\AbstractServiceProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;

/**
 * Docket's crowd moderation, weighed by level. Registered only while Docket is
 * enabled (Extend\Conditional); composer keeps Docket at 1.5 or later, where
 * reporter weights live in Crowd\Reporters. Bound in boot(), after Docket's own
 * provider (Docket is an optional dependency, so it loads first).
 */
class DocketProvider extends AbstractServiceProvider
{
    public function boot(): void
    {
        $this->container->bind(
            'ErnestDefoe\Docket\Crowd\Reporters',
            fn (Container $container) => $container->build(DocketReporters::class)
                ->levels($container->make(Exempt::class), $container->make(ConnectionInterface::class))
        );
    }
}
