<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\LevelWriter;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/trust-levels/users/{id}/spam: "Confirm spam" on a silenced
 * member. Hides their posts (the newest 200), suspends them if Suspend is
 * enabled, and upholds the flags on the post that silenced them. Staff only;
 * never on another member of staff.
 */
class SpamController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private LevelWriter $writer,
        private ExtensionManager $extensions,
        private Dispatcher $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->hasPermission('ernestdefoe-trust-levels.manage'));

        $user = User::query()->find((int) ($request->getAttribute('routeParameters')['id'] ?? 0));

        if (! $user || $user->id === $actor->id || $user->isAdmin() || $user->hasPermission('ernestdefoe-trust-levels.manage')) {
            return Json::error(404, 'not_found');
        }

        $posts = CommentPost::query()->where('user_id', $user->id)->whereNull('hidden_at')->orderByDesc('id')->limit(200)->get();

        foreach ($posts as $post) {
            $post->hide($actor);
            $post->save();

            foreach ($post->releaseEvents() as $event) {
                $this->events->dispatch($event);
            }
        }

        if ($this->extensions->isEnabled('flarum-suspend')) {
            $this->db->table('users')->where('id', $user->id)->update(['suspended_until' => Carbon::now()->addYears(100)]);
            $this->writer->log($user->id, 'suspended', null, null, $actor->id);
        }

        $this->writer->log($user->id, 'spam', null, null, $actor->id);

        return new EmptyResponse(204);
    }
}
