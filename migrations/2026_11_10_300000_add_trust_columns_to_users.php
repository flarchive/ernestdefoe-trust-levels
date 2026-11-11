<?php

use Flarum\Database\Migration;

/*
 * A member's level lives on the member, so every permission check and limit
 * reads it from the actor Flarum has already loaded: no query per request.
 *
 * trust_level_at: when they reached it (null = not placed yet: members who
 * were here before the extension was installed, placed by the first run).
 */
return Migration::addColumns('users', [
    'trust_level' => ['tinyInteger', 'unsigned' => true, 'default' => 0],
    'trust_level_at' => ['dateTime', 'nullable' => true],
    'trust_locked' => ['boolean', 'default' => false],
    'trust_note' => ['string', 'length' => 200, 'nullable' => true],
    'trust_silenced_at' => ['dateTime', 'nullable' => true],
    'trust_checked_at' => ['dateTime', 'nullable' => true],
]);
