<?php

declare(strict_types=1);

namespace NicmaxCarter\SlimPlates\Tests;

use League\Plates\Engine;
use NicmaxCarter\SlimPlates\AssetHelper;
use NicmaxCarter\SlimPlates\Bootstrap\PlatesBootstrap;
use NicmaxCarter\SlimPlates\Middleware\AbstractViewContextMiddleware;
use NicmaxCarter\SlimPlates\PlatesView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Exercise actual Plates layouts, helper registration, and request context. */
final class PlatesViewTest extends TestCase
{
    private string $directory;
    private PlatesView $view;

    /** Create a layout and child template with observable global values. */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/slim-plates-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/layout.phtml', <<<'PHP'
<title><?=$this->e($pageTitle ?? "")?></title>|<?=$this->e($searchTerm ?? "")?>|<?=$this->e($active ?? "")?>|<?=$this->e($company ?? "")?>|<?=$this->section("content")?>
PHP);
        file_put_contents($this->directory . '/page.phtml', <<<'PHP'
<?php $this->layout("layout") ?><?=$this->e($value ?? "")?>
PHP);
        $this->view = new PlatesView(new Engine($this->directory, 'phtml'));
    }

    /** Remove only the isolated templates created by this test. */
    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    /** Omitted render-specific keys reset; request globals remain available. */
    public function testRepeatedRenderingResetsLayoutData(): void
    {
        $this->view->addData(['active' => 'routes', 'company' => 'Company A']);
        $response = $this->view->render(new Response(422), 'page', [
            'pageTitle' => 'Edit <route>',
            'searchTerm' => 'truck',
            'value' => '<input>',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            '<title>Edit &lt;route&gt;</title>|truck|routes|Company A|&lt;input&gt;',
            (string) $response->getBody(),
        );
        $next = $this->view->render(new Response(), 'page');
        self::assertSame('<title></title>||routes|Company A|', (string) $next->getBody());
        self::assertSame(
            '<title>Next</title>||edit|Company A|',
            $this->view->fetch('page', ['pageTitle' => 'Next', 'active' => 'edit']),
        );
        self::assertSame('<title></title>||edit|Company A|', $this->view->fetch('page'));
    }

    /** Keep the app-facing helper names and complete asset URLs. */
    public function testRegisteredTemplateHelpers(): void
    {
        file_put_contents($this->directory . '/helpers.phtml', <<<'PHP'
<?=$this->url_for("route", ["id" => "42"], ["page" => "2"])?>|<?=$this->asset("bundle.js")?>|<?=$this->iconsSvg()?>
PHP);
        $app = AppFactory::create();
        $app->get('/routes/{id}', static fn (): Response => new Response())->setName('route');
        $this->view->registerUrlFor($app->getRouteCollector()->getRouteParser());
        $assets = new AssetHelper(baseUrl: 'https://cdn.example/assets');
        PlatesBootstrap::registerAsset($this->view->getEngine(), $assets);
        PlatesBootstrap::registerIconsSvg($this->view->getEngine(), $assets);
        self::assertSame(
            '/routes/42?page=2|https://cdn.example/assets/bundle.js|https://cdn.example/assets/icons.svg',
            $this->view->fetch('helpers'),
        );
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function contextRequests(): array
    {
        return [
            'page' => ['/dash', 'text/html', true],
            'fragment' => ['/snippet/routes/table', '*/*', true],
            'json' => ['/health', 'application/json', false],
            'api path' => ['/api/health', '*/*', false],
        ];
    }

    /** Skip context work for non-HTML without skipping the downstream handler. */
    #[DataProvider('contextRequests')]
    public function testRequestContext(string $path, string $accept, bool $expected): void
    {
        $middleware = new class ($this->view->getEngine()) extends AbstractViewContextMiddleware {
            /** @return array<string, mixed> */
            protected function context(ServerRequestInterface $request): array
            {
                return ['company' => 'Company A'];
            }
        };
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path)
            ->withHeader('Accept', $accept)
            ->withHeader('FX-Request', 'true');
        $response = new Response(202);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);
        self::assertSame($response, $middleware($request, $handler));
        self::assertSame(
            $expected ? '<title></title>|||Company A|' : '<title></title>||||',
            $this->view->fetch('page'),
        );
    }
}
