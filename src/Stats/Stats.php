<?php

namespace ErnestDefoe\TrustLevels\Stats;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Every number the requirements need, for a chunk of members at once.
 *
 * 🚨 One aggregate query per kind of number for the whole chunk, never one
 * per member: the hourly run looks at hundreds of members and a per-member
 * loop would be thousands of queries. Grouped by user id, read into arrays.
 *
 * "Window" numbers cover the last 100 days (what Regular is judged on).
 */
class Stats
{
    public const WINDOW_DAYS = 100;

    public function __construct(private ConnectionInterface $db, private ExtensionManager $extensions, private SettingsRepositoryInterface $settings)
    {
    }

    public function likesOn(): bool
    {
        return $this->extensions->isEnabled('flarum-likes');
    }

    public function flagsOn(): bool
    {
        return $this->extensions->isEnabled('flarum-flags');
    }

    public function suspendOn(): bool
    {
        return $this->extensions->isEnabled('flarum-suspend');
    }

    /**
     * @param int[] $ids
     * @return array<int, array<string, int>>
     */
    public function forUsers(array $ids, int $suspendedMonths = 6): array
    {
        if (! $ids) {
            return [];
        }

        $since = Carbon::now()->subDays(self::WINDOW_DAYS);
        $sinceDay = $since->toDateString();
        $w = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);

        $out = array_fill_keys($ids, [
            'days_visited' => 0, 'posts_read' => 0, 'topics_entered' => 0, 'read_minutes' => 0,
            'days_visited_w' => 0, 'posts_read_w' => 0, 'topics_entered_w' => 0,
            'replied_topics' => 0, 'replied_topics_w' => 0,
            'likes_given' => 0, 'likes_given_w' => 0,
            'likes_received' => 0, 'likes_received_w' => 0, 'likes_received_users_w' => 0, 'likes_received_days_w' => 0,
            'flagged_posts' => 0, 'flagged_by' => 0, 'suspended' => 0,
        ]);

        // Base totals: days already folded out of trust_daily.
        foreach ($this->db->table('trust_stats')->whereIn('user_id', $ids)->get() as $row) {
            $o = &$out[(int) $row->user_id];
            $o['days_visited'] += (int) $row->base_days;
            $o['posts_read'] += (int) $row->base_posts_read;
            $o['topics_entered'] += (int) $row->base_topics_entered;
            $o['read_minutes'] += intdiv((int) $row->base_read_seconds, 60);
            $o['_base_seconds'] = (int) $row->base_read_seconds;
            unset($o);
        }

        $day = $w('trust_daily.day');
        $rows = $this->db->table('trust_daily')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as days, sum('.$w('trust_daily.posts_read').') as posts, sum('.$w('trust_daily.topics_entered').') as topics, sum('.$w('trust_daily.read_seconds').') as seconds')
            ->selectRaw("sum(case when $day >= ? then 1 else 0 end) as days_w", [$sinceDay])
            ->selectRaw("sum(case when $day >= ? then ".$w('trust_daily.posts_read').' else 0 end) as posts_w', [$sinceDay])
            ->selectRaw("sum(case when $day >= ? then ".$w('trust_daily.topics_entered').' else 0 end) as topics_w', [$sinceDay])
            ->get();

        foreach ($rows as $row) {
            $o = &$out[(int) $row->user_id];
            $o['days_visited'] += (int) $row->days;
            $o['posts_read'] += (int) $row->posts;
            $o['topics_entered'] += (int) $row->topics;
            $o['read_minutes'] = intdiv(($o['_base_seconds'] ?? 0) + (int) $row->seconds, 60);
            $o['days_visited_w'] = (int) $row->days_w;
            $o['posts_read_w'] = (int) $row->posts_w;
            $o['topics_entered_w'] = (int) $row->topics_w;
            unset($o);
        }

        $this->windowFromHistory($ids, $since, $out);

        // Discussions replied in: comments that aren't the first post.
        $disc = $w('posts.discussion_id');
        $rows = $this->visibleComments()
            ->whereIn('posts.user_id', $ids)
            ->where('posts.number', '>', 1)
            ->groupBy('posts.user_id')
            ->select('posts.user_id')
            ->selectRaw("count(distinct $disc) as total")
            ->selectRaw('count(distinct case when '.$w('posts.created_at')." >= ? then $disc end) as w", [$since])
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->user_id]['replied_topics'] = (int) $row->total;
            $out[(int) $row->user_id]['replied_topics_w'] = (int) $row->w;
        }

        if ($this->likesOn()) {
            $this->likes($ids, $since, $out);
        }

        if ($this->flagsOn()) {
            $this->flags($ids, $out);
        }

        $this->suspensions($ids, $suspendedMonths, $out);

        foreach ($out as &$o) {
            unset($o['_base_seconds']);
        }

        return $out;
    }

    /**
     * The 100-day window from what Flarum already records, for the days before
     * the extension tracked them: discussions read recently (core's read marks
     * carry a date) and days the member posted or liked. Whichever is higher
     * counts, so a forum's regulars can be Regulars from the first check. Only
     * while the install is less than 100 days old.
     */
    private function windowFromHistory(array $ids, Carbon $since, array &$out): void
    {
        // Once the extension has tracked the whole window itself, history adds nothing.
        $installed = $this->settings->get(Config::INSTALLED);

        if (! $installed || Carbon::parse($installed)->lte($since)) {
            return;
        }

        $w = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);

        foreach ($this->db->table('discussion_user')
            ->whereIn('user_id', $ids)
            ->where('last_read_at', '>=', $since)
            ->where('last_read_post_number', '>', 0)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as topics')
            ->get() as $row) {
            $out[(int) $row->user_id]['topics_entered_w'] = max($out[(int) $row->user_id]['topics_entered_w'], (int) $row->topics);
        }

        // Posts read: only posts written in the window, up to where the member's
        // read mark is. Jumping to the end of an old 2,000-post thread adds nothing.
        foreach ($this->db->table('discussion_user')
            ->join('posts', function ($join) {
                $join->on('posts.discussion_id', '=', 'discussion_user.discussion_id')
                    ->whereColumn('posts.number', '<=', 'discussion_user.last_read_post_number');
            })
            ->whereIn('discussion_user.user_id', $ids)
            ->where('discussion_user.last_read_at', '>=', $since)
            ->where('posts.created_at', '>=', $since)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->groupBy('discussion_user.user_id')
            ->select('discussion_user.user_id')
            ->selectRaw('count(*) as posts')
            ->get() as $row) {
            $out[(int) $row->user_id]['posts_read_w'] = max($out[(int) $row->user_id]['posts_read_w'], (int) $row->posts);
        }

        $days = $this->db->table('posts')
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $since)
            ->select('user_id')
            ->selectRaw('date('.$w('posts.created_at').') as d');

        if ($this->likesOn()) {
            $days->union(
                $this->db->table('post_likes')
                    ->whereIn('user_id', $ids)
                    ->where('created_at', '>=', $since)
                    ->select('user_id')
                    ->selectRaw('date('.$w('post_likes.created_at').') as d')
            );
        }

        foreach ($this->db->query()->fromSub($days, 'days')->groupBy('user_id')->select('user_id')->selectRaw('count(distinct d) as n')->get() as $row) {
            $out[(int) $row->user_id]['days_visited_w'] = max($out[(int) $row->user_id]['days_visited_w'], (int) $row->n);
        }
    }

    private function likes(array $ids, Carbon $since, array &$out): void
    {
        $w = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);
        $created = $w('post_likes.created_at');

        $rows = $this->db->table('post_likes')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when $created >= ? then 1 else 0 end) as w", [$since])
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->user_id]['likes_given'] = (int) $row->total;
            $out[(int) $row->user_id]['likes_given_w'] = (int) $row->w;
        }

        $liker = $w('post_likes.user_id');
        $rows = $this->db->table('post_likes')
            ->join('posts', 'posts.id', '=', 'post_likes.post_id')
            ->whereIn('posts.user_id', $ids)
            ->whereColumn('post_likes.user_id', '!=', 'posts.user_id')
            ->groupBy('posts.user_id')
            ->select('posts.user_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when $created >= ? then 1 else 0 end) as w", [$since])
            ->selectRaw("count(distinct case when $created >= ? then $liker end) as users_w", [$since])
            ->selectRaw("count(distinct case when $created >= ? then date($created) end) as days_w", [$since])
            ->get();

        foreach ($rows as $row) {
            $o = &$out[(int) $row->user_id];
            $o['likes_received'] = (int) $row->total;
            $o['likes_received_w'] = (int) $row->w;
            $o['likes_received_users_w'] = (int) $row->users_w;
            $o['likes_received_days_w'] = (int) $row->days_w;
            unset($o);
        }
    }

    /** Upheld flags in the last 6 months: posts, and the people who flagged them. */
    private function flags(array $ids, array &$out): void
    {
        $rows = $this->db->table('trust_events')
            ->whereIn('user_id', $ids)
            ->where('type', 'flag_upheld')
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->get(['user_id', 'data']);

        $by = [];

        foreach ($rows as $row) {
            $out[(int) $row->user_id]['flagged_posts']++;

            foreach ((array) (json_decode((string) $row->data, true)['flaggers'] ?? []) as $flagger) {
                $by[(int) $row->user_id][(int) $flagger] = true;
            }
        }

        foreach ($by as $userId => $flaggers) {
            $out[$userId]['flagged_by'] = count($flaggers);
        }
    }

    private function suspensions(array $ids, int $months, array &$out): void
    {
        foreach ($this->db->table('trust_events')
            ->whereIn('user_id', $ids)
            ->where('type', 'suspended')
            ->where('created_at', '>=', Carbon::now()->subMonths($months))
            ->distinct()
            ->pluck('user_id') as $id) {
            $out[(int) $id]['suspended'] = 1;
        }

        if ($this->suspendOn()) {
            foreach ($this->db->table('users')->whereIn('id', $ids)->where('suspended_until', '>', Carbon::now())->pluck('id') as $id) {
                $out[(int) $id]['suspended'] = 1;
            }
        }
    }

    /** Discussions and posts started in the window: what "25% of" is of. Two counts per run. */
    public function forumTotals(): array
    {
        $since = Carbon::now()->subDays(self::WINDOW_DAYS);

        return [
            'topics' => $this->db->table('discussions')
                ->where('created_at', '>=', $since)->whereNull('hidden_at')->where('is_private', false)->count(),
            'posts' => $this->visibleComments()->where('posts.created_at', '>=', $since)->count(),
        ];
    }

    private function visibleComments(): Builder
    {
        return $this->db->table('posts')
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where('posts.is_private', false);
    }
}
