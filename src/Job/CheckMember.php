<?php

namespace ErnestDefoe\TrustLevels\Job;

use ErnestDefoe\TrustLevels\Evaluator;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/** One member's quick check (see Listener\QuickCheck), off the request that read or posted. */
class CheckMember extends AbstractJob
{
    public function __construct(private int $userId)
    {
    }

    public function handle(Evaluator $evaluator): void
    {
        $user = User::query()->find($this->userId);

        if ($user) {
            $evaluator->evaluateOne($user);
        }
    }
}
