# nicmaxcarter/slim-plates

PHP bridge for Slim + [League Plates](https://platesphp.com/): view adapter, manifest-based assets, fixi response helpers, and middleware guards.

**Frontend JS is not included.** Each app keeps its own fixi integration; this package documents the header/event contract so PHP and app JS stay aligned.

---

## Requirements

- PHP 8.1+
- `league/plates` ^3.6
- `psr/http-message` ^1.0 or ^2.0
- Slim 4 for the optional `url_for()` route integration
- `slim/flash` for the optional redirect toast helpers

Slim and Slim Flash are optional integrations, not installed automatically.
`AbstractViewContextMiddleware` also needs `psr/http-server-handler`, which Slim
already installs. Non-Slim applications using that middleware must provide it.
Tests and development tools are development-only dependencies.

---

## Installation

```bash
composer require nicmaxcarter/slim-plates:^1.0
```

Composer registers the package namespace automatically. Remove any old manual
`NicmaxCarter\\SlimPlates\\` autoload mapping when migrating from a copied package.
Do not keep a local source copy alongside the installed release.

## View setup

Register the engine and adapter in your application's existing container. Use
`phtml` explicitly; Plates otherwise defaults to `php`.

```php
use League\Plates\Engine;
use NicmaxCarter\SlimPlates\PlatesView;

$engine = new Engine(__DIR__ . '/templates', 'phtml');
$platesView = new PlatesView($engine);
$platesView->registerUrlFor($app->getRouteCollector()->getRouteParser());
```

Controllers call `$platesView->render($response, 'page', $data)` or
`$platesView->fetch('page', $data)` to obtain a string. Templates can generate
named-route links with `$this->url_for('route-name', $parameters, $query)`.
Share the same engine with any view-context middleware.

---

## Quick start — assets

### 1. Register services

In your DI container (example: PHP-DI):

```php
use NicmaxCarter\SlimPlates\AssetHelper;
use Psr\Container\ContainerInterface;

AssetHelper::class => function (ContainerInterface $container) {
    $settings = $container->get('settings');
    $rootPath = realpath(__DIR__ . '/..');

    $baseUrl = '/assets/';
    $cdn = $settings['assets'] ?? '';
    if (($settings['debug'] ?? false) === false && is_string($cdn) && $cdn !== '') {
        $baseUrl = rtrim($cdn, '/') . '/assets/';
    }

    $assetsDirectory = $rootPath . '/public/assets';

    return new AssetHelper(
        manifestPath: $assetsDirectory . '/manifest.json',
        baseUrl: $baseUrl,
        assetsDirectory: $assetsDirectory,
    );
},
```

### 2. Register the Plates function

After the container is built (e.g. in `public/index.php`):

```php
use NicmaxCarter\SlimPlates\AssetHelper;
use NicmaxCarter\SlimPlates\Bootstrap\PlatesBootstrap;
use NicmaxCarter\SlimPlates\PlatesView;

/** @var PlatesView $platesView */
$platesView = $container->get(PlatesView::class);
$assetHelper = $container->get(AssetHelper::class);

PlatesBootstrap::registerAsset($platesView->getEngine(), $assetHelper);
PlatesBootstrap::registerIconsSvg($platesView->getEngine(), $assetHelper);
```

### 3. Use in templates

```html
<link rel="stylesheet" href="<?=$this->asset('style.css')?>" />
<script>
    window.iconsSvgUrl = <?=json_encode($this->iconsSvg(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;
</script>
<script src="<?=$this->asset('bundle.js')?>" defer></script>
```

**Icons sprite:** your app generates `icons.svg` and includes it in the asset
manifest. This package provides its URL, not the sprite, icon partials, or JS.
Set `window.iconsSvgUrl` before loading app scripts that use it.

**Development:** configure your app's development build to remove stale
`manifest.json` files when switching back to stable filenames. This package does
not provide npm scripts.

---

## AssetHelper behaviour

Resolution order for `$this->asset('bundle.js')`:

1. **Manifest lookup** — if `manifest.json` maps to a file that exists on disk (or CDN-only with no disk checks), use the hashed filename.
2. **Fallback** — return the logical name (`bundle.js`).

| Scenario | Manifest | Result |
|----------|----------|--------|
| Production deploy after `npm run build` | Present | `/assets/bundle.a1b2c3d4.js` |
| Local dev (`npm run dev`) | Removed by dev script | `/assets/bundle.js` |
| After `npm run build` (local) | Used when only hashed files exist | `/assets/bundle.a1b2c3d4.js` |
| Static-only app (no npm) | Absent | `/assets/bundle.js` |
| CDN in production | Present | `https://cdn.example.com/assets/bundle.a1b2c3d4.js` |

No “uses npm” flag is required. Missing, unreadable, or malformed manifests and
empty mappings fall back to logical filenames. A configured `queryVersion` is
applied whenever the resolved name is unchanged, including stale-manifest
fallbacks. Hashed filenames do not receive it.

Keys and values use flat filenames; subdirectories are not preserved. Helpers
return complete URLs, so do not prepend another `/assets/` in templates.

### Constructor options

| Parameter | Default | Purpose |
|-----------|---------|---------|
| `$manifestPath` | `null` | Absolute path to `manifest.json`; unreadable/missing file is treated as empty |
| `$baseUrl` | `'/assets/'` | URL prefix; set to CDN base + `/assets/` when serving from a CDN |
| `$assetsDirectory` | `null` | When set, manifest entries are used only if the mapped file exists on disk; pass `null` only for CDN-only deploys with no local copy |
| `$queryVersion` | `null` | Optional string appended as `?v=` for unresolved logical assets only |

---

## Manifest contract (build tool ↔ PHP)

Production builds should write `public/assets/manifest.json`:

```json
{
  "bundle.js": "bundle.a1b2c3d4.js",
  "style.css": "style.e5f6g7h8.css",
  "icons.svg": "icons.a1b2c3d4.svg"
}
```

- **Keys:** logical filenames used in templates (`$this->asset('bundle.js')`)
- **Values:** hashed filenames on disk under the assets directory
- **Development:** manifest is optional; omit it or skip emitting in dev/watch mode

Any build tool (Webpack, Vite, Rollup) can produce this shape. The PHP package only reads the JSON file.

### Example application build setup

Configure your application's build tool to use:

- Production: `[contenthash:8]` in output filenames + `webpack-manifest-plugin` emitting logical → hashed mapping.
- Development: stable `bundle.js` / `style.css`, no manifest plugin.

```bash
npm run dev    # watch, stable filenames, no manifest
npm run build  # production hashes + manifest.json
```

### Static-only consumer (no build step)

1. Place files in `public/assets/` (e.g. `app.css`, `app.js`).
2. Register `AssetHelper` with `manifestPath: null` (or a path that does not exist).
3. Use `$this->asset('app.css')` in templates.
4. Optionally set `queryVersion` in app settings and bump it when static assets change.

---

## CDN setup

Set `$baseUrl` to your CDN origin including the assets path:

```php
new AssetHelper(
    manifestPath: $rootPath . '/public/assets/manifest.json',
    baseUrl: 'https://cdn.example.com/assets/',
    queryVersion: null,
);
```

Deploy hashed files and `manifest.json` to the CDN alongside (or instead of) the app server. Templates do not change.

---

## Checklist — new project integration

- [ ] Install the released package with Composer (no manual autoload mapping)
- [ ] Register `Engine` / `PlatesView` in DI
- [ ] Register `AssetHelper` with `manifestPath`, `baseUrl`, optional `queryVersion`
- [ ] Call `PlatesBootstrap::registerAsset()` after container build
- [ ] Call `PlatesBootstrap::registerIconsSvg()` after container build (icon sprite URL)
- [ ] Call `PlatesBootstrap::registerFlashToasts()` after container build (redirect toast partial)
- [ ] Call `$platesView->registerUrlFor($routeParser)` for named routes
- [ ] Add app `ViewContextMiddleware` extending `AbstractViewContextMiddleware` (see below)
- [ ] Register middleware in `conf/middleware.php`
- [ ] Replace hardcoded `/assets/…?v=…` with `$this->asset('…')` in layouts
- [ ] Production CI/deploy runs your asset build and publishes `public/assets/` (+ manifest)

Static files outside the manifest (favicon, images) can use a plain `/path` in templates.

---

## Fixi response contract (PHP ↔ app JS)

Documented for cross-project consistency. Listeners live in each app's `frontend/js/`.

| Header | Purpose |
|--------|---------|
| `HX-Trigger-After-Settle` | JSON object or plain string event name |
| `HX-Redirect` | Full-page internal redirect; cancel the pending swap first |
| `FX-Current-URL` (request) | App JS sends `location.pathname + location.search` for login returns |

Toast-only fixi actions skip body swap via `fx-swap="none"` on the requesting element (not a response header).

| Event name | Set by (package) | Handled by (app) |
|------------|------------------|------------------|
| `notifySuccess` | `Responses::withToast(…, 'success')` | App `notifications.js` |
| `notifyError` | `Responses::withToast(…, 'error')` | App `notifications.js` |
| `launchModal` | `Responses::launchModal()` | App `modalFunctions.js` |

```php
use NicmaxCarter\SlimPlates\Responses;

return Responses::withTriggers($response, [
    'notifySuccess' => 'Saved',
    'reload-table' => '',
]);
```

`withToast()` and `launchModalWithToast()` take **type before message**.
Unsupported types throw `InvalidArgumentException`; they never default to
success. `withTriggers()` replaces the event header, so combine events in one
call rather than chaining helpers. Invalid UTF-8 is replaced during encoding;
other non-JSON values throw `JsonException` instead of silently dropping events.

The app's browser bridge must handle redirects and HTTP failures at `fx:after`,
before insertion, and dispatch successful modal/reload events at `fx:swapped`,
after insertion. Network failures arrive at `fx:error`. For Fixi 0.9.2,
`fx:swapped` also fires for `fx-swap="none"`; cancelling `fx:after` skips it.
Preserve input on failures and never automatically replay writes after a lost
response. These protections are app responsibilities, not behavior supplied by
PHP headers alone.

See `src/Responses.php` for the complete helper signatures.

---

## Redirect flash toasts

For full-page POST → redirect flows (login, PRG forms), response headers are not available to JavaScript. Use Slim Flash instead:

```php
use NicmaxCarter\SlimPlates\Flash\FlashToastKeys;
use NicmaxCarter\SlimPlates\Responses;

return Responses::redirectWithToast(
    $response,
    '/dash',
    'Welcome back',
    FlashToastKeys::SUCCESS,
    $flash,
);
```

**Package:** `FlashToastKeys`, `redirectWithToast()`, `bridge::partials/flash-toasts` (meta tags only).

**App:** Call `PlatesBootstrap::registerFlashToasts($engine)`, make `$flash`
available to the layout, and insert the partial once in `<head>`:

```php
<?php $this->insert("bridge::partials/flash-toasts", ["flash" => $flash]) ?>
```

Plates partials do not inherit their caller's local variables, so pass `flash`
explicitly. The partial emits escaped metadata for the first message of each
type, not JavaScript. Provide one browser consumer to show those messages on
page load. Redirect to the final HTML page: initializing Slim Flash on
intermediate redirects can consume messages before they are displayed.

---

## View context middleware

Skip Plates `addData()` for API and JSON-preferring requests. Guard logic lives in the package; each app supplies layout globals in `context()`.

### RequestGuards

| Method | Behavior |
|--------|----------------------------|
| `isApiPath($request)` | Path is `/api`, starts with `/api/`, or contains `/api/` (e.g. `/backend/api/health`) |
| `prefersJson($request)` | JSON ranks above HTML, honoring wildcard ranges and specific overrides; ties favor HTML |
| `isFixiRequest($request)` | Request includes `FX-Request: true` (fixi-js default on every partial update) |
| `fixiCurrentUrl($request)` | Validated `FX-Current-URL` header (document path for post-login redirect) |
| `safeInternalRedirectPath($path)` | Same-origin relative path guard for redirect targets |
| `isNonHtmlRequest($request)` | `isApiPath` or `prefersJson` |

Fixi HTML partials under normal page paths still receive globals. JSON health
checks and JSON-preferring clients do not. `/api/` classification is a convention,
not proof of the response type: move HTML endpoints out of that prefix or apply
app-specific classification before adopting this middleware.

After trimming surrounding whitespace, the redirect guard rejects `/`, absolute
and protocol-relative URLs, backslashes, `@`, control characters, and encoded
path separators. Encoded
separators in query strings are allowed. It does not decide whether a path is an
authorized page or prevent login loops. The app must choose safe page returns,
exclude login/logout/action endpoints, and enforce authentication and company
access. The package does not manage sessions or replay submitted requests.

### App middleware

Extend `AbstractViewContextMiddleware` and implement `context()`:

```php
use NicmaxCarter\SlimPlates\Middleware\AbstractViewContextMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Interfaces\RouteInterface;

final class ViewContextMiddleware extends AbstractViewContextMiddleware
{
    protected function context(Request $request): array
    {
        $layoutData = ['uinfo' => [], 'darkmode' => ''];

        $route = $request->getAttribute('__route__');
        if ($route instanceof RouteInterface) {
            $routeName = $route->getName();
            if (is_string($routeName) && $routeName !== '') {
                $layoutData['active'] = $routeName;
            }
        }

        // … app-specific keys (uinfo, darkmode, flash, etc.)

        return $layoutData;
    }
}
```

Register in DI with the shared `League\Plates\Engine` instance and add to the Slim middleware stack (before route handlers).

---

## Layout key promotion

`PlatesView::render()` and `fetch()` promote selected render params to Plates engine globals so layouts can read them without explicit `layout()` data:

| Key | Typical source |
|-----|----------------|
| `pageTitle` | Controller render params |
| `searchTerm` | Controller render params |
| `active` | `ViewContextMiddleware::context()` (route name); optional override via controller render params |

Plates `addData()` never clears keys — within a single request, a second render that omits `pageTitle` would otherwise keep the previous value. `PlatesView` resets each promoted key in `LAYOUT_DATA_KEYS` to `null` at the start of every `render()` / `fetch()`, then applies only the keys present in that call's `$data` array.

`active` is not reset here: it is set once per request by view-context middleware from the matched route name. Controllers may still override it by passing `active` in render params.

Middleware globals (e.g. `uinfo`, `darkmode`) are unaffected; they are set once per request via `addData()` outside `PlatesView`.

The engine and adapter are request-scoped. Applications using long-lived workers
must create fresh request-scoped instances rather than carry user/company data
into another request.

---

## Development and releases

```bash
composer install
composer check
```

`composer check` validates package metadata, runs PHPUnit regressions, and checks
source and tests at PHPStan level 9. Tests use real Plates rendering, temporary
asset files, Slim responses, and flash storage; no application database or
browser is needed. PHP-only tests do not certify an application's Fixi bridge.

CI runs on PHP 8.1–8.5 and exercises both supported PSR HTTP message major
versions. The library does not commit `composer.lock`; CI resolves dependencies
for each supported environment. `vendor/` and local test/analyser caches are
ignored.

Before publishing a new tag:

1. Run `composer check` and confirm CI passes.
2. Confirm the distribution includes `src/`, `views/partials/flash-toasts.phtml`,
   `composer.json`, and `LICENSE`.
3. Record consumer-visible behavior changes in [CHANGELOG.md](CHANGELOG.md) and
   publish a new version; never move an existing tag.
4. Update consuming apps deliberately and verify their actual browser flows.
   Installing this package does not replace app-owned JavaScript, authentication,
   company permissions, or Portal-specific response helpers.

---

## License

MIT
