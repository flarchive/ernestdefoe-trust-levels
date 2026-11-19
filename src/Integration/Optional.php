<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\TrustLevels\Event\LevelChanged;
use Flarum\Extend;
use Flarum\Extend\ExtenderInterface;

/**
 * Extenders for Buzz and Onramp, named by string: those extensions are only
 * installed on some forums, and extend.php is loaded on all of them.
 */
final class Optional
{
    /** @return ExtenderInterface[] */
    public static function buzz(): array
    {
        $buzz = 'Ernestdefoe\Buzz\Extend\Buzz';

        return [
            (new $buzz())->type('trust-levels.reached', 'fas fa-shield-halved', 'ernestdefoe-trust-levels.forum.buzz.reached', [
                'name' => 'ernestdefoe-trust-levels.admin.buzz_name',
            ]),
            (new Extend\Event())->listen(LevelChanged::class, [BuzzPromotions::class, 'handle']),
        ];
    }

    /** @return ExtenderInterface[] */
    public static function onramp(): array
    {
        $types = 'ErnestDefoe\Onramp\Extend\StepTypes';

        return [(new $types())->add(OnrampStep::class)];
    }
}
