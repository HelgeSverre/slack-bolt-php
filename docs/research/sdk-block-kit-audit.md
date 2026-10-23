# SDK, Block Kit, and Mago audit

Verified locally on 23 October 2026 using Herd PHP 8.5.12, Tempest 3.20.0, and Mago 1.50.0. This extends the previous HTTP, OAuth, and storage milestones; it does not establish complete Bolt JS runtime parity.

## Implemented surface

| Requirement | Implementation and evidence |
| --- | --- |
| Endpoint request/response models | 271 request classes, 271 response classes, and 636 interned nested structural models across 8,474 endpoint occurrences; generated from pinned TypeScript compiler extraction |
| Exact named method surface | 271 direct methods across 35 root groups and 98 resources; a recorded runtime test invokes every method and checks its exact HTTP path and typed response |
| Correct source extraction | Restored 12 camel-case-root `slackLists` methods; fixed array intersections being mistaken for object prototypes; retained native unions, collision-safe names, sensitive arguments, and deterministic shared shape naming |
| Native model use | Existing `ChatPostMessage` remains accepted; endpoint properties, recursive user profiles, array-valued response warnings, and unknown fields have wire fixtures |
| Message/view builders | Immutable messages, legacy attachments/fields, metadata, unfurls, parsing, authorship, modal/home/legacy view values, rich text, controls, composition, and specialist blocks; [family inventory](block-kit-coverage.md) |
| Modern uploads | Reserve → streamed content transfer → complete, single/multiple files, destination grouping, partial-failure progress, and `files()->uploadV2()` parity facade |
| Stream ownership | Bytes, file paths, and caller-owned streams; declared byte windows/current cursor, non-seekable bounds, no bearer on upload URLs, and no caller-resource closure by temporary request bodies |
| Multipart protocols | Explicit `MultipartFile` values; streamed parts, JSON form values, token-header separation, safe part headers, reopening replayable bodies, and single-attempt borrowed streams |
| Retry policy | Immutable opt-in client copies, bounded attempts/wait, Retry-After, backoff/jitter, explicit transient-error replay, and single-attempt OAuth/token exchanges and response URLs |
| Mago migration | Pinned Composer/CI tool, native signatures, useful generics/collections retained, redundant generated method PHPDocs and 14 redundant handwritten docblocks removed in the final cleanup |

The [client guide](../web-api-client.md), [builder guide](../sdk-and-block-kit.md), and [development guide](../development.md) contain the public API and tooling policy. The hello-bolt example builds its modal with native builders.

## Verification

- PHPUnit: **274 tests, 1,460 assertions**, passing without warnings, skips, or notices.
- Mago format check: passing; generated/scaffold bytes remain generator-owned.
- Mago lint: zero errors; 25 advisory findings remain visible (7 warnings, 18 help).
- Mago source analysis: zero errors; 71 advisory findings remain visible (65 warnings, 6 help).
- No analyzer baseline or blanket analyzer ignores. One narrow expected linter pragma documents intentional PCRE-warning suppression while validating patterns.
- Seven Node tooling tests pass, including regeneration determinism and equality of endpoint/model method sets.
- Both generators verify output and ownership hashes: 271 model pairs/methods, 636 nested models, 126 declaration scaffolds, and 359 Bolt export mappings.
- Strict Composer validation passes for root, hello-bolt example, and a fresh scaffolded application.
- CLI syntax/version checks pass. A freshly installed generated application discovers its mention listener and serializes message metadata and modal builders through its installed framework autoloader.
- Independent review found and followed fixes for missing SlackLists methods, array-intersection hydration, control placement, image constraints, multipart headers, and caller-stream ownership.

The smoke project's temporary checkout/vendor tree was task-owned and removed after verification. The research summary index remains small rather than duplicating the full recursive model catalog.

## Parity boundaries

Generated coverage is broader than exhaustive schema validation. The [model inventory](web-api-model-coverage.json) records **29 raw request-field occurrences and 6,504 raw response-field occurrences** across endpoints, including polymorphic, indexed, unresolved, and depth-limited shapes. Structural extraction is bounded at six levels; unknown/deeper fields remain in raw data. These are explicit fidelity limits. Every response retains `data`, and requests retain `extra` for future fields.

Builders cover the pinned wire families and common field/count/placement constraints. They do not validate every cross-field or surface-specific restriction. Raw blocks/elements remain extension points. Legacy workflow-step serialization does not implement retired workflow execution.

Unlike the Node client's long default retry window and process-wide queue, PHP sends once by default. Retrying clients are explicit background/CLI choices; the wait budget bounds sleeping, while PSR-18 timeouts bound network calls. Ambiguous write retries can duplicate effects. Upload batches are not atomic and expose progress on failure.

No remote Linux/macOS CI, live-workspace API/upload test, or release/publish operation was performed. Socket Mode, durable workers, assistants/custom functions, and full release conformance remain in the [roadmap](roadmap.md).
