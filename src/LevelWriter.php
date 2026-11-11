<?php

namespace ErnestDefoe\TrustLevels;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Event\LevelChanged;
use ErnestDefoe\TrustLevels\Notification\LevelDroppedBlueprint;
use ErnestDefoe\TrustLevels\Notification\LevelReachedBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * The one place a level changes: the member's row, the history, the alert,
 * the event. Written with a plain UPDATE so no other extension's user-saving
 * hooks run for what is our own bookkeeping.
 */
class LevelWriter
{
    public function __construct(
        private ConnectionInterface $db,
        private NotificationSyncer $notifications,
        private Dispatcher $events,
    ) {
    }

    /**
     * @param string $reason promoted | quiet | flags | staff | placed
     */
    public function set(User $user, int $to, string $reason, ?User $actor = null): void
    {
        $from = (int) $user->getAttribute('trust_level');
        $now = Carbon::now();

        if ($from === $to && $user->getAttribute('trust_level_at')) {
            return;
        }

        // Only from the level we read: when the hourly run and a member's own
        // quick check promote them at the same moment, the second one changes
        // no row and stops here (no second history row, alert or Buzz post).
        $changed = $this->db->table('users')->where('id', $user->id)->where('trust_level', $from)
            ->update(['trust_level' => $to, 'trust_level_at' => $now]);

        if (! $changed) {
            return;
        }

        $user->setRawAttributes(['trust_level' => $to, 'trust_level_at' => $now] + $user->getAttributes(), true);

        if ($from === $to) {
            return;
        }

        $this->log($user->id, $to > $from ? 'promoted' : 'demoted', $from, $to, $actor?->id, null, ['reason' => $reason]);

        if ($reason !== 'placed') {
            if ($to > $from) {
                $this->notifications->sync(new LevelReachedBlueprint($user, $to), [$user]);
            } else {
                $this->notifications->sync(new LevelDroppedBlueprint($user, $to, $reason), [$user]);
            }
        }

        $this->events->dispatch(new LevelChanged($user, $from, $to, $reason, $actor));
    }

    public function log(int $userId, string $type, ?int $from = null, ?int $to = null, ?int $actorId = null, ?int $postId = null, ?array $data = null): void
    {
        $this->db->table('trust_events')->insert([
            'user_id' => $userId,
            'type' => $type,
            'from_level' => $from,
            'to_level' => $to,
            'actor_id' => $actorId,
            'post_id' => $postId,
            'data' => $data ? json_encode($data) : null,
            'created_at' => Carbon::now(),
        ]);
    }
}
