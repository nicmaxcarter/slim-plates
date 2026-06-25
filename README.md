# nicmaxcarter/slim-plates

PHP bridge for Slim + [League Plates](https://platesphp.com/): view adapter, manifest-based assets, fixi response helpers, and middleware guards.

**Frontend JS is not included.** Each app keeps its own fixi integration; this package documents the header/event contract so PHP and app JS stay aligned.

---

## Requirements

- PHP 8.1+
- `league/plates` ^3.6
- `psr/http-message`
- Slim 4 (suggested) for `url_for()` route integration and `psr/http-server-handler` (used by `AbstractViewContextMiddleware`; provided transitively by Slim)

`psr/http-server-handler` is **not** a direct package dependency — it is a PSR interface only, and Slim apps already install it. Non-Slim consumers using the view-context middleware must provide it themselves.

---

## Installation (path repo / monorepo)

In the consuming app's root `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "NicmaxCarter\\SlimPlates\\": "packages/nicmaxcarter/slim-plates/src/"
    }
  }
}
```

Run `composer dump-autoload`.

When stable, publish to Packagist and `composer require nicmaxcarter/slim-plates`.

---

## Quick start — assets

### 1. Register services

In your DI container (example: PHP-DI):

```php
use NicmaxCarter\SlimPlates\AssetHelper;

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
```

### 3. Use in templates

```html
<link rel="stylesheet" href="<?=$this->asset('style.css')?>" />
<script src="<?=$this->asset('bundle.js')?>"></script>
```

**Tip:** `npm run dev` removes `manifest.json` so PHP falls back to stable filenames while webpack watch runs.

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

No “uses npm” flag is required. Missing or unreadable manifest files are treated as an empty manifest (safe fallback).

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
  "style.css": "style.e5f6g7h8.css"
}
```

- **Keys:** logical filenames used in templates (`$this->asset('bundle.js')`)
- **Values:** hashed filenames on disk under the assets directory
- **Development:** manifest is optional; omit it or skip emitting in dev/watch mode

Any build tool (Webpack, Vite, Rollup) can produce this shape. The PHP package only reads the JSON file.

### Webpack example (minister-manager)

See the root `webpack.config.js`:

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

- [ ] PSR-4 autoload `NicmaxCarter\SlimPlates\`
- [ ] Register `Engine` / `PlatesView` in DI
- [ ] Register `AssetHelper` with `manifestPath`, `baseUrl`, optional `queryVersion`
- [ ] Call `PlatesBootstrap::registerAsset()` after container build
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

Toast-only fixi actions skip body swap via `fx-swap="none"` on the requesting element (not a response header).

| Event name | Set by (package) | Handled by (app) |
|------------|------------------|------------------|
| `notifySuccess` | `Responses::withToast(…, 'success')` | App `notifications.js` |
| `notifyError` | `Responses::withToast(…, 'error')` | App `notifications.js` |
| `launchModal` | `Responses::launchModal()` | App `modalFunctions.js` |

See `Responses` in `src/Responses.php` for `withTriggers()`, `withToast()`, `launchModal()`, `launchModalWithToast()`, `toastOnly()`, and `redirectWithToast()`.

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

**App:** Register `$flash` in Plates globals; insert the partial in layout `<head>`; implement `flashToasts.js` (or equivalent) to read meta tags and call your toast helpers on page load. Redirect to the final HTML page — intermediate 302s clear flash before the toast runs.

See minister-manager `docs/DEVELOPMENT_GUIDELINES.md` for the full pattern.

---

## View context middleware

Skip Plates `addData()` for API and JSON-preferring requests. Guard logic lives in the package; each app supplies layout globals in `context()`.

### RequestGuards

| Method | Skips layout globals when… |
|--------|----------------------------|
| `isApiPath($request)` | Path is `/api`, starts with `/api/`, or contains `/api/` (e.g. `/backend/api/health`) |
| `prefersJson($request)` | `Accept` header ranks `application/json` above `text/html` |
| `isNonHtmlRequest($request)` | Either of the above |

Fixi HTML partials under normal page paths still receive globals. JSON health checks and JSON API clients do not.

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
| `active` | Controller render params or `ViewContextMiddleware::context()` |

Plates `addData()` never clears keys — within a single request, a second render that omits `pageTitle` would otherwise keep the previous value. `PlatesView` resets each promoted key to `null` at the start of every `render()` / `fetch()`, then applies only the keys present in that call's `$data` array.

Middleware globals (e.g. `uinfo`, `darkmode`) are unaffected; they are set once per request via `addData()` outside `PlatesView`.

---

## Extract to Packagist

When Phases 0–5 are stable in minister-manager:

1. Copy or split `packages/nicmaxcarter/slim-plates/` to its own git repo
2. Ensure `composer.json` has correct `name`, `description`, `license`, `require`, and `autoload`
3. Tag `v1.0.0`
4. Register on Packagist; enable GitHub hook
5. In minister-manager: remove path autoload; add `"nicmaxcarter/slim-plates": "^1.0"`
6. In portal (or other consumers): same require; wire bootstrap in `conf/app.php` / `conf/dependencies.php` and `public/index.php`

No namespace or class renames should be required if Phases 0–5 followed the bridge plan.

---

## License

MIT
