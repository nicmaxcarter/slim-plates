<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Tests;

use NicmaxCarter\SlimPlates\Middleware\RequestGuards;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Protect login returns and HTML/JSON request classification.
 */
final class RequestGuardsTest extends TestCase
{
    /**
     * Includes the redirect regressions originally covered in minister-manager.
     *
     * @return array<string, array{string, string|null}>
     */
    public static function redirectPaths(): array
    {
        return [
            'page' => ['/dash', '/dash'],
            'query' => ['/servers?page=2', '/servers?page=2'],
            'encoded query' => ['/search?q=%2f%2fevil', '/search?q=%2f%2fevil'],
            'trimmed' => ['  /settings  ', '/settings'],
            'empty' => ['', null],
            'whitespace' => ['   ', null],
            'root' => ['/', null],
            'root with query' => ['/?page=2', null],
            'relative' => ['servers', null],
            'protocol relative' => ['//evil.example', null],
            'absolute' => ['https://evil.example/path', null],
            'backslash prefix' => ['/\\evil.example', null],
            'backslash' => ['/servers\\admin', null],
            'encoded slash' => ['/servers%2fadmin', null],
            'encoded upper slash' => ['/%2Fevil.example', null],
            'encoded backslash' => ['/servers%5cadmin', null],
            'encoded upper backslash' => ['/%5Cevil.example', null],
            'userinfo' => ['/user@evil.example', null],
            'newline' => ["/servers\nadmin", null],
            'header injection' => ["/servers\r\nLocation: //evil.example", null],
            'tab' => ["/servers\tadmin", null],
            'null byte' => ["/servers\0admin", null],
        ];
    }

    /**
     * Exercise both the shared validator and the untrusted browser header.
     */
    #[DataProvider('redirectPaths')]
    public function testRedirectPaths(string $input, ?string $expected): void
    {
        self::assertSame($expected, RequestGuards::safeInternalRedirectPath($input));

        if (preg_match('/[\x00-\x1F\x7F]/', $input) === 1) {
            return; // PSR-7 rejects these before the request reaches the guard.
        }

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/snippet')
            ->withHeader(RequestGuards::FIXI_CURRENT_URL_HEADER, $input);
        self::assertSame($expected, RequestGuards::fixiCurrentUrl($request));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function acceptHeaders(): array
    {
        return [
            'absent' => ['', false],
            'wildcard' => ['*/*', false],
            'html' => ['text/html', false],
            'json' => ['application/json', true],
            'equal' => ['application/json, text/html', false],
            'html preferred' => ['application/json;q=0.5, text/html', false],
            'json preferred' => ['application/json, text/html;q=0.5', true],
            'json refused' => ['application/json;q=0', false],
            'case insensitive' => ['APPLICATION/JSON;Q=0.8,text/html;q=0.2', true],
            'duplicates' => ['application/json;q=0.1,application/json;q=0.9,text/html;q=0.5', true],
            'html wildcard' => ['application/json;q=0.5,text/*', false],
            'general wildcard' => ['application/json;q=0.5,*/*', false],
            'application wildcard' => ['application/*,text/html;q=0.5', true],
            'specific html wins' => ['application/json;q=0.5,text/html;q=0.1,*/*', true],
            'specific json refusal' => ['application/json;q=0,application/*', false],
        ];
    }

    /**
     * Wildcards must not make an HTML-preferring request look like JSON.
     */
    #[DataProvider('acceptHeaders')]
    public function testJsonPreference(string $accept, bool $expected): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/dash')
            ->withHeader('Accept', $accept);
        self::assertSame($expected, RequestGuards::prefersJson($request));
        self::assertSame($expected, RequestGuards::isNonHtmlRequest($request));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function requestPaths(): array
    {
        return [
            'api root' => ['/api', true],
            'api endpoint' => ['/api/health', true],
            'mounted api' => ['/backend/api/health', true],
            'similar prefix' => ['/apiculture', false],
            'similar segment' => ['/backend/apiculture/health', false],
            'page' => ['/dash', false],
            'fragment' => ['/snippet/routes/table', false],
        ];
    }

    /**
     * Keep ordinary Fixi HTML fragments eligible for layout context.
     */
    #[DataProvider('requestPaths')]
    public function testRequestClassification(string $path, bool $expected): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        self::assertFalse(RequestGuards::isFixiRequest($request));
        self::assertNull(RequestGuards::fixiCurrentUrl($request));
        $request = $request->withHeader('FX-Request', 'true');
        self::assertTrue(RequestGuards::isFixiRequest($request));
        self::assertSame($expected, RequestGuards::isApiPath($request));
        self::assertSame($expected, RequestGuards::isNonHtmlRequest($request));
    }
}
