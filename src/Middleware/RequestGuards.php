<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Detect API and JSON-preferring requests that should skip Plates global mutation.
 */
final class RequestGuards
{
    public static function isApiPath(ServerRequestInterface $request): bool
    {
        $path = self::requestPath($request);

        return str_starts_with($path, '/api/')
            || $path === '/api'
            || str_contains($path, '/api/');
    }

    public static function prefersJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        if ($accept === '' || $accept === '*/*') {
            return false;
        }

        $priorities = self::parseAcceptHeader($accept);
        $jsonPriority = $priorities['application/json'] ?? 0.0;

        if ($jsonPriority <= 0.0) {
            return false;
        }

        $htmlPriority = $priorities['text/html'] ?? 0.0;

        return $jsonPriority > $htmlPriority;
    }

    public static function isNonHtmlRequest(ServerRequestInterface $request): bool
    {
        return self::isApiPath($request) || self::prefersJson($request);
    }

    private static function requestPath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    /**
     * @return array<string, float>
     */
    private static function parseAcceptHeader(string $accept): array
    {
        $priorities = [];

        foreach (explode(',', $accept) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = array_map('trim', explode(';', $part));
            $mediaType = strtolower($segments[0]);
            if ($mediaType === '') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (!str_starts_with(strtolower($parameter), 'q=')) {
                    continue;
                }

                $value = (float) substr($parameter, 2);
                $quality = max(0.0, min(1.0, $value));
            }

            if (!array_key_exists($mediaType, $priorities) || $quality > $priorities[$mediaType]) {
                $priorities[$mediaType] = $quality;
            }
        }

        return $priorities;
    }
}
