# Changelog

## v0.1.0 — 2026-10-26

First experimental preview. The namespace and interfaces may change before 1.0.

- PHP 8.5+ applications on Tempest 3, with discovered typed listeners, middleware, HTTP and Socket Mode delivery.
- Actions, shortcuts, external options, views, OAuth installations, state validation and token rotation.
- Immutable message/view builders, 273 SDK request/response pairs, cursor pagination, upload helpers and explicit bounded retries.
- Conversation/thread stores, assistant threads, modern agent sessions, Work Objects and custom-function execution helpers.
- Named SQLite jobs with durable acceptance, deduplication, retry policies, lease recovery, cancellation, migrations, history and bounded retention.
- Project/listener/job scaffolding and shell completions; a demo covering AI streaming, tool progress and a shared durable pet.
- Transport evidence for all 273 catalogued endpoints. Expanded GET handling, form-only POST support, corrected external-upload `alt_txt`, and bounded gzip analytics decoding.
- Fresh production-dependency installation smoke test covering discovery, signed deliveries and worker recovery across processes, alongside Linux/macOS CI.

This is not a claim of complete Bolt JS parity. Paid-plan custom functions and enterprise-only methods have fixture coverage, not comprehensive live verification. Distribution remains through this Git checkout; the package is not registered on Packagist.
