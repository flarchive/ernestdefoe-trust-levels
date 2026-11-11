<?php

namespace ErnestDefoe\TrustLevels\Gdpr;

use Flarum\Gdpr\Data\Type;
use Illuminate\Database\ConnectionInterface;

/**
 * A member's daily activity counts, their rollup and their trust history, in
 * a data export; all go on erasure (the rows also go with the account through
 * their foreign keys). Registered only when flarum/gdpr is enabled.
 */
class TrustData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-trust-levels.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::deleteDescription();
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-trust-levels.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $db = resolve(ConnectionInterface::class);
        $id = $this->user->id;

        return [
            ['trust-levels/level.json' => $this->encodeForExport([
                'level' => (int) $this->user->getAttribute('trust_level'),
                'since' => $this->user->getAttribute('trust_level_at'),
                'locked' => (bool) $this->user->getAttribute('trust_locked'),
                'on_hold_since' => $this->user->getAttribute('trust_silenced_at'),
            ])],
            ['trust-levels/days.json' => $this->encodeForExport($db->table('trust_daily')->where('user_id', $id)->orderBy('day')->get(['day', 'posts_read', 'topics_entered', 'read_seconds'])->all())],
            ['trust-levels/history.json' => $this->encodeForExport($db->table('trust_events')->where('user_id', $id)->orderBy('id')->get(['type', 'from_level', 'to_level', 'created_at'])->all())],
        ];
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $db = resolve(ConnectionInterface::class);
        $db->table('trust_daily')->where('user_id', $this->user->id)->delete();
        $db->table('trust_stats')->where('user_id', $this->user->id)->delete();
        $db->table('trust_events')->where('user_id', $this->user->id)->delete();
        $db->table('trust_events')->where('actor_id', $this->user->id)->update(['actor_id' => null]);
        // Staff's free-text note about the member goes too.
        $db->table('users')->where('id', $this->user->id)->update(['trust_note' => null]);
    }
}
