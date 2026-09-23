<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Tests;

use InvalidArgumentException;
use JsonException;
use League\Plates\Engine;
use NicmaxCarter\SlimPlates\Bootstrap\PlatesBootstrap;
use NicmaxCarter\SlimPlates\Flash\FlashToastKeys;
use NicmaxCarter\SlimPlates\PlatesView;
use NicmaxCarter\SlimPlates\Responses;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Flash\Messages;
use Slim\Psr7\Response;

/** Protect the PHP side of the app-owned browser event contract. */
final class ResponsesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function toastTypes(): array
    {
        return [
            'success' => ['success', 'notifySuccess'],
            'error' => ['error', 'notifyError'],
            'info' => ['info', 'notifyInfo'],
            'warning' => ['warning', 'notifyWarning'],
        ];
    }

    /** Preserve type-before-message ordering, response body, and status. */
    #[DataProvider('toastTypes')]
    public function testToasts(string $type, string $event): void
    {
        $original = new Response(422);
        $original->getBody()->write('Keep this content');
        $response = Responses::withToast($original, $type, 'Message');
        self::assertSame([$event => 'Message'], json_decode(
            $response->getHeaderLine(Responses::HEADER_TRIGGER),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Keep this content', (string) $response->getBody());
        self::assertFalse($original->hasHeader(Responses::HEADER_TRIGGER));
    }

    /** Multiple events must share one header rather than overwrite each other. */
    public function testModalAndReloadEvents(): void
    {
        $events = ['launchModal' => '', 'notifySuccess' => 'Saved', 'reload-table' => ''];
        $response = Responses::withTriggers(new Response(), $events);
        self::assertSame($events, json_decode($response->getHeaderLine(Responses::HEADER_TRIGGER), true));
        $modal = Responses::launchModalWithToast(new Response(), 'success', 'Saved');
        self::assertSame(
            ['launchModal' => '', 'notifySuccess' => 'Saved'],
            json_decode($modal->getHeaderLine(Responses::HEADER_TRIGGER), true),
        );
    }

    /** A bad character must not silently erase all notification/reload events. */
    public function testInvalidUtf8IsReplaced(): void
    {
        $encoded = Responses::encodeTriggers(['notifyError' => "Bad \xFF", 'reload-table' => '']);
        self::assertSame(
            ['notifyError' => "Bad \u{FFFD}", 'reload-table' => ''],
            json_decode($encoded, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /** Unsupported event values must fail explicitly, not emit an empty header. */
    public function testNonSerializableEventsFail(): void
    {
        $this->expectException(JsonException::class);
        Responses::encodeTriggers(['bad' => [INF]]);
    }

    /** Toast-only responses keep the requested failure status; JS controls swapping. */
    public function testToastOnly(): void
    {
        $response = Responses::toastOnly(new Response(), 'error', 'Denied', 403);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
        self::assertSame(
            ['notifyError' => 'Denied'],
            json_decode($response->getHeaderLine(Responses::HEADER_TRIGGER), true),
        );
    }

    /** Reject typos instead of reporting false success. */
    public function testInvalidToastType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Responses::withToast(new Response(), 'sucess', 'Saved');
    }

    /** Fixi needs a redirect header rather than a fetch-followed 302. */
    public function testFixiRedirect(): void
    {
        $response = Responses::fixiRedirect(new Response(), '/login?return=1');
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('/login?return=1', $response->getHeaderLine(Responses::HEADER_REDIRECT));
        self::assertFalse($response->hasHeader('Location'));
    }

    /** Never send an external redirect supplied through a browser header. */
    public function testUnsafeFixiRedirect(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Responses::fixiRedirect(new Response(), '//evil.example');
    }

    /** Use real flash storage across requests and the distributed escaped partial. */
    public function testRedirectFlashRoundTrip(): void
    {
        $storage = [];
        $flash = new Messages($storage);
        foreach (FlashToastKeys::all() as $type) {
            $response = Responses::redirectWithToast(new Response(), '/dash', '<script>"&', $type, $flash);
            self::assertSame(302, $response->getStatusCode());
            self::assertSame('/dash', $response->getHeaderLine('Location'));
        }
        $nextRequest = new Messages($storage);
        $engine = new Engine(null, 'phtml');
        PlatesBootstrap::registerFlashToasts($engine);
        $html = (new PlatesView($engine))->fetch('bridge::partials/flash-toasts', ['flash' => $nextRequest]);
        foreach (FlashToastKeys::all() as $type) {
            self::assertStringContainsString('name="flash-' . $type . '"', $html);
        }
        self::assertStringContainsString('content="&lt;script&gt;&quot;&amp;"', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame([], (new Messages($storage))->getMessages());
    }

    /** Invalid flash types must not enqueue a success or mutate storage. */
    public function testInvalidFlashTypeDoesNotWrite(): void
    {
        $storage = [];
        $flash = new Messages($storage);
        try {
            Responses::redirectWithToast(new Response(), '/dash', 'Saved', 'invalid', $flash);
            self::fail('An invalid toast type was accepted');
        } catch (InvalidArgumentException) {
            self::assertSame([], (new Messages($storage))->getMessages());
        }
    }
}
