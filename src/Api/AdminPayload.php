<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Config;
use Flarum\Frontend\Document;

/**
 * The admin page's placeholders and starting values: Config::DEFAULTS, so the
 * defaults live in one place (PHP) and the page never keeps a copy of its own.
 */
class AdminPayload
{
    public function __invoke(Document $document): void
    {
        $document->payload['trustLevelDefaults'] = Config::DEFAULTS;
    }
}
