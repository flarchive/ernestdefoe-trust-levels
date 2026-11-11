<?php

namespace ErnestDefoe\TrustLevels;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Stats\Stats;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Decides each member's level from their numbers, a chunk at a time.
 *
 * - Levels 1 to 3 are reached automatically, each needing the ones below it.
 *   Leader (4) is given by staff only.
 * - Regular is the only level that drops for going quiet: when the last 100
 *   days fall more than 10% short, after a grace period from promotion.
 * - After a drop for upheld flags, the member can't climb back above the level
 *   it left them at while those flags are within 6 months.
 * - A locked member never moves; a silenced one never climbs; a member Sleuth
 *   links to a banned account stays at New until staff clear it.
 * - Members who were here before the extension are placed once, from what
 *   history shows, and never below Basic.
 *
 * Queries per chunk are constant: the numbers (see Stats), the forum totals
 * (once per run), holds and caps (one each), one upsert and one update.
 */
class Evaluator
{
    public const CHUNK = 200;

    /** @var array{topics: int, posts: int}|null */
    private ?array $totals = null;

    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Stats $stats,
        private Requirements $requirements,
        private LevelWriter $writer,
        private ExtensionManager $extensions,
        private SettingsRepositoryInterface $settings,
        private Cache $cache,
    ) {
    }

    /**
     * Members seen since the last run, members not placed yet, and (with
     * $all) every Regular, whose 100-day window moves even if they don't.
     *
     * One run at a time, whichever schedule (or Run now) started it: $wait
     * seconds for one already going (the nightly pass waits, so pruning and
     * Regular drops aren't skipped behind a long hourly run).
     *
     * @return int|null members checked, or null when another run held the lock
     */
    public function run(bool $all = false, int $wait = 0): ?int
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return $this->runLocked($all);
        }

        $lock = $store->lock('ernestdefoe-trust-levels.run', 3600);

        try {
            if (! ($wait > 0 ? $lock->block($wait) : $lock->get())) {
                return null;
            }
        } catch (LockTimeoutException) {
            return null;
        }

        try {
            return $this->runLocked($all);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(bool $all): int
    {
        $this->settings->delete(Config::TOTALS);

        $lastRun = $this->settings->get(Config::LAST_RUN);
        $since = $lastRun ? Carbon::parse($lastRun)->subMinutes(10) : Carbon::now()->subDay();
        $started = Carbon::now();
        $checked = 0;

        User::query()
            ->where(fn ($q) => $q->where('last_seen_at', '>=', $since)
                ->orWhereNull('trust_level_at')
                ->when($all, fn ($q) => $q->orWhere('trust_level', 3)))
            ->select('users.*')
            ->chunkById(self::CHUNK, function ($users) use (&$checked) {
                $this->evaluate($users->all());
                $checked += $users->count();
            });

        $this->settings->set(Config::LAST_RUN, $started->toIso8601String());
        $this->settings->set(Config::LAST_RUN.'_count', (string) $checked);
        $this->settings->set(Config::LAST_RUN.'_seconds', (string) round(Carbon::now()->diffInMilliseconds($started, true) / 1000, 2));

        if ($all) {
            $this->prune();
        }

        return $checked;
    }

    /**
     * @param User[] $users
     */
    public function evaluate(array $users): void
    {
        if (! $users) {
            return;
        }

        $ids = array_map(fn (User $u) => (int) $u->id, $users);
        // History is estimated only for members who were here before the install;
        // someone who signs up later starts from what is tracked.
        $unplaced = array_values(array_filter($ids, function ($id) use ($users) {
            $user = $this->find($users, $id);

            return ! $user->getAttribute('trust_level_at') && $this->joinedBeforeInstall($user);
        }));

        if ($unplaced) {
            $this->estimate($unplaced);
        }

        $stats = $this->stats->forUsers($ids, $this->config->req(3)['suspended_months'] ?: 6);
        $totals = $this->totals ??= $this->totals();
        $held = $this->sleuthHolds($ids);
        $caps = $this->flagCaps($ids);
        $graceDays = (int) $this->config->get('grace_days');
        $rows = [];
        $now = Carbon::now();

        foreach ($users as $user) {
            $id = (int) $user->id;
            $s = $stats[$id];
            $current = (int) $user->getAttribute('trust_level');
            $placing = ! $user->getAttribute('trust_level_at');

            $earned = 0;
            $next = null;

            for ($level = 1; $level <= Config::AUTO_MAX; $level++) {
                $req = $this->requirements->rows($level, $s, $totals);

                if (! Requirements::met($req)) {
                    $next = ['level' => $level, 'rows' => $req];
                    break;
                }

                $earned = $level;
            }

            $keep = null;

            if (! $user->getAttribute('trust_locked')) {
                $target = $earned;

                if ($user->getAttribute('trust_silenced_at')) {
                    $target = min($target, $current);
                }

                if (isset($held[$id])) {
                    $target = 0;
                }

                if (isset($caps[$id])) {
                    $target = min($target, max($caps[$id], $current));
                }

                if ($placing) {
                    // Here before the install: placed from history, never below Basic.
                    // Joined since: New, like anyone signing up today.
                    $floor = ! isset($held[$id]) && $this->joinedBeforeInstall($user) ? 1 : 0;
                    $this->writer->set($user, $floor ? max($current, $target, $floor) : $current, 'placed');
                } elseif ($target > $current && $current < Config::AUTO_MAX + 1) {
                    $this->writer->set($user, $target, 'promoted');
                } elseif ($current === 3) {
                    $keep = $this->requirements->rows(3, $s, $totals, 0.9);
                    $at = $user->getAttribute('trust_level_at');

                    if (! Requirements::met($keep) && $at && Carbon::parse($at)->addDays($graceDays)->isPast()) {
                        $this->writer->set($user, 2, 'quiet');
                    }
                }
            }

            $level = (int) $user->getAttribute('trust_level');

            // Placed or set above what the numbers earn: show the level above where they are.
            if (! $next || $next['level'] <= $level) {
                $next = $level < Config::AUTO_MAX ? ['level' => $level + 1, 'rows' => $this->requirements->rows($level + 1, $s, $totals)] : null;
            }

            if ($level === 3) {
                $keep ??= $this->requirements->rows(3, $s, $totals, 0.9);
            }

            $rows[] = [
                'user_id' => $id,
                'data' => json_encode(['next' => $next, 'keep' => $level === 3 ? $keep : null, 'stats' => $s]),
                'next_level' => $next['level'] ?? null,
                'next_percent' => $next ? Requirements::percent($next['rows']) : 0,
                'computed_at' => $now,
            ];
        }

        $this->db->table('trust_stats')->upsert($rows, ['user_id'], ['data', 'next_level', 'next_percent', 'computed_at']);
        $this->db->table('users')->whereIn('id', $ids)->update(['trust_checked_at' => $now]);
    }

    /**
     * Discussions and posts started in the last 100 days, what Regular's "25%
     * of" is of. Two counts over the whole forum: done by the scheduled run and
     * kept for two hours, so a member's quick check never repeats them.
     *
     * @return array{topics: int, posts: int}
     */
    private function totals(): array
    {
        $cached = json_decode((string) $this->settings->get(Config::TOTALS), true);

        if (is_array($cached) && isset($cached['at'], $cached['topics'], $cached['posts']) && Carbon::parse($cached['at'])->gt(Carbon::now()->subHours(2))) {
            return ['topics' => (int) $cached['topics'], 'posts' => (int) $cached['posts']];
        }

        $totals = $this->stats->forumTotals();
        $this->settings->set(Config::TOTALS, json_encode($totals + ['at' => Carbon::now()->toIso8601String()]));

        return $totals;
    }

    /** One member, now (a promotion right after reading or posting). */
    public function evaluateOne(User $user): void
    {
        $this->evaluate([$user]);
    }

    private function joinedBeforeInstall(User $user): bool
    {
        $installed = $this->settings->get(Config::INSTALLED);

        return ! $installed || ! $user->joined_at || $user->joined_at->lt(Carbon::parse($installed));
    }

    private function find(array $users, int $id): User
    {
        foreach ($users as $user) {
            if ((int) $user->id === $id) {
                return $user;
            }
        }

        throw new \LogicException("User $id not in chunk");
    }

    /**
     * Members from before the install: what history can tell. Discussions
     * entered and posts read come from core's read marks; days visited from
     * the days they posted or liked; reading time from posts read, 15 s each.
     * Stored as the base totals, once.
     *
     * @param int[] $ids
     */
    private function estimate(array $ids): void
    {
        $have = $this->db->table('trust_stats')->whereIn('user_id', $ids)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff($ids, $have));

        if (! $ids) {
            return;
        }

        $w = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);
        $base = array_fill_keys($ids, ['base_days' => 0, 'base_posts_read' => 0, 'base_topics_entered' => 0, 'base_read_seconds' => 0]);

        foreach ($this->db->table('discussion_user')
            ->whereIn('user_id', $ids)
            ->where('last_read_post_number', '>', 0)
            ->groupBy('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as topics, sum('.$w('discussion_user.last_read_post_number').') as posts')
            ->get() as $row) {
            $base[(int) $row->user_id]['base_topics_entered'] = (int) $row->topics;
            $base[(int) $row->user_id]['base_posts_read'] = (int) $row->posts;
            $base[(int) $row->user_id]['base_read_seconds'] = (int) $row->posts * 15;
        }

        $days = $this->db->table('posts')
            ->whereIn('user_id', $ids)
            ->select('user_id')
            ->selectRaw('date('.$w('posts.created_at').') as d');

        if ($this->stats->likesOn()) {
            $days->union(
                $this->db->table('post_likes')
                    ->whereIn('user_id', $ids)
                    ->whereNotNull('created_at')
                    ->select('user_id')
                    ->selectRaw('date('.$w('post_likes.created_at').') as d')
            );
        }

        foreach ($this->db->query()->fromSub($days, 'days')->groupBy('user_id')->select('user_id')->selectRaw('count(distinct d) as n')->get() as $row) {
            $base[(int) $row->user_id]['base_days'] = (int) $row->n;
        }

        $this->db->table('trust_stats')->insertOrIgnore(array_map(fn ($id, $b) => ['user_id' => $id] + $b, array_keys($base), $base));
    }

    /** @return array<int, true> members Sleuth links to a banned account, not yet reviewed */
    private function sleuthHolds(array $ids): array
    {
        if (! $this->extensions->isEnabled('ernestdefoe-sleuth')) {
            return [];
        }

        return array_fill_keys($this->db->table('sleuth_signups')
            ->whereIn('user_id', $ids)
            ->where('reason', 'evader')
            ->whereNull('reviewed_at')
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /** @return array<int, int> the highest level each member may climb back to after a drop for flags */
    private function flagCaps(array $ids): array
    {
        $caps = [];

        foreach ($this->db->table('trust_events')
            ->whereIn('user_id', $ids)
            ->where('type', 'demoted')
            ->where('created_at', '>=', Carbon::now()->subMonths(6))
            ->where('data', 'like', '%"reason":"flags"%')
            ->get(['user_id', 'to_level']) as $row) {
            $id = (int) $row->user_id;
            $caps[$id] = min($caps[$id] ?? PHP_INT_MAX, (int) $row->to_level);
        }

        return $caps;
    }

    /**
     * Days older than the window are folded into each member's base totals,
     * then deleted, so trust_daily stays at about 100 rows per active member.
     */
    public function prune(): void
    {
        $cutoff = Carbon::now()->subDays(Stats::WINDOW_DAYS + 1)->toDateString();
        $w = fn (string $col) => $this->db->getQueryGrammar()->wrap($col);

        while (true) {
            $ids = $this->db->table('trust_daily')->where('day', '<', $cutoff)->distinct()->orderBy('user_id')->limit(500)->pluck('user_id')->all();

            if (! $ids) {
                return;
            }

            $this->db->transaction(function () use ($ids, $cutoff, $w) {
                $old = $this->db->table('trust_daily')
                    ->whereIn('user_id', $ids)
                    ->where('day', '<', $cutoff)
                    ->groupBy('user_id')
                    ->select('user_id')
                    ->selectRaw('count(*) as days, sum('.$w('trust_daily.posts_read').') as posts, sum('.$w('trust_daily.topics_entered').') as topics, sum('.$w('trust_daily.read_seconds').') as seconds')
                    ->get()
                    ->keyBy('user_id');

                $base = $this->db->table('trust_stats')->whereIn('user_id', $ids)->get()->keyBy('user_id');
                $rows = [];

                foreach ($old as $id => $o) {
                    $b = $base[$id] ?? null;
                    $rows[] = [
                        'user_id' => (int) $id,
                        'base_days' => (int) ($b->base_days ?? 0) + (int) $o->days,
                        'base_posts_read' => (int) ($b->base_posts_read ?? 0) + (int) $o->posts,
                        'base_topics_entered' => (int) ($b->base_topics_entered ?? 0) + (int) $o->topics,
                        'base_read_seconds' => (int) ($b->base_read_seconds ?? 0) + (int) $o->seconds,
                    ];
                }

                $this->db->table('trust_stats')->upsert($rows, ['user_id'], ['base_days', 'base_posts_read', 'base_topics_entered', 'base_read_seconds']);
                $this->db->table('trust_daily')->whereIn('user_id', $ids)->where('day', '<', $cutoff)->delete();
            });
        }
    }
}
