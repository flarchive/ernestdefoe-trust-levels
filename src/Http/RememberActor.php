<?php

namespace ErnestDefoe\TrustLevels\Http;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Keeps the request's actor where the read listener can reach it, since core's
 * `UserRead` event carries only the read state. Saves a query per read save.
 */
class RememberActor implements MiddlewareInterface
{
    public ?User $actor = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->actor = RequestUtil::getActor($request);

        return $handler->handle($request);
    }
}
