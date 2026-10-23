# Thunder Bolt research brief

## Scope and evidence

This repository targets capability parity with [Bolt JS v5.1.0](https://github.com/slackapi/bolt-js/tree/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d), while deliberately using PHP value objects, attributes, and explicit HTTP boundaries. The reproducible source set, archive hashes, package metadata, licenses, and Slack documentation index are recorded in [upstream-manifest.json](upstream-manifest.json). The complete compiler-derived catalog is [typescript-api-catalog.json](typescript-api-catalog.json).

The input set includes Bolt at `ffbe0b6…`, Web API 8.1.1 at `e026671…`, OAuth 4.0.0 at peeled commit `70e15b8…`, Socket Mode 3.0.1 at `e0fa745…`, and Types 3.1.0 at peeled commit `c663dc0…`. The Types tag was annotated; its tag object is recorded in the manifest. The Slack documentation seed is [llms.txt](https://docs.slack.dev/llms.txt).

## Findings

| Area | Finding | Confidence |
| --- | --- | --- |
| Bolt public contract | `App`, listeners, middleware, receiver lifecycle, assistants, custom functions, error types, and exports are inventoried from 46 TypeScript files. | 🟢 |
| Web API surface | 259 direct API methods are generated across 34 root groups and 94 typed dotted-path resources. Web API and separately pinned `@slack/types` exports are compiler-inventoried. `files.uploadV2` is intentionally excluded: it is SDK-side orchestration, not one Slack endpoint. | 🟢 |
| PHP transport | Signed HTTP requests, parsed envelopes, inline acknowledgement and `Client::call()` are portable. Node HTTP server bootstrapping and Socket Mode's WebSocket implementation need PHP-specific adapters. | 🟢 |
| Framework inspiration | Tempest 3.20.0 is used because its 22 October 2026 release resolves the unavailable Nette generator constraint in 3.19.2. Laravel informs configuration, errors, and ergonomic factories; neither framework is a Bolt compatibility dependency. | 🟡 |
| Full behavioral parity | Listener timing, OAuth install flow, Socket Mode reconnect semantics, assistant streaming, and function completion need conformance tests before claiming parity. | 🟡 |

## Compatibility boundary

Parity means accepting the same Slack request categories and preserving observable protocol behavior: signature verification, acknowledgement deadlines, selector matching, middleware ordering and `next()` rules, listener error routing, authorization selection, response URLs, Web API method names, custom-function completion/failure, assistant callbacks, and OAuth/Socket Mode when their adapters land.

Parity does not mean copying Node internals. Node's `http.Server`, `EventEmitter`, `AsyncLocalStorage`, timers, `fetch` implementation, `WebSocket`, and package-specific upload helper are adapter concerns. PHP's equivalent is a PSR HTTP boundary plus explicit optional worker/socket adapters. Generated classes therefore forward only known Slack method strings and never imply Node transport compatibility.
