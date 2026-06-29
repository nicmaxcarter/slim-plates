<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Bootstrap;

use League\Plates\Engine;
use NicmaxCarter\SlimPlates\AssetHelper;

/**
 * Register Plates template functions provided by the slim-plates package.
 */
class PlatesBootstrap
{
    /**
     * Register $this->asset('bundle.js') using AssetHelper.
     */
    public static function registerAsset(Engine $plates, AssetHelper $helper): void
    {
        $plates->registerFunction('asset', function (string $logicalName) use ($helper): string {
            return $helper->url($logicalName);
        });
    }

    /**
     * Register $this->iconsSvg() using AssetHelper::iconsUrl().
     */
    public static function registerIconsSvg(Engine $plates, AssetHelper $helper): void
    {
        $plates->registerFunction('iconsSvg', function () use ($helper): string {
            return $helper->iconsUrl();
        });
    }

    /**
     * Register the bridge views folder for shared partials (e.g. flash-toasts).
     */
    public static function registerFlashToasts(Engine $plates): void
    {
        $viewsPath = dirname(__DIR__, 2) . '/views';
        $plates->addFolder('bridge', $viewsPath);
    }
}
