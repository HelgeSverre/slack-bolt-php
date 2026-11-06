# Public interface map

Use this map to find the public PHP interface behind a feature. The linked guides explain setup and behavior; source links expose the current native signatures and implementation. This is a navigation map, not an exhaustive generated class listing.

[Documentation home](README.md) · [Getting started](guides/getting-started.md)

## Application and delivery

| Interface | Responsibility | Usage |
| --- | --- | --- |
| [`Bootstrap`](../src/Bootstrap.php) | Shared project boot and private HTTP/CLI runtime storage (unreleased) | [Runtime and browser routes](guides/runtime-and-browser-routes.md) |
| [`App`](../src/App.php) | Registration, middleware, dispatch and error policy | [Building apps](guides/building-apps.md) |
| [`BoltConfig`](../src/BoltConfig.php) | Runtime credentials and configuration | [Getting started](guides/getting-started.md#configure-the-local-app) |
| [`Delivery`](../src/Invocation/Delivery.php) | Selected listener, authorization, destination and capabilities | [Building apps](guides/building-apps.md) |
| [`Context`](../src/Context.php) | Values shared during one delivery | [Building apps](guides/building-apps.md) |
| [`Reply`](../src/Reply.php) | Channel, thread, ephemeral and response-URL replies | [Building apps](guides/building-apps.md) |
| [`Receiver`](../src/Http/Receiver.php) | Signed HTTP intake and response handling | [HTTP interactivity](http-interactivity.md) |
| [`Runner`](../src/SocketMode/Runner.php) | Socket Mode connection and delivery lifecycle | [Slack setup](guides/slack-app-setup.md) |

## Payloads, messages and Web API

| Interface | Responsibility | Usage |
| --- | --- | --- |
| [`EventPayloads`](../src/Payloads/EventPayloads.php) | Known inbound event types and generic fallback | [Typed events](guides/typed-events.md) |
| [`InputValue`](../src/Payloads/Values/InputValue.php) | Typed action and form values | [HTTP interactivity](http-interactivity.md) |
| [`Message`](../src/Messages/Message.php) | Outbound message composition | [SDK and Block Kit](sdk-and-block-kit.md) |
| [`Block`](../src/BlockKit/Block.php) | Shared interface for Block Kit builders | [SDK and Block Kit](sdk-and-block-kit.md) |
| [`Client`](../src/WebApi/Client.php) | Slack methods, request transport and pagination | [Web API client](web-api-client.md) |

## Installation and durable state

| Interface | Responsibility | Usage |
| --- | --- | --- |
| [`Installer`](../src/OAuth/Installer.php) | OAuth installation and browser-bound state | [OAuth](oauth-and-authorization.md) |
| [`InstallationStore`](../src/OAuth/InstallationStore.php) | Installation persistence and locked updates | [OAuth](oauth-and-authorization.md) |
| [`ConversationStore`](../src/Conversation/ConversationStore.php) | Persisted conversation/thread values and expiry | [Storage](conversation-and-thread-storage.md) |
| [`Jobs`](../src/Jobs.php) | Named durable intake, deduplication and cancellation | [Durable operations](guides/durable-operations.md) |
| [`JobExecution`](../src/Jobs/JobExecution.php) | Attempt checkpoints and lease heartbeats | [Durable operations](guides/durable-operations.md) |
| [`SqliteJobStore`](../src/Jobs/SqliteJobStore.php) | Execution records, claims, recovery and retention | [Durable operations](guides/durable-operations.md) |

## Agents and application testing

| Interface | Responsibility | Usage |
| --- | --- | --- |
| [`Assistant`](../src/Assistant.php) | Configure Agent View or legacy assistant handlers | [Agent View](guides/agent-view.md) |
| [`AppContext`](../src/Assistant/AppContext.php) | Current Slack focus snapshot | [Agent View](guides/agent-view.md#context-semantics) |
| [`Thread`](../src/Assistant/Thread.php) | Rooted replies, streaming, prompts and session access | [Agent View](guides/agent-view.md) |
| [`Session`](../src/Assistant/Session.php) | Modern status and title controls | [Advanced features](guides/advanced-slack-features.md) |
| [`MessageStream`](../src/Assistant/MessageStream.php) | Destination-bound streaming and lifetime limits | [Streaming](guides/streaming-progress.md) |
| [`TaskUpdate`](../src/Assistant/Streaming/TaskUpdate.php) | Typed progress, output and source values | [Streaming](guides/streaming-progress.md) |
| [`BoltTest`](../src/Testing/BoltTest.php) | Isolated application harness and signed fixtures | [Testing toolkit](guides/testing-toolkit.md) |
| [`FakeSlack`](../src/Testing/FakeSlack.php) | Captured requests and configured Slack responses | [Testing toolkit](guides/testing-toolkit.md) |
