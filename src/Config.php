<?php

namespace ErnestDefoe\TrustLevels;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Everything the admin sets, in one JSON setting, read once per request.
 *
 * Saved by the admin page through core's settings API; every value is cleaned
 * here on the way out, so a hand-edited or half-written setting can't make a
 * limit negative or a requirement a string. Keys missing from the stored JSON
 * take the defaults, so a new option never needs a migration.
 *
 * A limit that is absent or null for a level means "no limit" at that level.
 */
class Config
{
    public const KEY = 'ernestdefoe-trust-levels.config';
    public const LAST_RUN = 'ernestdefoe-trust-levels.last_run';
    public const INSTALLED = 'ernestdefoe-trust-levels.installed_at';
    public const TOTALS = 'ernestdefoe-trust-levels.totals';

    /** Levels that are reached automatically. Level 4 is given by staff. */
    public const AUTO_MAX = 3;
    public const MAX = 4;

    public const LIMITS = ['links', 'images', 'mentions', 'post_seconds', 'discussions_day', 'messages_day'];

    public const DEFAULTS = [
        'names' => [0 => '', 1 => '', 2 => '', 3 => '', 4 => ''],
        'req' => [
            1 => ['topics_entered' => 5, 'posts_read' => 30, 'read_minutes' => 10],
            2 => [
                'days_visited' => 15, 'topics_entered' => 20, 'posts_read' => 100, 'read_minutes' => 60,
                'replied_topics' => 3, 'likes_given' => 3, 'likes_received' => 1,
            ],
            3 => [
                'days_visited' => 50, 'replied_topics' => 10,
                'topics_percent' => 25, 'topics_max' => 500, 'posts_percent' => 25, 'posts_max' => 20000,
                'likes_received' => 20, 'likes_received_users' => 5, 'likes_received_days' => 7, 'likes_given' => 30,
                'flagged_posts' => 5, 'flagged_by' => 5, 'suspended_months' => 6,
            ],
        ],
        'groups' => [1 => null, 2 => null, 3 => null, 4 => null],
        'limits' => [
            'links' => [0 => 2, 1 => 5, 2 => 10],
            'images' => [0 => 1, 1 => 5],
            'mentions' => [0 => 2, 1 => 5, 2 => 10],
            'post_seconds' => [0 => 60, 1 => 15],
            'discussions_day' => [0 => 3, 1 => 10],
            'messages_day' => [0 => 0, 1 => 5, 2 => 20],
        ],
        'uploads_from' => 2,
        'approval_posts' => 2,
        'spam' => ['flags' => 3, 'flagger_level' => 2, 'silence_up_to' => 1, 'gatehouse_days' => 7, 'drop_flags' => 3],
        'grace_days' => 14,
        'buzz' => false,
        'exempt_groups' => [],
    ];

    private ?array $config = null;

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function all(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $stored = json_decode((string) $this->settings->get(self::KEY), true);

        return $this->config = self::clean(is_array($stored) ? $stored : []);
    }

    /** Forget the copy read this request (after the admin saves). */
    public function refresh(): void
    {
        $this->config = null;
    }

    public static function clean(array $stored): array
    {
        $d = self::DEFAULTS;
        $out = $d;

        foreach ($d['names'] as $level => $_) {
            $out['names'][$level] = mb_substr(trim((string) ($stored['names'][$level] ?? '')), 0, 30);
        }

        foreach ($d['req'] as $level => $reqs) {
            foreach ($reqs as $key => $default) {
                $out['req'][$level][$key] = self::int($stored['req'][$level][$key] ?? $default, $default);
            }
        }

        foreach ($d['groups'] as $level => $_) {
            $id = $stored['groups'][$level] ?? null;
            $out['groups'][$level] = $id ? (int) $id : null;
        }

        foreach (self::LIMITS as $limit) {
            $out['limits'][$limit] = [];
            $levels = array_key_exists('limits', $stored) && isset($stored['limits'][$limit]) && is_array($stored['limits'][$limit])
                ? $stored['limits'][$limit]
                : $d['limits'][$limit];

            foreach ($levels as $level => $value) {
                if ($value !== null && $value !== '' && (int) $level >= 0 && (int) $level <= self::MAX) {
                    $out['limits'][$limit][(int) $level] = max(0, (int) $value);
                }
            }
        }

        foreach (['uploads_from', 'approval_posts', 'grace_days'] as $key) {
            $out[$key] = self::int($stored[$key] ?? $d[$key], $d[$key]);
        }

        $out['uploads_from'] = min($out['uploads_from'], self::MAX);

        foreach ($d['spam'] as $key => $default) {
            $out['spam'][$key] = self::int($stored['spam'][$key] ?? $default, $default);
        }

        $out['buzz'] = (bool) ($stored['buzz'] ?? false);
        $out['exempt_groups'] = array_values(array_map('intval', array_filter((array) ($stored['exempt_groups'] ?? []))));

        return $out;
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    public function req(int $level): array
    {
        return $this->all()['req'][$level] ?? [];
    }

    /** The limit at this level, or null for none. */
    public function limit(string $limit, int $level): ?int
    {
        return $this->all()['limits'][$limit][$level] ?? null;
    }

    /** Lowest level with no limit of this kind (the level it's lifted at). */
    public function liftedAt(string $limit, int $level): ?int
    {
        for ($l = $level + 1; $l <= self::MAX; $l++) {
            $next = $this->limit($limit, $l);

            $now = $this->limit($limit, $level) ?? 0;

            if ($next === null || ($limit === 'post_seconds' ? $next < $now : $next > $now)) {
                return $l;
            }
        }

        return null;
    }

    public function spam(string $key): int
    {
        return $this->all()['spam'][$key];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key];
    }

    /** level => group id, only levels with a group. */
    public function groups(): array
    {
        return array_filter($this->all()['groups']);
    }
}
