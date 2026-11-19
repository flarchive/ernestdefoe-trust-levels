<?php

namespace ErnestDefoe\TrustLevels\Access;

use ErnestDefoe\TrustLevels\Config;
use Flarum\User\User;

/**
 * What a level (or a silence) takes away, for both policies: GlobalTrustPolicy
 * (abilities with no model) and TrustPolicy (discussions, posts, users, polls).
 * Only ever a deny, so it never grants what the permission grid doesn't; the
 * policies run before core's admin check, so exempt members are skipped here.
 * Costs nothing: the level and silence are columns on the actor.
 */
class Restrictions
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

    public function denies(User $actor, string $ability): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        $silenced = Exempt::silenced($actor);
        $level = $this->exempt->level($actor);

        $relevant = $silenced
            ? in_array($ability, self::SILENCED, true)
            : in_array($ability, ['fof-upload.upload', 'replyWithoutApproval', 'startWithoutApproval'], true);

        if (! $relevant || $this->exempt->is($actor)) {
            return false;
        }

        if ($silenced) {
            return true;
        }

        return match ($ability) {
            'fof-upload.upload' => $level < $this->config->get('uploads_from'),
            default => $level === 0 && (int) $actor->comment_count < $this->config->get('approval_posts'),
        };
    }
}
