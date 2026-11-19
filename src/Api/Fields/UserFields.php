<?php

namespace ErnestDefoe\TrustLevels\Api\Fields;

use ErnestDefoe\TrustLevels\Access\Exempt;
use ErnestDefoe\TrustLevels\Config;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;

/**
 * Only the member and staff ever see a level: these fields are left out for
 * everyone else, so a level never reaches another member's browser. All of
 * them read columns already on the user row: no queries.
 */
class UserFields
{
    public function __construct(private Exempt $exempt, private Config $config)
    {
    }

    /** @return array<Schema\Attribute> */
    public function __invoke(): array
    {
        $canSee = fn (User $user, Context $context) => $context->getActor()->id === $user->id
            || $context->getActor()->hasPermission('ernestdefoe-trust-levels.manage');

        return [
            Schema\Integer::make('trustLevel')
                ->visible($canSee)
                ->get(fn (User $user) => (int) $user->getAttribute('trust_level')),
            Schema\Boolean::make('trustLocked')
                ->visible($canSee)
                ->get(fn (User $user) => (bool) $user->getAttribute('trust_locked')),
            Schema\Boolean::make('trustSilenced')
                ->visible($canSee)
                ->get(fn (User $user) => (bool) $user->getAttribute('trust_silenced_at')),
            // The composer's live link/image counter: the member's own limits.
            Schema\Arr::make('trustLimits')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id)
                ->get(fn (User $user) => $this->limits($user)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function limits(User $user): ?array
    {
        if ($this->exempt->is($user)) {
            return null;
        }

        $level = $this->exempt->level($user);
        $silenced = (bool) $user->getAttribute('trust_silenced_at');

        // Private messages: only this member's own browser hears it (flarum/messages'
        // per-user canSendAnyMessage must stay the same for everyone, see Access\Restrictions).
        return [
            'links' => $this->config->limit('links', $level),
            'images' => $this->config->limit('images', $level),
            'messages' => ! $silenced && $this->config->limit('messages_day', $level) !== 0,
            'messagesFrom' => $silenced ? null : $this->config->liftedAt('messages_day', $level),
        ];
    }
}
