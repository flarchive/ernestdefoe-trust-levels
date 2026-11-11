<?php

namespace ErnestDefoe\TrustLevels\Access;

use ErnestDefoe\TrustLevels\Config;
use Flarum\Database\AbstractModel;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * What a level (or a silence) takes away. Only ever FORCE_DENY or nothing,
 * so it never grants what the permission grid doesn't, and it runs before
 * core's admin check: exempt members are skipped here, by us.
 *
 * Registered as the global policy and for discussions, posts and dialogs.
 * Costs nothing: the level and silence are columns on the actor.
 */
class TrustPolicy extends AbstractPolicy
{
    /**
     * What a silenced member can't do: they can read. (Core lets a member edit or hide
     * their own post only while they may reply, so denying `reply` stops edits too.)
     *
     * 🚨 Never an ability another extension serializes per user ($user->can(...)
     * on every member in a response): flarum/messages sends `canSendAnyMessage`
     * for everyone, so denying `sendAnyMessage` here told every member who was
     * New or on hold. Private messages are refused in Limits::messageCreating.
     */
    private const SILENCED = [
        'startDiscussion', 'reply', 'like', 'fof-upload.upload',
        'replyWithoutApproval', 'startWithoutApproval',
        // Renaming, reactions (fof/reactions), poll votes (fof/polls), the profile bio (fof/user-bio).
        'rename', 'react', 'vote', 'polls.vote', 'editBio',
    ];

    public function __construct(private Config $config, private Exempt $exempt)
    {
    }

    public function can(User $actor, string $ability, string|AbstractModel|null $model = null): ?string
    {
        if ($actor->isGuest()) {
            return null;
        }

        $silenced = Exempt::silenced($actor);
        $level = $this->exempt->level($actor);

        $relevant = $silenced
            ? in_array($ability, self::SILENCED, true)
            : in_array($ability, ['fof-upload.upload', 'replyWithoutApproval', 'startWithoutApproval'], true);

        if (! $relevant || $this->exempt->is($actor)) {
            return null;
        }

        if ($silenced) {
            return $this->forceDeny();
        }

        return match ($ability) {
            'fof-upload.upload' => $level < $this->config->get('uploads_from') ? $this->forceDeny() : null,
            default => $level === 0 && (int) $actor->comment_count < $this->config->get('approval_posts') ? $this->forceDeny() : null,
        };
    }
}
