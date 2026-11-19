<?php

namespace ErnestDefoe\TrustLevels\Gdpr;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\User;

/**
 * flarum/gdpr's anonymization sets every users column it doesn't know to null,
 * and trust_level and trust_locked are NOT NULL, so the save failed on
 * PostgreSQL and strict MySQL. Put their defaults back before the save,
 * whichever data type runs first. Registered only when flarum/gdpr is enabled.
 */
class KeepColumnsValid extends AbstractServiceProvider
{
    private const DEFAULTS = ['trust_level' => 0, 'trust_locked' => false];

    public function boot(): void
    {
        User::saving(function (User $user) {
            $attributes = $user->getAttributes();

            foreach (self::DEFAULTS as $column => $default) {
                if (array_key_exists($column, $attributes) && $attributes[$column] === null) {
                    $user->setAttribute($column, $default);
                }
            }
        });
    }
}
