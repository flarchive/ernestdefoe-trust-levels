<?php

namespace ErnestDefoe\TrustLevels\Api;

use ErnestDefoe\TrustLevels\Evaluator;
use ErnestDefoe\TrustLevels\Job\RunCheck;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/trust-levels/run: "Run now" (no cron, or impatience). Queues the
 * check (202) unless one is already running (200, busy), and answers with the
 * dashboard; the page polls it until lastRun moves. Admins only.
 */
class RunController implements RequestHandlerInterface
{
    public function __construct(private Dashboard $dashboard, private Evaluator $evaluator, private Queue $queue)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $busy = $this->evaluator->running();

        if (! $busy) {
            $this->queue->push(new RunCheck());
        }

        return new JsonResponse(['data' => $this->dashboard->data() + ['busy' => $busy, 'queued' => ! $busy]], $busy ? 200 : 202);
    }
}
