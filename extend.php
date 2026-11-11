<?php

/*
 * Trust Levels: members earn trust by taking part, the way Discourse does it.
 *
 * Five levels, New to Leader. Levels 1 to 3 are reached automatically from
 * days visited, reading, replies and likes; Leader is given by staff. Each
 * level lifts limits that keep a fresh spam account harmless, and can give the
 * permissions of a hidden group. Posts the community flags are hidden at once.
 *
 * 🚨 extend.php reads no class constants outside closures: it autoloads during
 * boot and would fatal every request while a deploy updates composer.
 */

use ErnestDefoe\TrustLevels\Access\TrustPolicy;
use ErnestDefoe\TrustLevels\Api;
use ErnestDefoe\TrustLevels\Console\EvaluateCommand;
use ErnestDefoe\TrustLevels\Http\RememberActor;
use ErnestDefoe\TrustLevels\InheritPermissions;
use ErnestDefoe\TrustLevels\Integration\Optional;
use ErnestDefoe\TrustLevels\Limits\Limits;
use ErnestDefoe\TrustLevels\Listener\QuickCheck;
use ErnestDefoe\TrustLevels\Listener\ValidateSettings;
use ErnestDefoe\TrustLevels\Notification\LevelDroppedBlueprint;
use ErnestDefoe\TrustLevels\Notification\LevelReachedBlueprint;
use ErnestDefoe\TrustLevels\Provider;
use ErnestDefoe\TrustLevels\Spam\Spam;
use ErnestDefoe\TrustLevels\Tracking\Tracker;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Discussion\Event\UserRead;
use Flarum\Extend;
use Flarum\Post\Event\Deleting as PostDeleting;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\Post;
use Flarum\Settings\Event\Saving as SettingsSaving;
use Flarum\User\User;
use Illuminate\Console\Scheduling\Event;

$canSee = fn (User $user, Context $context) => $context->getActor()->id === $user->id
    || $context->getActor()->hasPermission('ernestdefoe-trust-levels.manage');

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less')
        ->route('/u/{username}/trust', 'user.trust'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-trust-levels', __DIR__.'/views'),

    (new Extend\ServiceProvider())->register(Provider::class),

    (new Extend\Middleware('api'))->add(RememberActor::class),

    (new Extend\Routes('api'))
        ->get('/trust-levels/users/{id:\d+}', 'trust-levels.member', Api\MemberController::class)
        ->post('/trust-levels/users/{id:\d+}', 'trust-levels.member.update', Api\MemberController::class)
        ->post('/trust-levels/users/{id:\d+}/spam', 'trust-levels.member.spam', Api\SpamController::class)
        ->get('/trust-levels/dashboard', 'trust-levels.dashboard', Api\DashboardController::class)
        ->post('/trust-levels/run', 'trust-levels.run', Api\DashboardController::class)
        ->post('/trust-levels/groups', 'trust-levels.groups', Api\GroupsController::class),

    // Only the member and staff ever see a level: these fields are left out
    // for everyone else, so a level never reaches another member's browser.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn () => [
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
                ->get(function (User $user) {
                    $exempt = resolve(\ErnestDefoe\TrustLevels\Access\Exempt::class);
                    $config = resolve(\ErnestDefoe\TrustLevels\Config::class);

                    if ($exempt->is($user)) {
                        return null;
                    }

                    $level = $exempt->level($user);

                    // Private messages: only this member's own browser hears it (flarum/messages'
                    // per-user canSendAnyMessage must stay the same for everyone, see TrustPolicy).
                    return [
                        'links' => $config->limit('links', $level),
                        'images' => $config->limit('images', $level),
                        'messages' => ! $user->getAttribute('trust_silenced_at') && $config->limit('messages_day', $level) !== 0,
                        'messagesFrom' => $user->getAttribute('trust_silenced_at') ? null : $config->liftedAt('messages_day', $level),
                    ];
                }),
        ]),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('trustLevelNames')
                ->get(fn () => resolve(\ErnestDefoe\TrustLevels\Config::class)->all()['names']),
            Schema\Boolean::make('canManageTrustLevels')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ernestdefoe-trust-levels.manage')),
        ]),

    (new Extend\User())
        ->permissionGroups(InheritPermissions::class),

    (new Extend\Policy())
        ->globalPolicy(TrustPolicy::class)
        ->modelPolicy(Discussion::class, TrustPolicy::class)
        ->modelPolicy(Post::class, TrustPolicy::class)
        ->modelPolicy(User::class, TrustPolicy::class)
        ->modelPolicy('FoF\Polls\Poll', TrustPolicy::class),


    (new Extend\Event())
        ->listen(UserRead::class, [Tracker::class, 'read'])
        ->listen(UserRead::class, [QuickCheck::class, 'read'])
        ->listen(Posted::class, [QuickCheck::class, 'posted'])
        ->listen(PostSaving::class, [Limits::class, 'postSaving'])
        ->listen(DiscussionSaving::class, [Limits::class, 'discussionSaving'])
        ->listen(Hidden::class, [Spam::class, 'hidden'])
        ->listen(Restored::class, [Spam::class, 'restored'])
        ->listen(PostDeleting::class, [Spam::class, 'deleting'])
        ->listen(SettingsSaving::class, ValidateSettings::class),

    (new Extend\Notification())
        ->type(LevelReachedBlueprint::class, ['alert'])
        ->type(LevelDroppedBlueprint::class, ['alert']),

    (new Extend\Console())
        ->command(EvaluateCommand::class)
        ->schedule(EvaluateCommand::class, function (Event $event): void {
            $event->hourlyAt(7)->withoutOverlapping();
        })
        ->schedule(EvaluateCommand::class, function (Event $event): void {
            $event->dailyAt('03:17')->withoutOverlapping();
        }, ['--all']),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-flags', fn () => [
            (new Extend\Event())->listen('Flarum\Flags\Event\Created', [Spam::class, 'flagged']),
        ])
        ->whenExtensionEnabled('flarum-suspend', fn () => [
            (new Extend\Event())->listen('Flarum\Suspend\Event\Suspended', [Spam::class, 'suspended']),
        ])
        ->whenExtensionEnabled('ernestdefoe-gatehouse', fn () => [
            (new Extend\Event())->listen('Ernestdefoe\Gatehouse\Event\Approved', [Spam::class, 'approvedByGatehouse']),
        ])
        ->whenExtensionEnabled('flarum-messages', fn () => [
            (new Extend\Event())->listen('Flarum\Messages\DialogMessage\Event\Creating', [Limits::class, 'messageCreating']),
        ])
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new \Flarum\Gdpr\Extend\UserData())->addType(\ErnestDefoe\TrustLevels\Gdpr\TrustData::class),
        ])
        ->whenExtensionEnabled('ernestdefoe-buzz', fn () => Optional::buzz())
        ->whenExtensionEnabled('ernestdefoe-onramp', fn () => Optional::onramp()),

    (new Extend\Settings())
        ->default('ernestdefoe-trust-levels.config', '{}'),
];
