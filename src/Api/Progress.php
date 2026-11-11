<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Config;
use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Everything a member's "Trust level" tab shows, from their stats row and
 * history: four small queries, only when the tab is opened.
 */
class Progress
{
    /** History types a member sees about themselves; staff see everything. */
    private const OWN_HISTORY = ['promoted', 'demoted', 'silenced', 'unsilenced'];

    public function __construct(private ConnectionInterface $db, private Config $config, private ExtensionManager $extensions)
    {
    }

    public function for(User $user, bool $staff): array
    {
        $level = (int) $user->getAttribute('trust_level');
        $row = $this->db->table('trust_stats')->where('user_id', $user->id)->first();
        $data = $row ? (array) json_decode((string) $row->data, true) : [];

        $history = $this->db->table('trust_events')
            ->where('user_id', $user->id)
            ->when(! $staff, fn ($q) => $q->whereIn('type', self::OWN_HISTORY))
            ->orderByDesc('id')
            ->limit(30)
            ->get(['type', 'from_level', 'to_level', 'actor_id', 'post_id', 'data', 'created_at']);

        $actors = $staff ? $this->db->table('users')->whereIn('id', $history->pluck('actor_id')->filter()->unique()->all())->pluck('username', 'id') : collect();

        $next = $data['next'] ?? null;

        return [
            'level' => $level,
            'levelAt' => $this->date($user->getAttribute('trust_level_at')),
            'joinedAt' => $this->date($user->joined_at),
            'locked' => (bool) $user->getAttribute('trust_locked'),
            'silenced' => (bool) $user->getAttribute('trust_silenced_at'),
            'note' => $staff ? $user->getAttribute('trust_note') : null,
            'computedAt' => $row?->computed_at ? $this->date($row->computed_at) : null,
            'next' => $next,
            'keep' => $level === 3 ? ($data['keep'] ?? null) : null,
            'unlocks' => $next ? $this->unlocks($level, (int) $next['level']) : ($level === 3 ? $this->unlocks(3, 4) : []),
            'stats' => $staff ? ($data['stats'] ?? null) : null,
            'history' => $history->map(fn ($e) => [
                'type' => $e->type,
                'from' => $e->from_level === null ? null : (int) $e->from_level,
                'to' => $e->to_level === null ? null : (int) $e->to_level,
                'reason' => json_decode((string) $e->data, true)['reason'] ?? null,
                'actor' => $staff && $e->actor_id ? ($actors[$e->actor_id] ?? null) : null,
                'postId' => $staff && $e->post_id ? (int) $e->post_id : null,
                'at' => $this->date($e->created_at),
            ])->all(),
        ];
    }

    /**
     * What reaching $to changes compared with $from: limits raised or lifted,
     * uploads, posts no longer held for approval.
     *
     * @return list<array{key: string, value?: int|null}>
     */
    public function unlocks(int $from, int $to): array
    {
        $out = [];

        foreach (Config::LIMITS as $limit) {
            if ($limit === 'messages_day' && ! $this->extensions->isEnabled('flarum-messages')) {
                continue;
            }

            if ($limit === 'mentions' && ! $this->extensions->isEnabled('flarum-mentions')) {
                continue;
            }

            $now = $this->config->limit($limit, $from);
            $then = $this->config->limit($limit, $to);

            // Fewer seconds between posts is more; everywhere else a higher number is.
            $better = $limit === 'post_seconds' ? $then < $now : $then > $now;

            if ($now !== null && ($then === null || $better)) {
                $out[] = ['key' => $limit, 'value' => $then];
            }
        }

        $uploadsFrom = $this->config->get('uploads_from');

        if ($this->extensions->isEnabled('fof-upload') && $from < $uploadsFrom && $to >= $uploadsFrom) {
            $out[] = ['key' => 'uploads'];
        }

        if ($from === 0 && $this->extensions->isEnabled('flarum-approval') && $this->config->get('approval_posts') > 0) {
            $out[] = ['key' => 'approval'];
        }

        return $out;
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
