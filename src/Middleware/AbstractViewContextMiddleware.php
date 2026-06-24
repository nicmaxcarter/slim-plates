<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Middleware;

use League\Plates\Engine;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Adds request-scoped Plates globals for HTML page requests only.
 */
abstract class AbstractViewContextMiddleware
{
    private Engine $plates;

    public function __construct(Engine $plates)
    {
        $this->plates = $plates;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if (!RequestGuards::isNonHtmlRequest($request)) {
            $this->plates->addData($this->context($request));
        }

        return $handler->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function context(Request $request): array;
}
