<?php

namespace ErnestDefoe\TrustLevels\Tracking;

use Carbon\Carbon;
use Flarum\Discussion\Event\UserRead;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Days visited, discussions entered, posts read and time spent reading.
 *
 * 🚨 No request of our own and no timer in the browser. As a member scrolls,
 * Flarum already saves how far they've read, and raises `UserRead` when that
 * number goes up: the posts in between are what they just read. The time
 * since their previous read save today is time spent reading, capped so a tab
 * left open overnight counts two minutes, not eight hours.
 *
 * A visit is recorded when core updates `last_seen_at`, but only the first
 * time each day: core writes that column every few minutes, we write once.
 */
class Tracker
{
    /** Seconds one read save may add: more than this is a break, not reading. */
    public const MAX_STEP = 120;

    /** Jumping to the end of a long discussion is not reading all of it. */
    public const MAX_POSTS = 30;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function read(UserRead $event): void
    {
        $state = $event->state;
        $old = (int) ($state->getPrevious()['last_read_post_number'] ?? 0);
        $new = (int) $state->last_read_post_number;

        if ($new <= $old || ! $state->user_id) {
            return;
        }

        $now = Carbon::now();
        $day = $now->toDateString();
        $table = $this->db->table('trust_daily');
        $row = $table->where('user_id', $state->user_id)->where('day', $day)->first(['last_read_at']);

        $values = [
            'posts_read' => min($new - $old, self::MAX_POSTS),
            'topics_entered' => $old === 0 ? 1 : 0,
            'read_seconds' => 0,
            'last_read_at' => $now,
        ];

        if (! $row) {
            $this->db->table('trust_daily')->insertOrIgnore(['user_id' => $state->user_id, 'day' => $day] + $values);

            return;
        }

        if ($row->last_read_at) {
            $values['read_seconds'] = (int) min(self::MAX_STEP, max(0, $now->getTimestamp() - Carbon::parse($row->last_read_at)->getTimestamp()));
        }

        $this->db->table('trust_daily')->where('user_id', $state->user_id)->where('day', $day)->update([
            'posts_read' => $this->db->raw($this->db->getQueryGrammar()->wrap('posts_read').' + '.$values['posts_read']),
            'topics_entered' => $this->db->raw($this->db->getQueryGrammar()->wrap('topics_entered').' + '.$values['topics_entered']),
            'read_seconds' => $this->db->raw($this->db->getQueryGrammar()->wrap('read_seconds').' + '.$values['read_seconds']),
            'last_read_at' => $now,
        ]);
    }

    /** Called while a member is saved: their first sight today marks the day. */
    public function saving(User $user): void
    {
        if (! $user->exists || ! $user->isDirty('last_seen_at') || ! $user->last_seen_at) {
            return;
        }

        $before = $user->getOriginal('last_seen_at');
        $today = Carbon::now()->toDateString();

        if ($before && Carbon::parse($before)->toDateString() === $today) {
            return;
        }

        $this->db->table('trust_daily')->insertOrIgnore(['user_id' => $user->id, 'day' => $today]);
    }
}
