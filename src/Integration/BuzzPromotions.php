<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Event\LevelChanged;
use ErnestDefoe\TrustLevels\Names;

/**
 * Buzz: "Ana is now a Regular". Off unless the admin turns it on (levels are
 * otherwise only seen by the member and staff). Only promotions earned by
 * taking part: never a drop, a placement or a level set by staff.
 */
class BuzzPromotions
{
    public function __construct(private Config $config, private Names $names)
    {
    }

    public function handle(LevelChanged $event): void
    {
        if (! $this->config->get('buzz') || $event->reason !== 'promoted' || $event->to <= $event->from) {
            return;
        }

        resolve('Ernestdefoe\Buzz\Recorder')->record('trust-levels.reached', $event->user, ['level' => $this->names->name($event->to)], [
            'subject_id' => $event->user->id,
        ]);
    }
}
