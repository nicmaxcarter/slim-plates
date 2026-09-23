<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Tests;

use NicmaxCarter\SlimPlates\AssetHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercise real manifests and files rather than mocking filesystem behavior.
 */
final class AssetHelperTest extends TestCase
{
    private string $directory;

    /** Create an isolated asset directory. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/slim-plates-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    /** Remove only files created by this test. */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    /**
     * @return array<string, array{string|null, bool, string}>
     */
    public static function manifests(): array
    {
        return [
            'missing manifest' => [null, false, 'bundle.js?v=release%202'],
            'invalid json' => ['{', false, 'bundle.js?v=release%202'],
            'non-object json' => ['null', false, 'bundle.js?v=release%202'],
            'invalid entry' => ['{"bundle.js":42}', false, 'bundle.js?v=release%202'],
            'empty entry' => ['{"bundle.js":""}', false, 'bundle.js?v=release%202'],
            'missing hash' => ['{"bundle.js":"bundle.123.js"}', false, 'bundle.js?v=release%202'],
            'existing hash' => ['{"bundle.js":"bundle.123.js"}', true, 'bundle.123.js'],
            'stable entry' => ['{"bundle.js":"bundle.js"}', false, 'bundle.js?v=release%202'],
        ];
    }

    /**
     * Falling back to a stable asset must also apply its cache version.
     */
    #[DataProvider('manifests')]
    public function testManifestResolution(?string $manifest, bool $hashedFile, string $expected): void
    {
        $manifestPath = $this->directory . '/manifest.json';
        if ($manifest !== null) {
            file_put_contents($manifestPath, $manifest);
        }
        if ($hashedFile) {
            file_put_contents($this->directory . '/bundle.123.js', '');
        }
        $helper = new AssetHelper($manifestPath, '/assets', $this->directory, 'release 2');
        self::assertSame('/assets/' . $expected, $helper->url('bundle.js'));
    }

    /** CDN-only resolution must not require a local copy of the hash. */
    public function testCdnAssetsAndIcons(): void
    {
        $manifestPath = $this->directory . '/manifest.json';
        file_put_contents($manifestPath, '{"icons.svg":"icons.123.svg"}');
        $helper = new AssetHelper($manifestPath, 'https://cdn.example/assets/', null, '2');
        self::assertSame('https://cdn.example/assets/icons.123.svg', $helper->iconsUrl());
        self::assertSame('https://cdn.example/assets/style.css?v=2', $helper->url('style.css'));
    }

    /** Empty manifest values must not resolve to the CDN directory itself. */
    public function testEmptyCdnEntryFallsBack(): void
    {
        $manifestPath = $this->directory . '/manifest.json';
        file_put_contents($manifestPath, '{"bundle.js":""}');
        $helper = new AssetHelper($manifestPath, 'https://cdn.example/assets');
        self::assertSame('https://cdn.example/assets/bundle.js', $helper->url('bundle.js'));
    }
}
