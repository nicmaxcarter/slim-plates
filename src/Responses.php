<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates;

use InvalidArgumentException;
use NicmaxCarter\SlimPlates\Flash\FlashToastKeys;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Flash\Messages;

/**
 * Fixi response helpers using HX-* header names consumed by app JS bridges (fx:swapped).
 */
final class Responses
{
    public const HEADER_TRIGGER = 'HX-Trigger-After-Settle';

    public const HEADER_REDIRECT = 'HX-Redirect';

    /**
     * @param array<string, string|int|bool|array<mixed>> $triggers
     */
    public static function withTriggers(Response $response, array $triggers): Response
    {
        return $response->withHeader(self::HEADER_TRIGGER, self::encodeTriggers($triggers));
    }

    /**
     * @param array<string, string|int|bool|array<mixed>> $triggers
     */
    public static function encodeTriggers(array $triggers): string
    {
        $encoded = json_encode($triggers);

        return $encoded !== false ? $encoded : '';
    }

    /**
     * @param array<mixed>|string|null $message
     */
    public static function withToast(
        Response $response,
        string $type,
        array|string|null $message = null,
    ): Response {
        $eventName = self::toastEventName($type);

        return self::withTriggers($response, [$eventName => $message ?? '']);
    }

    public static function launchModal(Response $response): Response
    {
        return self::withTriggers($response, ['launchModal' => '']);
    }

    public static function launchModalWithToast(
        Response $response,
        string $type,
        string $message,
    ): Response {
        $eventName = self::toastEventName($type);

        return self::withTriggers($response, [
            'launchModal' => '',
            $eventName => $message,
        ]);
    }

    /**
     * Toast-only JSON response (no body swap). Used for success/error fixi actions.
     *
     * @param array<mixed>|string|null $message
     */
    public static function toastOnly(
        Response $response,
        string $type,
        array|string|null $message = null,
        int $statusCode = 200,
    ): Response {
        $response = self::withToast($response, $type, $message);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * Set a pre-encoded trigger header value (plain event name or JSON string).
     */
    public static function withTriggerValue(Response $response, string $triggerValue): Response
    {
        return $response->withHeader(self::HEADER_TRIGGER, $triggerValue);
    }

    /**
     * Full-page redirect for unauthenticated fixi requests (no HTML swap).
     *
     * Consumed by app JS on `fx:after` before fixi applies the response body.
     */
    public static function fixiRedirect(
        Response $response,
        string $url,
        int $statusCode = 401,
    ): Response {
        return $response
            ->withStatus($statusCode)
            ->withHeader(self::HEADER_REDIRECT, $url);
    }

    /**
     * Redirect after POST (PRG) and show a toast on the next full page load via Slim Flash.
     */
    public static function redirectWithToast(
        Response $response,
        string $url,
        string $message,
        string $type,
        Messages $flash,
    ): Response {
        $flashKey = match ($type) {
            FlashToastKeys::ERROR => FlashToastKeys::ERROR,
            FlashToastKeys::INFO => FlashToastKeys::INFO,
            FlashToastKeys::WARNING => FlashToastKeys::WARNING,
            FlashToastKeys::SUCCESS => FlashToastKeys::SUCCESS,
            default => throw new InvalidArgumentException(
                'Toast type must be success, error, info, or warning'
            ),
        };

        $flash->addMessage($flashKey, $message);

        return $response
            ->withStatus(302)
            ->withHeader('Location', $url);
    }

    private static function toastEventName(string $type): string
    {
        return match ($type) {
            'success' => 'notifySuccess',
            'error' => 'notifyError',
            'info' => 'notifyInfo',
            'warning' => 'notifyWarning',
            default => throw new InvalidArgumentException(
                'Toast type must be success, error, info, or warning'
            ),
        };
    }
}
