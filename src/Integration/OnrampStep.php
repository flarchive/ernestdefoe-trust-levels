<?php

namespace ErnestDefoe\TrustLevels\Integration;

use ErnestDefoe\Onramp\StepType\StepType;
use ErnestDefoe\TrustLevels\Event\LevelChanged;
use Flarum\User\User;

/**
 * Onramp: a step "Reach trust level N" (N is the step's count). Loaded only
 * while Onramp is enabled.
 */
class OnrampStep extends StepType
{
    public function key(): string
    {
        return 'trust-levels.reach';
    }

    public function icon(): string
    {
        return 'fas fa-shield-halved';
    }

    public function events(): array
    {
        return [LevelChanged::class => fn (object $e) => $e->user ?? null];
    }

    public function progress(User $user, array $config): int
    {
        return (int) $user->getAttribute('trust_level');
    }

    public function fields(): array
    {
        return ['count'];
    }

    public function extension(): ?string
    {
        return 'ernestdefoe-trust-levels';
    }
}
