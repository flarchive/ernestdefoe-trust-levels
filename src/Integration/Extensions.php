<?php

namespace ErnestDefoe\TrustLevels\Integration;

use Flarum\Extension\ExtensionManager;

/**
 * Which of the optional extensions are on, asked in one place.
 *
 * Their listeners, extenders and bindings are registered with
 * Extend\Conditional in extend.php. These runtime checks are for the rest:
 * whether to read another extension's table (likes, flags, suspensions, Ladder
 * rungs, Sleuth matches) or show a requirement or limit that needs it. None of
 * them loads a class from that extension, so it is safe when it is absent.
 */
class Extensions
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function on(string $id): bool
    {
        return $this->extensions->isEnabled($id);
    }
}
