<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates;

/**
 * Resolve logical asset names to public URLs using an optional build manifest.
 *
 * Production builds can emit manifest.json mapping logical names (bundle.js)
 * to content-hashed filenames (bundle.a1b2c3d4.js). When no manifest exists,
 * logical names are used as-is so dev and static-only apps keep working.
 */
class AssetHelper
{
    private readonly string $normalizedBaseUrl;

    /** @var array<string, string>|null */
    private ?array $manifest = null;

    private bool $manifestLoaded = false;

    /**
     * @param string|null $manifestPath Absolute path to manifest.json, or null to disable
     * @param string $baseUrl URL prefix, e.g. /assets/ or https://cdn.example.com/assets/
     * @param string|null $queryVersion Optional ?v= value for assets not resolved via manifest
     */
    public function __construct(
        private readonly ?string $manifestPath = null,
        string $baseUrl = '/assets/',
        private readonly ?string $queryVersion = null,
    ) {
        $this->normalizedBaseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    /**
     * Resolve a logical asset name to a public URL.
     */
    public function url(string $logicalName): string
    {
        $logicalName = $this->sanitizeLogicalName($logicalName);
        $filename = $this->resolveFilename($logicalName);
        $url = $this->normalizedBaseUrl . $filename;

        if ($this->shouldAppendQueryVersion($logicalName)) {
            $url .= '?v=' . rawurlencode($this->queryVersion ?? '');
        }

        return $url;
    }

    private function sanitizeLogicalName(string $logicalName): string
    {
        $logicalName = str_replace('\\', '/', $logicalName);

        return basename($logicalName);
    }

    private function resolveFilename(string $logicalName): string
    {
        $manifest = $this->getManifest();

        if (isset($manifest[$logicalName])) {
            return $this->sanitizeManifestValue($manifest[$logicalName]);
        }

        return $logicalName;
    }

    private function shouldAppendQueryVersion(string $logicalName): bool
    {
        if ($this->queryVersion === null || $this->queryVersion === '') {
            return false;
        }

        $manifest = $this->getManifest();

        return !isset($manifest[$logicalName]);
    }

    private function sanitizeManifestValue(string $value): string
    {
        $value = str_replace('\\', '/', $value);

        return basename($value);
    }

    /**
     * @return array<string, string>
     */
    private function getManifest(): array
    {
        if ($this->manifestLoaded) {
            return $this->manifest ?? [];
        }

        $this->manifestLoaded = true;
        $this->manifest = $this->loadManifest();

        return $this->manifest;
    }

    /**
     * @return array<string, string>
     */
    private function loadManifest(): array
    {
        if ($this->manifestPath === null || $this->manifestPath === '') {
            return [];
        }

        if (!is_readable($this->manifestPath)) {
            return [];
        }

        $contents = file_get_contents($this->manifestPath);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $manifest = [];

        foreach ($decoded as $logicalName => $filename) {
            if (!is_string($logicalName) || !is_string($filename)) {
                continue;
            }

            $logicalName = $this->sanitizeLogicalName($logicalName);
            if ($logicalName === '') {
                continue;
            }

            $manifest[$logicalName] = $this->sanitizeManifestValue($filename);
        }

        return $manifest;
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        if ($baseUrl === '') {
            return '/';
        }

        return rtrim($baseUrl, '/') . '/';
    }
}
