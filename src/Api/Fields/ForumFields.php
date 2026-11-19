<?php

namespace ErnestDefoe\TrustLevels\Api\Fields;

use ErnestDefoe\TrustLevels\Config;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The level names (the admin can rename them) and whether the actor is staff for levels. */
class ForumFields
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<Schema\Attribute> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('trustLevelNames')
                ->get(fn () => $this->config->all()['names']),
            Schema\Boolean::make('canManageTrustLevels')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-trust-levels.manage')),
        ];
    }
}
