<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates;

use League\Plates\Engine;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Interfaces\RouteParserInterface;

/**
 * Plates View adapter for Slim Framework
 *
 * Wraps League\Plates\Engine to provide Slim-compatible render() method
 * that accepts PSR-7 Response objects and returns Response objects.
 */
class PlatesView
{
    /** @var list<string> */
    private const LAYOUT_DATA_KEYS = [
        'pageTitle',
        'searchTerm',
    ];

    private Engine $plates;

    /**
     * @param Engine $plates The Plates Engine instance
     */
    public function __construct(Engine $plates)
    {
        $this->plates = $plates;
    }

    /**
     * Render a template into a PSR-7 Response
     *
     * @param Response $response The PSR-7 Response object
     * @param string $template Template name (without .phtml extension)
     * @param array<string, mixed> $data Template variables
     * @return Response Response with rendered template in body
     */
    public function render(Response $response, string $template, array $data = []): Response
    {
        $this->mergeLayoutData($data);
        $html = $this->plates->render($template, $data);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Add data to all templates (globals)
     *
     * @param array<string, mixed> $data Data to add
     */
    public function addData(array $data): void
    {
        $this->plates->addData($data);
    }

    /**
     * Fetch a template as a string (for use in error handlers, etc.)
     *
     * @param string $template Template name (without .phtml extension)
     * @param array<string, mixed> $data Template variables
     */
    public function fetch(string $template, array $data = []): string
    {
        $this->mergeLayoutData($data);
        return $this->plates->render($template, $data);
    }

    /**
     * Promote layout-scoped render params to Plates globals.
     *
     * Plates layouts only receive engine globals and explicit layout() data,
     * not the child template's render params. Keys are reset to null on each
     * render/fetch so a later call cannot inherit values from an earlier one.
     *
     * @param array<string, mixed> $data
     */
    private function mergeLayoutData(array $data): void
    {
        $layoutData = [];

        foreach (self::LAYOUT_DATA_KEYS as $key) {
            $layoutData[$key] = array_key_exists($key, $data) ? $data[$key] : null;
        }

        if (array_key_exists('active', $data)) {
            $layoutData['active'] = $data['active'];
        }

        $this->plates->addData($layoutData);
    }

    /**
     * Get the underlying Plates Engine instance
     */
    public function getEngine(): Engine
    {
        return $this->plates;
    }

    /**
     * Register url_for function with router
     */
    public function registerUrlFor(RouteParserInterface $router): void
    {
        $this->plates->registerFunction('url_for', function ($routeName, $data = [], $queryParams = []) use ($router) {
            try {
                return $router->urlFor($routeName, $data, $queryParams);
            } catch (\Exception $e) {
                return '#';
            }
        });
    }
}
