<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

/*
 * Members who joined before this moment are placed from their history (never
 * below Basic); members who join after start at New.
 */
return [
    'up' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->insertOrIgnore([
            'key' => 'ernestdefoe-trust-levels.installed_at',
            'value' => Carbon::now()->toIso8601String(),
        ]);
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-trust-levels.installed_at')->delete();
    },
];
