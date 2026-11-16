<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Evaluator;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/trust-levels/dashboard: members per level, this week's moves,
 *      who's closest to their next level, recent changes, the last run.
 * POST /api/trust-levels/run: run the check now (no cron, or impatience).
 * Admins only.
 */
class DashboardController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private Evaluator $evaluator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $busy = false;

        if ($request->getMethod() === 'POST') {
            @set_time_limit(300);
            $busy = $this->evaluator->run() === null;
        }

        $week = Carbon::now()->subDays(7);

        $counts = array_fill(0, Config::MAX + 1, 0);

        foreach ($this->db->table('users')->groupBy('trust_level')->select('trust_level')->selectRaw('count(*) as n')->get() as $row) {
            $counts[min(Config::MAX, (int) $row->trust_level)] += (int) $row->n;
        }

        $moves = array_fill(0, Config::MAX + 1, ['up' => 0, 'down' => 0]);

        foreach ($this->db->table('trust_events')->whereIn('type', ['promoted', 'demoted'])->where('created_at', '>=', $week)
            ->groupBy('type', 'to_level')->select('type', 'to_level')->selectRaw('count(*) as n')->get() as $row) {
            $moves[(int) $row->to_level][$row->type === 'promoted' ? 'up' : 'down'] += (int) $row->n;
        }

        $close = $this->db->table('trust_stats')
            ->join('users', 'users.id', '=', 'trust_stats.user_id')
            ->whereNotNull('trust_stats.next_level')
            ->where('users.trust_locked', false)
            ->whereNull('users.trust_silenced_at')
            ->orderByDesc('trust_stats.next_percent')
            ->orderByDesc('users.last_seen_at')
            ->limit(10)
            ->get(['users.id', 'users.username', 'users.avatar_url', 'users.trust_level', 'trust_stats.next_level', 'trust_stats.next_percent', 'trust_stats.data']);

        $recent = $this->db->table('trust_events')
            ->join('users', 'users.id', '=', 'trust_events.user_id')
            ->leftJoin('users as actors', 'actors.id', '=', 'trust_events.actor_id')
            ->whereIn('trust_events.type', ['promoted', 'demoted', 'silenced', 'unsilenced', 'locked', 'unlocked', 'gatehouse'])
            ->where('trust_events.created_at', '>=', $week)
            ->orderByDesc('trust_events.id')
            ->limit(15)
            ->get(['trust_events.type', 'trust_events.from_level', 'trust_events.to_level', 'trust_events.data', 'trust_events.created_at', 'users.id', 'users.username', 'actors.username as actor']);

        $lastRun = $this->settings->get(Config::LAST_RUN);

        return new JsonResponse(['data' => [
            'counts' => $counts,
            'moves' => $moves,
            'close' => $close->map(function ($r) {
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
            })->all(),
            'recent' => $recent->map(fn ($r) => [
                'type' => $r->type,
                'from' => $r->from_level === null ? null : (int) $r->from_level,
                'to' => $r->to_level === null ? null : (int) $r->to_level,
                'reason' => json_decode((string) $r->data, true)['reason'] ?? null,
                'at' => Carbon::parse($r->created_at)->toIso8601String(),
                'userId' => (int) $r->id,
                'username' => $r->username,
                'actor' => $r->actor,
            ])->all(),
            'lastRun' => $lastRun,
            'lastRunCount' => (int) $this->settings->get(Config::LAST_RUN.'_count'),
            'lastRunSeconds' => (float) $this->settings->get(Config::LAST_RUN.'_seconds'),
            'busy' => $busy,
            'stale' => ! $lastRun || Carbon::parse($lastRun)->lt(Carbon::now()->subHours(2)),
        ]]);
    }
}
