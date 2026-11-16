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
        $rows = [];
        $add = function (string $key, int $have, int $need, bool $upTo = false) use (&$rows, $margin) {
            if ($need <= 0 && ! $upTo) {
                return;
            }

            $need = $upTo ? $need : (int) ceil($need * $margin);
            $rows[] = ['key' => $key, 'have' => $have, 'need' => $need, 'met' => $upTo ? $have <= $need : $have >= $need] + ($upTo ? ['upTo' => true] : []);
        };

        if ($level < 3) {
            $add('days_visited', $s['days_visited'], $r['days_visited'] ?? 0);
            $add('topics_entered', $s['topics_entered'], $r['topics_entered'] ?? 0);
            $add('posts_read', $s['posts_read'], $r['posts_read'] ?? 0);
            $add('read_minutes', $s['read_minutes'], $r['read_minutes'] ?? 0);
            $add('replied_topics', $s['replied_topics'], $r['replied_topics'] ?? 0);

            if ($this->stats->likesOn()) {
                $add('likes_given', $s['likes_given'], $r['likes_given'] ?? 0);
                $add('likes_received', $s['likes_received'], $r['likes_received'] ?? 0);
            }

            return $rows;
        }

        $add('days_visited', $s['days_visited_w'], $r['days_visited']);
        $add('replied_topics', $s['replied_topics_w'], $r['replied_topics']);

        if ($r['topics_percent'] > 0) {
            $add('topics_entered', $s['topics_entered_w'], min((int) ceil($totals['topics'] * $r['topics_percent'] / 100), $r['topics_max'] ?: PHP_INT_MAX));
        }

        if ($r['posts_percent'] > 0) {
            $add('posts_read', $s['posts_read_w'], min((int) ceil($totals['posts'] * $r['posts_percent'] / 100), $r['posts_max'] ?: PHP_INT_MAX));
        }

        if ($this->stats->likesOn()) {
            $add('likes_given', $s['likes_given_w'], $r['likes_given']);
            $add('likes_received', $s['likes_received_w'], $r['likes_received']);
            $add('likes_received_users', $s['likes_received_users_w'], $r['likes_received_users']);
            $add('likes_received_days', $s['likes_received_days_w'], $r['likes_received_days']);
        }

        // "Fewer than N": 0 turns the requirement off, like every other one.
        if ($this->stats->flagsOn()) {
            if ($r['flagged_posts'] > 0) {
                $add('flagged_posts', $s['flagged_posts'], $r['flagged_posts'] - 1, true);
            }

            if ($r['flagged_by'] > 0) {
                $add('flagged_by', $s['flagged_by'], $r['flagged_by'] - 1, true);
            }
        }

        if ($r['suspended_months'] > 0) {
            $rows[] = ['key' => 'not_suspended', 'have' => $s['suspended'] ? 0 : 1, 'need' => 1, 'met' => ! $s['suspended']];
        }

        return $rows;
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
