<?php

use Carbon\Carbon;
use Flarum\Database\Migration;

/*
 * Members who joined before this moment are placed from their history (never
 * below Basic); members who join after start at New.
 */
return Migration::addSettings([
    'ernestdefoe-trust-levels.installed_at' => Carbon::now()->toIso8601String(),
]);
