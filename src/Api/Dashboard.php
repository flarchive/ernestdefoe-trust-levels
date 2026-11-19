<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * What the admin dashboard shows: members per level, this week's moves, who's
 * closest to their next level, recent changes and the last run. A handful of
 * grouped queries, whatever the size of the forum.
 */
class Dashboard
{
    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $week = Carbon::now()->subDays(7);
        $lastRun = $this->settings->get(Config::LAST_RUN);

        return [
            'counts' => $this->counts(),
            'moves' => $this->moves($week),
            'close' => $this->closest(),
            'recent' => $this->recent($week),
            'lastRun' => $lastRun,
            'lastRunCount' => (int) $this->settings->get(Config::LAST_RUN.'_count'),
            'lastRunSeconds' => (float) $this->settings->get(Config::LAST_RUN.'_seconds'),
            'stale' => ! $lastRun || Carbon::parse($lastRun)->lt(Carbon::now()->subHours(2)),
        ];
    }

    /** @return array<int, int> members per level */
    private function counts(): array
    {
        $counts = array_fill(0, Config::MAX + 1, 0);

        foreach ($this->db->table('users')->groupBy('trust_level')->select('trust_level')->selectRaw('count(*) as n')->get() as $row) {
            $counts[min(Config::MAX, (int) $row->trust_level)] += (int) $row->n;
        }

        return $counts;
    }

    /** @return array<int, array{up: int, down: int}> promotions and drops into each level this week */
    private function moves(Carbon $week): array
    {
        $moves = array_fill(0, Config::MAX + 1, ['up' => 0, 'down' => 0]);

        foreach ($this->db->table('trust_events')->whereIn('type', ['promoted', 'demoted'])->where('created_at', '>=', $week)
            ->groupBy('type', 'to_level')->select('type', 'to_level')->selectRaw('count(*) as n')->get() as $row) {
            $moves[(int) $row->to_level][$row->type === 'promoted' ? 'up' : 'down'] += (int) $row->n;
        }

        return $moves;
    }

    /** @return list<array<string, mixed>> the 10 members closest to their next level, with what they still miss */
    private function closest(): array
    {
        $rows = $this->db->table('trust_stats')
            ->join('users', 'users.id', '=', 'trust_stats.user_id')
            ->whereNotNull('trust_stats.next_level')
            ->where('users.trust_locked', false)
            ->whereNull('users.trust_silenced_at')
            ->orderByDesc('trust_stats.next_percent')
            ->orderByDesc('users.last_seen_at')
            ->limit(10)
            ->get(['users.id', 'users.username', 'users.avatar_url', 'users.trust_level', 'trust_stats.next_level', 'trust_stats.next_percent', 'trust_stats.data']);

        return $rows->map(function ($r) {
            $next = json_decode((string) $r->data, true)['next'] ?? null;
            $missing = array_values(array_filter($next['rows'] ?? [], fn ($row) => ! $row['met']));

            return [
                'id' => (int) $r->id,
                'username' => $r->username,
                'level' => (int) $r->trust_level,
                'next' => (int) $r->next_level,
                'percent' => (int) $r->next_percent,
                'missing' => array_slice($missing, 0, 2),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> this week's level changes, locks and holds */
    private function recent(Carbon $week): array
    {
        $rows = $this->db->table('trust_events')
            ->join('users', 'users.id', '=', 'trust_events.user_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'trust_events.actor_id')
            ->whereIn('trust_events.type', ['promoted', 'demoted', 'silenced', 'unsilenced', 'locked', 'unlocked', 'gatehouse'])
            ->where('trust_events.created_at', '>=', $week)
            ->orderByDesc('trust_events.id')
            ->limit(15)
            ->get(['trust_events.type', 'trust_events.from_level', 'trust_events.to_level', 'trust_events.data', 'trust_events.created_at', 'users.id', 'users.username', 'actors.username as actor']);

        return $rows->map(fn ($r) => [
            'type' => $r->type,
            'from' => $r->from_level === null ? null : (int) $r->from_level,
            'to' => $r->to_level === null ? null : (int) $r->to_level,
            'reason' => json_decode((string) $r->data, true)['reason'] ?? null,
            'at' => Carbon::parse($r->created_at)->toIso8601String(),
            'userId' => (int) $r->id,
            'username' => $r->username,
            'actor' => $r->actor,
        ])->values()->all();
    }
}
