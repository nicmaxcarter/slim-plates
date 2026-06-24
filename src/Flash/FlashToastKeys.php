<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Flash;

/**
 * Slim Flash message keys used by redirectWithToast() and flash-toasts.phtml.
 */
final class FlashToastKeys
{
    public const SUCCESS = 'success';

    public const ERROR = 'error';

    public const INFO = 'info';

    public const WARNING = 'warning';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SUCCESS,
            self::ERROR,
            self::INFO,
            self::WARNING,
        ];
    }
}
