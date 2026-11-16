<?php

namespace ErnestDefoe\TrustLevels\Listener;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Evaluator;
use ErnestDefoe\TrustLevels\Http\RememberActor;
use Flarum\Discussion\Event\UserRead;
use Flarum\Post\Event\Posted;
use Flarum\User\User;

/**
 * Members at New or Basic are checked right after they read or post, at most
 * once every 10 minutes each, so promotions up to Member don't wait for the
 * hourly run and work on hosts with no cron at all. The forum totals come
 * from the scheduled run's cache, so a check is the member's own numbers only.
 */
class QuickCheck
{
    public const EVERY_MINUTES = 10;

    public function __construct(private Evaluator $evaluator, private RememberActor $current)
    {
    }

    public function read(UserRead $event): void
    {
        $actor = $this->current->actor;

        if ($actor && (int) $actor->id === (int) $event->state->user_id) {
            $this->check($actor);
        }
    }

    public function posted(Posted $event): void
    {
        if ($event->actor && $event->actor->id === $event->post->user_id) {
            $this->check($event->actor);
        }
    }

    private function check(User $user): void
    {
        if ($user->isGuest() || (int) $user->getAttribute('trust_level') > 1 || $user->getAttribute('trust_locked')) {
            return;
        }

        $checked = $user->getAttribute('trust_checked_at');

        if ($checked && Carbon::parse($checked)->addMinutes(self::EVERY_MINUTES)->isFuture()) {
            return;
        }

        $this->evaluator->evaluateOne($user);
        $user->setRawAttributes(['trust_checked_at' => Carbon::now()] + $user->getAttributes(), true);
    }
}
