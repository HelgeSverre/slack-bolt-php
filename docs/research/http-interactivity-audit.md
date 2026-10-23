# HTTP interactivity milestone audit

Verified locally on 23 October 2026 with Herd PHP 8.5.12 and the locked Tempest 3.20.0 dependency set. This milestone implements the requested HTTP actions, shortcuts, options, and views. Full framework parity remains tracked in the [implementation plan](roadmap.md).

## Requirements and evidence

| Requirement | Implementation and validation |
| --- | --- |
| Actions | `block_actions`, legacy interactive-message actions, and dialog submissions dispatch to readonly `Payloads\Action`; first-action matching, compound constraints, and typed initial responses have signed HTTP coverage |
| Shortcuts | Global `shortcut` and message `message_action` payloads route by literal or regex callback ID; message context is preserved and global shortcuts have no fabricated reply destination |
| External options | Block suggestions, dialog suggestions, and legacy interactive-message suggestions return empty, flat, or grouped choices with the source-specific text/label wire format |
| Views | Submission shorthand, explicit closure filters, and untyped compound view selectors follow pinned Bolt semantics; empty, errors, update, push, and clear response bodies are covered |
| Response URLs | `Reply::respond()`, `replaceOriginal()`, and `deleteOriginal()` preserve the opaque URL and send no bot bearer token; modal submissions select the first response URL; missing context and transport failures have tests |
| Acknowledgement contract | Exactly one returned response per delivery; wrong-family, missing, multiple, and invalid source-specific responses fail; numeric modal error block IDs serialize as object keys |
| Idiomatic PHP interface | Readonly payloads preserve `raw`; method attributes and fluent registrations share descriptors; response/option factories and immutable builders keep wire details inside modules |
| CLI and discovery | Four listener templates, type filters, legacy callback mapping, regex attributes, cached compound constraints, and project-local completions are exercised |
| Connected example | Mention/button/update and shortcut/modal/options/submission validation run through the actual dispatcher and recorded client; the manifest includes interactivity and the required scopes |

The [protocol reference](http-interactivity.md) links the pinned Bolt JS classifier, matcher, listener normalization, and response URL behavior. The [user guide](../http-interactivity.md) documents the public PHP API. A final independent read-only review found no actionable protocol blocker in the implemented milestone.

## Final validation

- PHPUnit: **124 tests, 370 assertions**, passing.
- PHPStan level 6: **167 files**, no errors. Experimental declarations remain excluded.
- Laravel Pint: passing.
- Strict Composer validation: package and checked-in example passing; the fresh installed project also validated during smoke testing.
- Node tooling: **6 tests**, passing; built-in Node modules suffice for verification.
- Generated ownership/consistency verification: **259 methods, 34 root groups, 94 resources, 126 declaration scaffolds, 359 export mappings**, matching the inventory.
- Working-tree whitespace check: passing.

### Fresh installed application over HTTP

A new project was scaffolded and installed through its local Composer path repository with fake credentials. Eleven listeners included all new families, legacy forms, and a regex method attribute. Cold and cached discovery produced identical listener lists. Project-local completions worked, and the HTTP bootstrap used the CLI's `.thunder-bolt` discovery storage.

The actual local development server returned `401` for an invalid signature and `200` for each of these ten signed requests:

1. Block action.
2. Global shortcut.
3. Block options suggestion.
4. View submission.
5. View closure.
6. Legacy dialog submission.
7. Legacy interactive-message action.
8. Legacy dialog suggestion.
9. Legacy interactive-message suggestion.
10. A regex action attribute loaded from the discovery cache, including capture verification.

The temporary application and server were removed afterward. This smoke sent no Slack Web API calls and used no real credentials. The connected example tests separately cover outbound modal and response-URL requests with a recorded transport.

## Deliberate differences and remaining scope

PHP handlers return an acknowledgement value rather than calling an imperative `ack()`. HTTP execution is inline and buffered; returning a response does not flush it early. Handlers and outbound calls must fit Slack's three-second deadline. Matching listeners run sequentially, retaining the existing middleware and error aggregation semantics.

The pinned Bolt classifier excludes `dialog_cancellation`; the receiver returns `501` for it. Legacy interactive-message responses accept complete message arrays through `ActionResponse::fromArray()`. View bodies currently use native arrays until complete typed Block Kit/view models are implemented.

Real-workspace installation and remote Linux/macOS CI have not run. OAuth/token rotation, durable stores/queues, Socket Mode, full endpoint models and upload helpers, assistants, and custom functions remain later milestones. The next planned milestone is installation and authorization.
