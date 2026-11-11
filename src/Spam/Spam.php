<?php

namespace ErnestDefoe\TrustLevels\Spam;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Access\Exempt;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Event\Silenced;
use ErnestDefoe\TrustLevels\LevelWriter;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleting as PostDeleting;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Restored;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Posts the community flags, and what happens to their authors.
 *
 * - Enough different members (level 1+ by default) flag a post: it's hidden
 *   at once. With Docket installed, Docket's crowd moderation does the hiding
 *   (weighted by each reporter's level, see DocketWeights) and this stays out.
 * - A post hidden with no one's hand on it (our auto-hide or Docket's) while
 *   flagged silences its author if they're level 0 or 1: they can read, can't
 *   post. Silenced in their first days, with Gatehouse installed, they go back
 *   to its queue instead.
 * - A moderator hiding or deleting a flagged post upholds its flags; enough
 *   upheld flags in 6 months drops the author a level.
 * - A moderator restoring the post lifts the silence it caused.
 */
class Spam
{
    public function __construct(
        private ConnectionInterface $db,
        private Config $config,
        private Exempt $exempt,
        private LevelWriter $writer,
        private ExtensionManager $extensions,
        private Dispatcher $events,
    ) {
    }

    /** flarum/flags Created. */
    public function flagged(object $event): void
    {
        $flag = $event->flag;
        $post = $flag->post;

        if ($flag->type === 'approval' || ! $post instanceof CommentPost || $post->hidden_at || $this->extensions->isEnabled('ernestdefoe-docket')) {
            return;
        }

        $needed = $this->config->spam('flags');
        $author = $post->user;

        if ($needed <= 0 || ! $author || $this->exempt->is($author)) {
            return;
        }

        $flaggers = $this->flaggers($post);
        $min = $this->config->spam('flagger_level');
        $counted = $flaggers ? $this->db->table('users')
            ->whereIn('id', $flaggers)
            ->whereNull('trust_silenced_at')
            ->get(['trust_level', 'trust_level_at', 'joined_at'])
            ->filter(fn ($u) => $this->exempt->effective((int) $u->trust_level, $u->trust_level_at, $u->joined_at) >= $min)
            ->count() : 0;

        if ($counted < $needed) {
            return;
        }

        $post->hide();
        $post->save();

        foreach ($post->releaseEvents() as $e) {
            $this->events->dispatch($e);
        }
    }

    public function hidden(Hidden $event): void
    {
        $post = $event->post;
        $author = $post->user;

        if (! $author || ! $this->flagsOn() || ! $flaggers = $this->flaggers($post)) {
            return;
        }

        if ($event->actor === null) {
            $this->silenceFor($author, $post);
        } elseif ($event->actor->id !== $author->id) {
            $this->uphold($author, $post, $flaggers, $event->actor);
        }
    }

    public function deleting(PostDeleting $event): void
    {
        $post = $event->post;
        $author = $post->user;

        if ($author && $this->flagsOn() && $event->actor->id !== $author->id && ($flaggers = $this->flaggers($post))) {
            $this->uphold($author, $post, $flaggers, $event->actor);
        }
    }

    public function restored(Restored $event): void
    {
        $author = $event->post->user;

        if ($author && $event->actor && $event->actor->id !== $author->id && $author->getAttribute('trust_silenced_at')
            && $this->db->table('trust_events')->where('user_id', $author->id)->where('type', 'silenced')->where('post_id', $event->post->id)->exists()) {
            $this->unsilence($author, $event->actor);
        }
    }

    /** Gatehouse approved someone it was holding: an on-hold from their first days ends with it. */
    public function approvedByGatehouse(object $event): void
    {
        if ($event->user->getAttribute('trust_silenced_at')) {
            $this->unsilence($event->user, $event->actor ?? null);
        }
    }

    /** flarum/suspend Suspended: remembered, since core keeps no history. */
    public function suspended(object $event): void
    {
        $this->writer->log($event->user->id, 'suspended', null, null, $event->actor?->id);
    }

    public function silenceFor(User $author, Post $post): void
    {
        if ($author->getAttribute('trust_silenced_at') || $this->exempt->is($author)
            || $this->exempt->level($author) > $this->config->spam('silence_up_to')) {
            return;
        }

        $now = Carbon::now();
        $this->db->table('users')->where('id', $author->id)->update(['trust_silenced_at' => $now]);
        $author->setRawAttributes(['trust_silenced_at' => $now] + $author->getAttributes(), true);
        $this->writer->log($author->id, 'silenced', null, null, null, $post->id);

        $days = $this->config->spam('gatehouse_days');

        if ($days > 0 && $this->extensions->isEnabled('ernestdefoe-gatehouse') && $author->joined_at && $author->joined_at->gt($now->copy()->subDays($days))) {
            $this->db->table('users')->where('id', $author->id)->update(['gatehouse_status' => 'pending']);
            // Signed out everywhere: Gatehouse holds a pending account at sign-in.
            $this->db->table('access_tokens')->where('user_id', $author->id)->delete();
            $this->writer->log($author->id, 'gatehouse', null, null, null, $post->id);
        }

        $this->events->dispatch(new Silenced($author, $post->id));
    }

    public function unsilence(User $user, ?User $actor): void
    {
        $this->db->table('users')->where('id', $user->id)->update(['trust_silenced_at' => null]);
        $user->setRawAttributes(['trust_silenced_at' => null] + $user->getAttributes(), true);
        $this->writer->log($user->id, 'unsilenced', null, null, $actor?->id);
    }

    /**
     * @param int[] $flaggers
     */
    public function uphold(User $author, Post $post, array $flaggers, ?User $actor): void
    {
        if ($this->db->table('trust_events')->where('user_id', $author->id)->where('type', 'flag_upheld')->where('post_id', $post->id)->exists()) {
            return;
        }

        $this->writer->log($author->id, 'flag_upheld', null, null, $actor?->id, $post->id, ['flaggers' => $flaggers]);

        $drop = $this->config->spam('drop_flags');
        $level = $this->exempt->level($author);

        if ($drop <= 0 || $level === 0 || $author->getAttribute('trust_locked')) {
            return;
        }

        $since = Carbon::now()->subMonths(6);
        $lastDrop = $this->db->table('trust_events')->where('user_id', $author->id)->where('type', 'demoted')
            ->where('created_at', '>=', $since)->where('data', 'like', '%"reason":"flags"%')->max('created_at');

        $upheld = $this->db->table('trust_events')->where('user_id', $author->id)->where('type', 'flag_upheld')
            ->where('created_at', '>=', $lastDrop ?: $since)
            ->when($lastDrop, fn ($q) => $q->where('created_at', '>', $lastDrop))
            ->count();

        if ($upheld >= $drop) {
            $this->writer->set($author, $level - 1, 'flags', $actor);
        }
    }

    /** @return int[] who flagged the post (not for approval, not the author) */
    private function flaggers(Post $post): array
    {
        if (! $this->flagsOn()) {
            return [];
        }

        return $this->db->table('flags')
            ->where('post_id', $post->id)
            ->where('type', '!=', 'approval')
            ->whereNotNull('user_id')
            ->where('user_id', '!=', (int) $post->user_id)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function flagsOn(): bool
    {
        return $this->extensions->isEnabled('flarum-flags');
    }
}
