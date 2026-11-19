<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Why a level changed (promoted, quiet, flags, staff, placed), as its own
 * column: drops for upheld reports are looked up by it, not matched inside the
 * JSON in `data`. Not Migration::addColumns, because earlier drops are copied
 * across here, once, so no request ever has to match inside the JSON.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('trust_events', function (Blueprint $table) {
            $table->string('reason', 20)->nullable();
        });

        $schema->getConnection()->table('trust_events')
            ->where('type', 'demoted')
            ->where('data', 'like', '%"reason":"flags"%')
            ->update(['reason' => 'flags']);
    },
    'down' => function (Builder $schema) {
        $schema->table('trust_events', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    },
];
