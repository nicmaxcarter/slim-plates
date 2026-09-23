# Changelog

## Unreleased

- Keep `queryVersion` on stable asset URLs when a manifest is stale, empty, or
  maps to the logical filename; ignore empty CDN manifest mappings.
- Honor wildcard `Accept` ranges when comparing JSON and HTML preferences.
- Preserve response events by replacing invalid UTF-8. Other non-JSON event
  values now throw `JsonException` rather than silently producing an empty
  header; callers must supply JSON-serializable payloads.
- Accept Slim's `RouteParserInterface` when registering `url_for()`.
- Add focused PHPUnit tests, PHPStan level 9 checks, and a PHP 8.1–8.5 CI matrix
  covering both supported PSR HTTP message major versions.
- Document Composer installation and app-owned browser integration; include the
  MIT license text. Runtime dependency requirements are unchanged.

## v1.0.0

- Initial standalone release of the shared Slim/Plates PHP helpers.
