<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\Docket\Crowd\Reporters;
use ErnestDefoe\TrustLevels\Access\Exempt;
use Illuminate\Database\ConnectionInterface;

/**
 * Docket's crowd moderation, with each reporter weighed by their level.
 *
 * Docket (1.5+) works out reporter weights in its Reporters service; this
 * takes its place only while Docket is enabled (DocketProvider; this file is
 * never loaded otherwise). Level 0 counts 0.5, level 4 counts 1.5; with
 * Docket's own track-record weighting on, the two are averaged. One query for
 * all the reporters of a report.
 *
 * No constructor of its own: the container builds Docket's dependencies, so a
 * change to them in Docket can't break this; ours come in through levels().
 */
class DocketReporters extends Reporters
{
    public const LEVEL_WEIGHTS = [0 => 0.5, 1 => 0.75, 2 => 1.0, 3 => 1.25, 4 => 1.5];

    private Exempt $exempt;

    private ConnectionInterface $db;

    public function levels(Exempt $exempt, ConnectionInterface $db): static
    {
        $this->exempt = $exempt;
        $this->db = $db;

        return $this;
    }

    public function weights(array $userIds, ?int $exceptReport = null): array
    {
        $weights = parent::weights($userIds, $exceptReport);

        if (! $userIds) {
            return $weights;
        }

        $trackRecord = (bool) $this->setting('trust');
        $rows = $this->db->table('users')->whereIn('id', $userIds)->get(['id', 'trust_level', 'trust_level_at', 'joined_at']);

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $byLevel = self::LEVEL_WEIGHTS[min(4, $this->exempt->effective((int) $row->trust_level, $row->trust_level_at, $row->joined_at))];
            $weights[$id] = $trackRecord ? (($weights[$id] ?? 1.0) + $byLevel) / 2 : $byLevel;
        }

        return $weights;
    }
}
