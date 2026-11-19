<?php

namespace ErnestDefoe\TrustLevels\Api;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

final class Json
{
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        if (! is_array($body) || ! $body) {
            $body = json_decode((string) $request->getBody(), true);
        }

        return is_array($body) ? $body : [];
    }

    public static function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'code' => $code]]], $status);
    }
}
