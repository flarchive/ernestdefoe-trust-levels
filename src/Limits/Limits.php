<?php

namespace ErnestDefoe\TrustLevels\Limits;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Access\Exempt;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\Names;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Saving as DiscussionSaving;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * The per-level limits, checked on the server where they can't be skipped.
 * Private messages (flarum/messages) get the same content limits as posts.
 *
 * Links, images and mentions are counted in the parsed post (the formatter's
 * XML), so Markdown, BBCode, autolinks and uploads all count the same way, and
 * edits are checked too. Pace and per-day limits cost one count query, and
 * only for a member whose level has that limit.
 *
 * Every message says what the limit is, how far over they are and the level
 * that lifts it.
 */
class Limits
{
    public function __construct(
        private Config $config,
        private Exempt $exempt,
        private TranslatorInterface $translator,
        private Names $names,
    ) {
    }

    public function postSaving(PostSaving $event): void
    {
        $post = $event->post;
        $actor = $event->actor;

        if (! $post instanceof CommentPost || $actor->isGuest() || $post->user_id !== $actor->id || $this->exempt->is($actor)) {
            return;
        }

        $level = $this->exempt->level($actor);

        if (! $post->exists && ($seconds = $this->config->limit('post_seconds', $level))) {
            $last = Post::query()->where('user_id', $actor->id)->where('type', 'comment')->max('created_at');

            if ($last && Carbon::parse($last)->addSeconds($seconds)->isFuture()) {
                $this->fail('post_seconds', $level, $seconds, (int) ceil(Carbon::parse($last)->addSeconds($seconds)->diffInSeconds(Carbon::now(), true)));
            }
        }

        if ($post->exists && ! $post->isDirty('content')) {
            return;
        }

        $this->checkContent((string) $post->parsed_content, $level);

    }

    public function discussionSaving(DiscussionSaving $event): void
    {
        $actor = $event->actor;

        if ($event->discussion->exists || $actor->isGuest() || $this->exempt->is($actor)) {
            return;
        }

        $level = $this->exempt->level($actor);
        $max = $this->config->limit('discussions_day', $level);

        if ($max !== null && Discussion::query()->where('user_id', $actor->id)->where('created_at', '>=', Carbon::now()->subDay())->count() >= $max) {
            $this->fail('discussions_day', $level, $max);
        }
    }

    /** flarum/messages: a new private message. */
    public function messageCreating(object $event): void
    {
        $message = $event->message;
        $actor = $message->user_id ? User::query()->find($message->user_id) : null;

        if (! $actor || $this->exempt->is($actor)) {
            return;
        }

        if (Exempt::silenced($actor)) {
            throw new ValidationException(['content' => $this->translator->trans('ernestdefoe-trust-levels.api.silenced')]);
        }

        $level = $this->exempt->level($actor);
        $max = $this->config->limit('messages_day', $level);

        if ($max === 0) {
            $this->fail('messages_day', $level, $max);
        }

        if ($max !== null && $message::query()->where('user_id', $actor->id)->where('created_at', '>=', Carbon::now()->subDay())->count() >= $max) {
            $this->fail('messages_day', $level, $max);
        }

        // Links, images and mentions: the same limits as posts (the content is parsed by now).
        $this->checkContent((string) $message->parsed_content, $level);
    }

    private function checkContent(string $xml, int $level): void
    {
        $counts = self::count($xml);

        foreach (['links', 'images', 'mentions'] as $limit) {
            $max = $this->config->limit($limit, $level);

            if ($max !== null && $counts[$limit] > $max) {
                $this->fail($limit, $level, $max, $counts[$limit] - $max);
            }
        }
    }

    /**
     * Links, images and mentions in a post's parsed XML.
     *
     * @return array{links: int, images: int, mentions: int}
     */
    public static function count(string $xml): array
    {
        if ($xml === '' || $xml[0] !== '<') {
            return ['links' => 0, 'images' => 0, 'mentions' => 0];
        }

        // An image with a link around it is one image, not a link too.
        $images = preg_match_all('/<(IMG|UPL-IMAGE-PREVIEW|UPL-IMAGE)[\s>]/', $xml);
        $links = preg_match_all('/<(URL|LINK|EMAIL)[\s>]/', $xml) - preg_match_all('/<URL[^>]*>(?:<[se]>[^<]*<\/[se]>)?<IMG[\s>]/', $xml);

        return [
            'links' => max(0, $links),
            'images' => $images,
            'mentions' => preg_match_all('/<USERMENTION[\s>]/', $xml),
        ];
    }

    private function fail(string $limit, int $level, int $max, int $over = 0): never
    {
        $lifted = $this->config->liftedAt($limit, $level);

        $message = $this->translator->trans('ernestdefoe-trust-levels.api.limits.'.$limit, [
            '{level}' => $this->names->name($level),
            '{max}' => $max,
            '{over}' => $over,
        ]);

        if ($lifted !== null) {
            $message .= ' '.$this->translator->trans('ernestdefoe-trust-levels.api.limits.lifted', ['{level}' => $this->names->name($lifted)]);
        }

        throw new ValidationException(['content' => $message]);
    }
}
