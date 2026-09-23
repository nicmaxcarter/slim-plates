<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Detect API and JSON-preferring requests that should skip Plates global mutation.
 */
final class RequestGuards
{
    public const FIXI_CURRENT_URL_HEADER = 'FX-Current-URL';

    /** Identify the conventional API path segment. */
    public static function isApiPath(ServerRequestInterface $request): bool
    {
        $path = self::requestPath($request);

        return str_starts_with($path, '/api/')
            || $path === '/api'
            || str_contains($path, '/api/');
    }

    /** Compare JSON and HTML acceptability, with specific ranges taking precedence. */
    public static function prefersJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        if ($accept === '' || $accept === '*/*') {
            return false;
        }

        $priorities = self::parseAcceptHeader($accept);
        $jsonPriority = $priorities['application/json']
            ?? $priorities['application/*']
            ?? $priorities['*/*']
            ?? 0.0;

        if ($jsonPriority <= 0.0) {
            return false;
        }

        $htmlPriority = $priorities['text/html']
            ?? $priorities['text/*']
            ?? $priorities['*/*']
            ?? 0.0;

        return $jsonPriority > $htmlPriority;
    }

    /**
     * Whether the request was issued by fixi-js (hypermedia partial update).
     */
    public static function isFixiRequest(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('FX-Request') === 'true';
    }

    /**
     * Document URL from a fixi request (pathname + search of the page the user was viewing).
     */
    public static function fixiCurrentUrl(ServerRequestInterface $request): ?string
    {
        return self::safeInternalRedirectPath($request->getHeaderLine(self::FIXI_CURRENT_URL_HEADER));
    }

    /**
     * Accept only same-origin relative paths suitable for post-login redirect.
     */
    public static function safeInternalRedirectPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return null;
        }

        if (!self::isSafeRelativePath($path)) {
            return null;
        }

        $pathComponent = parse_url($path, PHP_URL_PATH);
        if (!is_string($pathComponent) || $pathComponent === '' || $pathComponent === '/') {
            return null;
        }

        if (!self::isSafeRelativePath($pathComponent)) {
            return null;
        }

        if (self::containsEncodedPathSeparators($pathComponent)) {
            return null;
        }

        return $path;
    }

    /** Reject external URL forms and unsafe characters before parsing. */
    private static function isSafeRelativePath(string $path): bool
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        if (str_contains($path, '\\') || str_contains($path, '@')) {
            return false;
        }

        return preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    /** Detect separators that a later URL decoder could reinterpret. */
    private static function containsEncodedPathSeparators(string $path): bool
    {
        return preg_match('/%2[fF]|%5[cC]/', $path) === 1;
    }

    /** Apply the conventional path and Accept-header guards to layout context. */
    public static function isNonHtmlRequest(ServerRequestInterface $request): bool
    {
        return self::isApiPath($request) || self::prefersJson($request);
    }

    /** Normalize a missing or relative request path for segment matching. */
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
