<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Job\ConfirmSpam;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/trust-levels/users/{id}/spam: "Confirm spam" on a silenced
 * member. Queues ConfirmSpam, which hides all their posts and suspends them
 * (see there), and answers 202 straight away. Staff only; never on another
 * member of staff.
 */
class SpamController implements RequestHandlerInterface
{
    public function __construct(private Queue $queue)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->hasPermission('ernestdefoe-trust-levels.manage'));

        $user = User::query()->find((int) ($request->getAttribute('routeParameters')['id'] ?? 0));

        if (! $user || $user->id === $actor->id || $user->isAdmin() || $user->hasPermission('ernestdefoe-trust-levels.manage')) {
            return Json::error(404, 'not_found');
        }

        $this->queue->push(new ConfirmSpam($user->id, $actor->id));

        return new EmptyResponse(202);
    }
}
