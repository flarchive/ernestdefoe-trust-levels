<?php

namespace ErnestDefoe\TrustLevels\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/trust-levels/users/{id}: the progress tab. The member themselves
 * or staff with the manage permission; anyone else gets a 404, so it can't be
 * used to learn someone's level (a JSON:API user include would leak it).
 */
class MemberController implements RequestHandlerInterface
{
    public function __construct(private Progress $progress)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $user = User::query()->find((int) ($request->getAttribute('routeParameters')['id'] ?? 0));
        $staff = $actor->hasPermission('ernestdefoe-trust-levels.manage');

        if (! $user || (! $staff && $actor->id !== $user->id)) {
            return Json::error(404, 'not_found');
        }

        return new JsonResponse(['data' => $this->progress->for($user, $staff)]);
    }
}
