<?php

namespace ErnestDefoe\TrustLevels\Job;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\LevelWriter;
use ErnestDefoe\TrustLevels\Stats\Stats;
use Flarum\Post\CommentPost;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

/**
 * "Confirm spam" on a member who is on hold: hides every visible post of
 * theirs, 100 at a time, with core's Hidden events (which uphold the reports on
 * them, see Spam::hidden), then suspends them if Suspend is enabled.
 */
class ConfirmSpam extends AbstractJob
{
    public function __construct(private int $userId, private int $actorId)
    {
    }

    public function handle(LevelWriter $writer, Stats $stats, Dispatcher $events): void
    {
        $user = User::query()->find($this->userId);
        $actor = User::query()->find($this->actorId);

        if (! $user || ! $actor) {
            return;
        }

        CommentPost::query()->where('user_id', $user->id)->whereNull('hidden_at')
            ->chunkById(100, function (Collection $posts) use ($actor, $events) {
                foreach ($posts as $post) {
                    $post->hide($actor);
                    $post->save();

                    foreach ($post->releaseEvents() as $event) {
                        $events->dispatch($event);
                    }
                }
            });

        if ($stats->suspendOn()) {
            $user->setAttribute('suspended_until', Carbon::now()->addYears(100))->save();
            $writer->log($user->id, 'suspended', null, null, $actor->id);
        }

        $writer->log($user->id, 'spam', null, null, $actor->id);
    }
}
