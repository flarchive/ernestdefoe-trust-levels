<?php

namespace ErnestDefoe\TrustLevels;

use ErnestDefoe\TrustLevels\Stats\Stats;

/**
 * What a level asks for, and how far a member is: one row per requirement.
 *
 * Rows are [key, have, need, met, upTo]; `upTo` marks an "at most" row (flags).
 * A requirement whose extension is off is left out, and a requirement set to
 * 0 by the admin is left out too.
 */
class Requirements
{
    public function __construct(private Config $config, private Stats $stats)
    {
    }

    /**
     * @param array<string, int> $s a member's numbers from Stats::forUsers
     * @param array{topics: int, posts: int} $totals
     * @param float $margin 0.9 when checking a Regular who might drop: 10% short is still fine
     * @return list<array{key: string, have: int, need: int, met: bool, upTo?: bool}>
     */
    public function rows(int $level, array $s, array $totals, float $margin = 1.0): array
    {
        $r = $this->config->req($level);
        $rows = $level < 3 ? $this->allTime($r, $s) : $this->window($r, $s, $totals);

        $rows = array_map(fn (array $row) => $this->row(...$row, margin: $margin), $rows);

        if ($level >= 3 && $r['suspended_months'] > 0) {
            $rows[] = ['key' => 'not_suspended', 'have' => $s['suspended'] ? 0 : 1, 'need' => 1, 'met' => ! $s['suspended']];
        }

        return array_values(array_filter($rows));
    }

    /**
     * Basic and Member: totals since joining.
     *
     * @param array<string, int> $r
     * @param array<string, int> $s
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function allTime(array $r, array $s): array
    {
        $rows = [];

        foreach (['days_visited', 'topics_entered', 'posts_read', 'read_minutes', 'replied_topics'] as $key) {
            $rows[] = [$key, $s[$key], $r[$key] ?? 0];
        }

        if ($this->stats->likesOn()) {
            $rows[] = ['likes_given', $s['likes_given'], $r['likes_given'] ?? 0];
            $rows[] = ['likes_received', $s['likes_received'], $r['likes_received'] ?? 0];
        }

        return $rows;
    }

    /**
     * Regular: the last 100 days, reading as a share of what was posted.
     *
     * @param array<string, int> $r
     * @param array<string, int> $s
     * @param array{topics: int, posts: int} $totals
     * @return list<array{0: string, 1: int, 2: int, 3?: bool}>
     */
    private function window(array $r, array $s, array $totals): array
    {
        $rows = [['days_visited', $s['days_visited_w'], $r['days_visited']], ['replied_topics', $s['replied_topics_w'], $r['replied_topics']]];

        if ($r['topics_percent'] > 0) {
            $rows[] = ['topics_entered', $s['topics_entered_w'], min((int) ceil($totals['topics'] * $r['topics_percent'] / 100), $r['topics_max'] ?: PHP_INT_MAX)];
        }

        if ($r['posts_percent'] > 0) {
            $rows[] = ['posts_read', $s['posts_read_w'], min((int) ceil($totals['posts'] * $r['posts_percent'] / 100), $r['posts_max'] ?: PHP_INT_MAX)];
        }

        if ($this->stats->likesOn()) {
            foreach (['likes_given', 'likes_received', 'likes_received_users', 'likes_received_days'] as $key) {
                $rows[] = [$key, $s[$key.'_w'], $r[$key]];
            }
        }

        // "Fewer than N": 0 turns the requirement off, like every other one.
        if ($this->stats->flagsOn()) {
            foreach (['flagged_posts', 'flagged_by'] as $key) {
                if ($r[$key] > 0) {
                    $rows[] = [$key, $s[$key], $r[$key] - 1, true];
                }
            }
        }

        return $rows;
    }

    /**
     * One checklist row, or null for a requirement turned off (0). The margin
     * lowers what's needed; an "up to" row (reports) is a ceiling, never lowered.
     *
     * @return array{key: string, have: int, need: int, met: bool, upTo?: bool}|null
     */
    private function row(string $key, int $have, int $need, bool $upTo = false, float $margin = 1.0): ?array
    {
        if ($need <= 0 && ! $upTo) {
            return null;
        }

        $need = $upTo ? $need : (int) ceil($need * $margin);

        return ['key' => $key, 'have' => $have, 'need' => $need, 'met' => $upTo ? $have <= $need : $have >= $need] + ($upTo ? ['upTo' => true] : []);
    }

    public static function met(array $rows): bool
    {
        foreach ($rows as $row) {
            if (! $row['met']) {
                return false;
            }
        }

        return true;
    }

    /** 0-100: how close, averaged over the requirements, each capped at 100%. */
    public static function percent(array $rows): int
    {
        if (! $rows) {
            return 100;
        }

        $sum = 0;

        foreach ($rows as $row) {
            if (! empty($row['upTo'])) {
                $sum += $row['met'] ? 1 : 0;
            } else {
                $sum += $row['need'] > 0 ? min(1, $row['have'] / $row['need']) : 1;
            }
        }

        return (int) floor($sum / count($rows) * 100);
    }
}
