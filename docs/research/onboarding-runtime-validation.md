# Onboarding and runtime validation — 6 November 2026

Runtime implementation: `5e42816`. This records development acceptance, not a release. The package remains unreleased; no production deployment or Slack app permission change was performed.

## What changed

- One shared `Bootstrap` resolves project configuration and `BOLT_RUNTIME_PATH` for HTTP and CLI. Built-in store overrides also resolve from the project root. A common path inspector checks filesystem kind, writability, lexical exposure and symlinks.
- Application routes preserve intentionally returned HTTP statuses, bodies and headers. Exceptions remain Tempest's responsibility; route-binding failures retain 404 handling.
- Optional browser-session readiness and safe local key generation, with explicit rotation, algorithm-aware validation, private atomic writes and redacted reports.
- Three coherent starter profiles: mention replies, an interactive Home/command/modal flow, and Agent View streaming/Stop. Transport and authorization remain independent choices. Modern profiles reject older incompatible package constraints before writing files.
- Typed command trigger IDs and complete command fixtures for modal tests. Existing constructor calls remain supported.

## Automated checks

- Full suite: **1,251 tests / 7,115 assertions**, passing. The final lock-error wording was additionally checked by the focused key-file and starter tests.
- Twelve profile/transport/authorization combinations: generated PHP, parsed YAML scopes, URLs, OAuth redirects, onboarding defaults and instructions agree. Interactive handlers are dispatched through the real signed testing receiver, including empty/oversized form errors and plain-text output.
- `composer test:project`: passed with state relocated outside the disposable application, including durable restart/recovery, OAuth metadata, typed intake and Agent View fixtures.
- `composer test:vcs-project`: passed; the public testing toolkit works with production dependencies and without relying on the source checkout after installation.
- `composer test:onboarding`: passed against a fresh production-dependency installation and real loopback HTTP server. Application files/directories were made read-only without following the framework path symlink. HTTP and CLI used the same external runtime, process environment won over dotenv, a deliberate HTML 410 retained its body and no-store header, an encrypted session persisted across two requests, and an unhandled production exception returned 500 without exposing its canary. No `.thunder-bolt` directory appeared in the release or web root.
- Key generation preserved unrelated environment settings and existing keys, rotated only with `--force`, used the configured algorithm, rejected symlinked secrets and never printed key material. Missing/invalid session keys were reported without falsely marking Slack HTTP configuration unconfigured.
- Formatting, lint and analysis passed with no errors; existing nonfatal diagnostics remain. Documentation checks and parity-inventory checks passed. Composer validation passed and audit reported no dependency advisories.
- Staged files were compared with private environment values and rotating installation credentials; no matches. Credentials, stores, runtime data and local screenshots remain ignored.

CI now includes the HTTP onboarding smoke on Linux/macOS and focused path, key-file and interactive-starter checks on Windows. Local execution was on macOS/PHP 8.5.10; CI is the evidence for other platforms.

## Real Slack acceptance

Used the established **Thunder Bolt Dev** app (`A0CCM894C64`) in **Helge Sverre** (`T02DB4YSA`), with synthetic input in the test user's private app DM (`D0CBLL7LF51`). The installed app already had the required capabilities. Existing rotating credentials were resolved from the durable installation store; no new scopes, tokens or installation were requested.

The disposable application was installed through Composer's local path source, booted by the actual generated CLI, and used an external private runtime. It combined the generated interactive listener and the unchanged generated Agent View/mention listeners under a test-user/workspace authorization guard. This tests generated code against the existing app; it does not claim that three new least-privilege Slack apps were separately installed.

| Case | Visible outcome |
| --- | --- |
| Generated Home | Rendered “Welcome to your Thunder Bolt app.” with an Open form button. |
| Home action | Open form produced the generated native modal. |
| Required input | Slack rejected an empty/whitespace-only field with its native required-field message. Server-side validation branches were separately exercised by signed fixtures. |
| Submission | Synthetic input updated the modal to “Received” with the submitted name in plain text. Repeated after adding the input length limit; the final modal confirmed “Final starter acceptance.” |
| Slash command | `/bolt` opened the same modal using the typed trigger ID and returned an ephemeral acknowledgement. |
| Agent View | A human root message invoked the generated listener and produced a typed task plus streamed reply. [Root message](https://helgesverre.slack.com/archives/D0CBLL7LF51/p1793988852492099), [reply](https://helgesverre.slack.com/archives/D0CBLL7LF51/p1793988853107159?thread_ts=1793988852.492099&cid=D0CBLL7LF51). |
| Native Stop | Because the example finishes inline, the fixture deliberately put that completed root back into processing state to make Stop operable. Clicking Stop delivered `agent_session_stopped`; the generated handler ran and the UI returned to idle. This establishes event/status handling, not interruption of already-completed inline work. Thread-job cancellation is covered by the existing durable-job tests. |
| Cleanup | Stopped the task-owned Socket Mode worker, restored the captured original Boltling Home, and visibly confirmed its return. No pet state was changed. |

The mention listener is unchanged; this run verified its generated artifacts and registration without posting in shared channels. New profile manifests were parsed and checked locally, but not imported as new Slack installations. Production HTTP delivery, OAuth consent and Marketplace eligibility were outside this development check.

Local screenshots: ignored `work/onboarding-live/form-final.jpg`, `command-modal.jpg`, `agent-stream-and-stop.jpg` and `agent-stopped.jpg`. Local sanitized outcome receipts and command logs accompany them. Early live-fixture problems were confined to the test recorder's payload type and missing rotator constructor dependency; both were corrected before the successful run.

## Migration notes

- Existing apps must upgrade their framework dependency and switch their HTTP entry point to `Bootstrap::boot(...)` to share the runtime setting with CLI workers. CLI upgrades alone do not update an app dependency.
- Existing route code relying on automatic generic error pages should throw an appropriate exception when it wants exception rendering. Intentional error responses now survive unchanged.
- Keep production cookie keys stable and operator-managed. `--force` invalidates encrypted cookies and any other data using that key; it does not rotate Slack credentials.
- Review starter manifests before import. Agent features depend on workspace availability; migrating an existing Assistant View app is a separate decision.

See [runtime and browser routes](../guides/runtime-and-browser-routes.md) and [starter setup](../guides/getting-started.md). A later release still requires the repository's live release gate and exact-tag/registry verification.
