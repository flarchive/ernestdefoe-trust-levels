<?php

namespace ErnestDefoe\TrustLevels;

use Flarum\Locale\TranslatorInterface;

/** A level's name: the admin's, else the translated default. */
class Names
{
    public function __construct(private Config $config, private TranslatorInterface $translator)
    {
    }

    public function name(int $level): string
    {
        return $this->config->all()['names'][$level] ?: $this->translator->trans('ernestdefoe-trust-levels.lib.levels.'.$level);
    }
}
