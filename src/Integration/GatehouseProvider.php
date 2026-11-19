<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Flarum\Foundation\AbstractServiceProvider;

/** Registered only while Gatehouse is enabled (Extend\Conditional in extend.php). */
class GatehouseProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(NewMemberQueue::class, GatehouseQueue::class);
    }
}
