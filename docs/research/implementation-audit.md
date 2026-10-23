# Initial implementation audit

Verified locally on 23 October 2026 with Herd PHP 8.5.12. This audit covers the request to update the plan and start implementation through a working slice and broad scaffold. The complete Bolt JS port remains the target of the remaining milestones in the [implementation plan](roadmap.md).

This is a historical snapshot of the initial milestone. The subsequent [HTTP interactivity audit](http-interactivity-audit.md) records implemented interaction families and current validation; follow the README for current tooling commands.

## Requirements and evidence

| Requirement | Current-state evidence | Result |
| --- | --- | --- |
| Update the plan while preserving the full port scope | `roadmap.md` records the agreed foundation, initial deliverables, remaining families, and acceptance gates; the README links it | Complete for this milestone |
| Gather reproducible references | Five pinned archives match their recorded SHA-256 hashes; 14 preserved package/license files and the saved documentation records match the manifest | Verified locally |
| Map the upstream API | Compiler extraction reads 46 Bolt, 395 Web API, and 42 Slack Types files; all 359 extracted Bolt exports have dispositions | First-pass inventory complete; unresolved constructs remain explicit |
| Produce initial transpilation scripts and scaffolds | Full regeneration and verification produce 126 declaration scaffolds, 359 mappings, and 259 direct API methods across 34 root groups and 94 typed resources | Verified; scaffolds do not claim runtime implementation |
| Start idiomatic PHP implementation | Production modules implement signed HTTP requests, typed dispatch, middleware, reply destinations, initial command responses, immutable messages, and Web API calls | Implemented; protocol and interface tests pass |
| Provide code examples and PHP deviations | README, architecture/design documents, generated listener templates, and the installed `examples/hello-bolt` application use the current interface | Present and consistent with the implementation |
| Start the scaffolding CLI | A fresh generated project installs through its path repository, passes strict Composer validation, discovers generated listeners, and produces usable completions | Verified in a fresh project |
| Integrate HTTP and cached discovery | Cold and cached listener lists agree; the development server handles signed challenges and slash commands with the same `.thunder-bolt` storage as the CLI | Verified over local HTTP |
| Keep pending parity visible | Interactivity, installation/OAuth, complete SDK/Block Kit models, adapters, assistants, functions, and release validation remain in the plan and parity map | Explicitly pending |

## Validation

- `composer test`: **61 tests, 154 assertions**, passing.
- `composer analyse`: PHPStan level 6, no errors. Experimental declaration scaffolds are intentionally excluded from static analysis.
- `composer lint`: Laravel Pint, passing.
- `composer validate --strict`: passing for the package and freshly generated project.
- `npm run generate`: compiler extraction and scaffold generation complete.
- `npm run verify:generated`: generated outputs match the current inventory and ownership records.
- `npm run test:tooling`: six tests passing, including ownership/conflict checks, orphan detection, export dispositions, nested resource paths, and scaffold discovery exclusion.
- `git diff --check`: passing. Upstream reference snapshots retain their original whitespace.

The fresh-project smoke test additionally checked credential diagnostics, generated completion metadata/helper output, plaintext `401` for an invalid signature, plaintext `200` for a signed URL challenge, and a `200` JSON initial response from a generated slash-command handler. The temporary application and development server were removed afterward. Requests stayed local; no Slack messages were sent.

## Audit corrections

The generated HTTP bootstrap now uses the CLI's `.thunder-bolt` internal storage, so discovery cache commands operate on the web application's cache. The completion test ignores shell comments when checking for accidental Tempest executable/cache references, allowing the preserved license attribution. New dispatcher tests verify independent listener failure handling, aggregated failures, error-handler context, and unknown-event preservation.

## Limits

Local fixtures establish the implemented slice, not full Bolt JS conformance. Remote Linux/macOS CI and real-workspace tests have not run. Most SDK bindings still accept arrays or `RequestPayload`; full typed models, upload orchestration, and automatic retry behavior remain pending. Inline handlers must meet Slack's response deadline. The measured CLI help startup remains above the 50 ms target.
