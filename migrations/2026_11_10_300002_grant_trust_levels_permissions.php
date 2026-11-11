<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    // See and set members' levels, lock them, lift a silence.
    'ernestdefoe-trust-levels.manage' => Group::MODERATOR_ID,
    // Never held back by the per-level limits.
    'ernestdefoe-trust-levels.bypassLimits' => Group::MODERATOR_ID,
]);
