<?php

namespace ErnestDefoe\TrustLevels\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/trust-levels/dashboard: the admin dashboard (see Dashboard). Admins only. */
class DashboardController implements RequestHandlerInterface
{
    public function __construct(private Dashboard $dashboard)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => $this->dashboard->data() + ['busy' => false, 'queued' => false]]);
    }
}
