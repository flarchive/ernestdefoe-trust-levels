<?php

namespace ErnestDefoe\TrustLevels\Job;

use ErnestDefoe\TrustLevels\Evaluator;
use Flarum\Queue\AbstractJob;

/**
 * "Run now" on the dashboard: the same check the hourly schedule runs, off the
 * web request (a big forum can take minutes). The run's own lock keeps it to
 * one at a time; the dashboard shows the result when lastRun moves.
 */
class RunCheck extends AbstractJob
{
    public function handle(Evaluator $evaluator): void
    {
        $evaluator->run();
    }
}
