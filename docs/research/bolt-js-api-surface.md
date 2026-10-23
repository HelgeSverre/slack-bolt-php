# Bolt JS v5.1.0 extracted API surface

Generated from 46 TypeScript source files using the TypeScript compiler API.

## App public surface

- `client` — property: `WebClient`
- `logger` — property: `Logger`
- `(call signature)` — Constructor
- `init` — method: `Promise<void>`
- `webClientOptions` — GetAccessor: `WebClientOptions`
- `use` — method: `this`
- `assistant` — method: `this`
- `function` — method: `this`
- `function` — method: `this`
- `function` — method: `this`
- `start` — method: `ReturnType<HTTPReceiver['start']>`
- `stop` — method: `Promise<unknown>`
- `event` — method: `void`
- `event` — method: `void`
- `event` — method: `void`
- `message` — method: `void`
- `message` — method: `void`
- `message` — method: `void`
- `message` — method: `void`
- `message` — method: `void`
- `message` — method: `void`
- `shortcut` — method: `void`
- `shortcut` — method: `void`
- `shortcut` — method: `void`
- `action` — method: `void`
- `action` — method: `void`
- `action` — method: `void`
- `command` — method: `void`
- `options` — method: `void`
- `options` — method: `void`
- `options` — method: `void`
- `view` — method: `void`
- `view` — method: `void`
- `view` — method: `void`
- `error` — method: `void`
- `error` — method: `void`
- `error` — method: `void`
- `processEvent` — method: `Promise<void>`

## AppOptions keys and types

- `signingSecret?` — `HTTPReceiverOptions['signingSecret']`
- `endpoints?` — `HTTPReceiverOptions['endpoints']`
- `port?` — `HTTPReceiverOptions['port']`
- `customRoutes?` — `HTTPReceiverOptions['customRoutes']`
- `processBeforeResponse?` — `HTTPReceiverOptions['processBeforeResponse']`
- `signatureVerification?` — `HTTPReceiverOptions['signatureVerification']`
- `clientId?` — `HTTPReceiverOptions['clientId']`
- `clientSecret?` — `HTTPReceiverOptions['clientSecret']`
- `stateSecret?` — `HTTPReceiverOptions['stateSecret']`
- `redirectUri?` — `HTTPReceiverOptions['redirectUri']`
- `installationStore?` — `HTTPReceiverOptions['installationStore']`
- `scopes?` — `HTTPReceiverOptions['scopes']`
- `installerOptions?` — `HTTPReceiverOptions['installerOptions']`
- `convoStore?` — `ConversationStore | false`
- `token?` — `AuthorizeResult['botToken']`
- `appToken?` — `string`
- `botId?` — `AuthorizeResult['botId']`
- `botUserId?` — `AuthorizeResult['botUserId']`
- `authorize?` — `Authorize<boolean>`
- `receiver?` — `Receiver`
- `logger?` — `Logger`
- `logLevel?` — `LogLevel`
- `ignoreSelf?` — `boolean`
- `clientOptions?` — `WebClientOptions`
- `socketMode?` — `boolean`
- `developerMode?` — `boolean`
- `tokenVerificationEnabled?` — `boolean`
- `deferInitialization?` — `boolean`
- `extendedErrorHandler?` — `boolean`
- `attachFunctionToken?` — `boolean`

## Export groups by source module

### `src/App.ts`

- `ActionConstraints` — re-export from './types'
- `OptionsConstraints` — re-export from './types'
- `ShortcutConstraints` — re-export from './types'
- `ViewConstraints` — re-export from './types'
- `AppOptions` — InterfaceDeclaration
- `Logger` — re-export from '@slack/logger'
- `LogLevel` — re-export from '@slack/logger'
- `Authorize` — TypeAliasDeclaration
- `AuthorizeSourceData` — InterfaceDeclaration
- `AuthorizeResult` — InterfaceDeclaration
- `ExtendedErrorHandlerArgs` — InterfaceDeclaration
- `ErrorHandler` — TypeAliasDeclaration
- `ExtendedErrorHandler` — TypeAliasDeclaration
- `AnyErrorHandler` — InterfaceDeclaration
- `App` — ClassDeclaration

### `src/Assistant.ts`

- `AssistantConfig` — InterfaceDeclaration
- `AssistantThreadStartedMiddleware` — TypeAliasDeclaration
- `AssistantThreadContextChangedMiddleware` — TypeAliasDeclaration
- `AssistantUserMessageMiddleware` — TypeAliasDeclaration
- `AssistantMiddleware` — TypeAliasDeclaration
- `AssistantMiddlewareArgs` — TypeAliasDeclaration
- `AssistantThreadStartedMiddlewareArgs` — InterfaceDeclaration
- `AssistantThreadContextChangedMiddlewareArgs` — InterfaceDeclaration
- `AssistantUserMessageMiddlewareArgs` — InterfaceDeclaration
- `AllAssistantMiddlewareArgs` — TypeAliasDeclaration
- `Assistant` — ClassDeclaration
- `enrichAssistantArgs` — FunctionDeclaration
- `isAssistantEvent` — FunctionDeclaration
- `matchesConstraints` — FunctionDeclaration
- `isAssistantMessage` — FunctionDeclaration
- `validate` — FunctionDeclaration
- `processAssistantMiddleware` — FunctionDeclaration
- `extractThreadInfo` — FunctionDeclaration

### `src/AssistantThreadContextStore.ts`

- `AssistantThreadContextStore` — InterfaceDeclaration
- `GetThreadContextFn` — TypeAliasDeclaration
- `SaveThreadContextFn` — TypeAliasDeclaration
- `AssistantThreadContext` — InterfaceDeclaration
- `DefaultThreadContextStore` — ClassDeclaration

### `src/CustomFunction.ts`

- `FunctionCompleteFn` — TypeAliasDeclaration
- `FunctionFailFn` — TypeAliasDeclaration
- `SlackCustomFunctionMiddlewareArgs` — TypeAliasDeclaration
- `CustomFunctionMiddleware` — TypeAliasDeclaration
- `AllCustomFunctionMiddlewareArgs` — TypeAliasDeclaration
- `matchCallbackId` — FunctionDeclaration
- `CustomFunction` — ClassDeclaration
- `validate` — FunctionDeclaration

### `src/context/create-function-complete.ts`

- `createFunctionComplete` — FunctionDeclaration

### `src/context/create-function-fail.ts`

- `createFunctionFail` — FunctionDeclaration

### `src/context/create-respond.ts`

- `createRespond` — FunctionDeclaration

### `src/context/create-say-stream.ts`

- `SayStreamArguments` — TypeAliasDeclaration
- `SayStreamFn` — TypeAliasDeclaration
- `createSayStream` — FunctionDeclaration

### `src/context/create-say.ts`

- `createSay` — FunctionDeclaration

### `src/context/create-set-status.ts`

- `SetStatusArguments` — TypeAliasDeclaration
- `SetStatusFn` — TypeAliasDeclaration
- `createSetStatus` — FunctionDeclaration

### `src/context/index.ts`

- `createFunctionComplete` — re-export from './create-function-complete'
- `createFunctionFail` — re-export from './create-function-fail'
- `createRespond` — re-export from './create-respond'
- `createSay` — re-export from './create-say'
- `SayStreamArguments` — re-export from './create-say-stream'
- `SayStreamFn` — re-export from './create-say-stream'
- `createSayStream` — re-export from './create-say-stream'
- `SetStatusArguments` — re-export from './create-set-status'
- `SetStatusFn` — re-export from './create-set-status'
- `createSetStatus` — re-export from './create-set-status'

### `src/conversation-store.ts`

- `ConversationStore` — InterfaceDeclaration
- `MemoryStore` — ClassDeclaration
- `conversationContext` — FunctionDeclaration

### `src/errors.ts`

- `CodedError` — InterfaceDeclaration
- `isCodedError` — FunctionDeclaration
- `ErrorCode` — EnumDeclaration
- `UnknownError` — ClassDeclaration
- `asCodedError` — FunctionDeclaration
- `AppInitializationError` — ClassDeclaration
- `AssistantInitializationError` — ClassDeclaration
- `AssistantMissingPropertyError` — ClassDeclaration
- `AuthorizationError` — ClassDeclaration
- `ContextMissingPropertyError` — ClassDeclaration
- `InvalidCustomPropertyError` — ClassDeclaration
- `CustomRouteInitializationError` — ClassDeclaration
- `ReceiverMultipleAckError` — ClassDeclaration
- `ReceiverAuthenticityError` — ClassDeclaration
- `ReceiverInconsistentStateError` — ClassDeclaration
- `HTTPReceiverDeferredRequestError` — ClassDeclaration
- `RespondError` — ClassDeclaration
- `MultipleListenerError` — ClassDeclaration
- `CustomFunctionInitializationError` — ClassDeclaration
- `CustomFunctionCompleteSuccessError` — ClassDeclaration
- `CustomFunctionCompleteFailError` — ClassDeclaration

### `src/helpers.ts`

- `IncomingEventType` — EnumDeclaration
- `getTypeAndConversation` — FunctionDeclaration
- `isBodyWithTypeEnterpriseInstall` — FunctionDeclaration
- `isEventTypeToSkipAuthorize` — FunctionDeclaration
- `assertNever` — FunctionDeclaration
- `extractEventThreadTs` — FunctionDeclaration
- `extractEventTs` — FunctionDeclaration
- `extractEventChannelId` — FunctionDeclaration
- `isRecord` — FunctionDeclaration
- `hasStringProperty` — FunctionDeclaration

### `src/index.ts`

- `Logger` — re-export from './App'
- `ActionConstraints` — re-export from './App'
- `AppOptions` — re-export from './App'
- `Authorize` — re-export from './App'
- `AuthorizeResult` — re-export from './App'
- `AuthorizeSourceData` — re-export from './App'
- `LogLevel` — re-export from './App'
- `App` — re-export
- `isValidSlackRequest` — re-export from './receivers/verify-request'
- `verifySlackRequest` — re-export from './receivers/verify-request'
- `Installation` — re-export from '@slack/oauth'
- `InstallationQuery` — re-export from '@slack/oauth'
- `InstallationStore` — re-export from '@slack/oauth'
- `InstallProviderOptions` — re-export from '@slack/oauth'
- `InstallURLOptions` — re-export from '@slack/oauth'
- `StateStore` — re-export from '@slack/oauth'
- `FileInstallationStore` — re-export from '@slack/oauth'
- `MemoryInstallationStore` — re-export from '@slack/oauth'
- `*` — re-export from '@slack/types'
- `*` — re-export from '@slack/web-api'
- `Assistant` — re-export from './Assistant'
- `AssistantConfig` — re-export from './Assistant'
- `AssistantThreadContextChangedMiddleware` — re-export from './Assistant'
- `AssistantThreadStartedMiddleware` — re-export from './Assistant'
- `AssistantUserMessageMiddleware` — re-export from './Assistant'
- `SayStreamArguments` — re-export from './context/create-say-stream'
- `SayStreamFn` — re-export from './context/create-say-stream'
- `SetStatusArguments` — re-export from './context/create-set-status'
- `SetStatusFn` — re-export from './context/create-set-status'
- `ConversationStore` — re-export from './conversation-store'
- `MemoryStore` — re-export from './conversation-store'
- `*` — re-export from './errors'
- `*` — re-export from './middleware/builtin'
- `AwsLambdaReceiverOptions` — re-export from './receivers/AwsLambdaReceiver'
- `BufferedIncomingMessage` — re-export from './receivers/BufferedIncomingMessage'
- `buildReceiverRoutes` — re-export from './receivers/custom-routes'
- `CustomRoute` — re-export from './receivers/custom-routes'
- `ReceiverRoutes` — re-export from './receivers/custom-routes'
- `ExpressReceiverOptions` — re-export from './receivers/ExpressReceiver'
- `*` — re-export from './receivers/HTTPModuleFunctions'
- `defaultBodyLimit` — re-export from './receivers/HTTPModuleFunctions'
- `isRawBodyError` — re-export from './receivers/HTTPModuleFunctions'
- `ReceiverDispatchErrorHandlerArgs` — re-export from './receivers/HTTPModuleFunctions'
- `ReceiverProcessEventErrorHandlerArgs` — re-export from './receivers/HTTPModuleFunctions'
- `ReceiverUnhandledRequestHandlerArgs` — re-export from './receivers/HTTPModuleFunctions'
- `RequestVerificationOptions` — re-export from './receivers/HTTPModuleFunctions'
- `HTTPReceiverOptions` — re-export from './receivers/HTTPReceiver'
- `HTTPResponseAck` — re-export from './receivers/HTTPResponseAck'
- `defaultProcessEventErrorHandler` — re-export from './receivers/SocketModeFunctions'
- `SocketModeReceiverProcessEventErrorHandlerArgs` — re-export from './receivers/SocketModeFunctions'
- `SocketModeReceiverOptions` — re-export from './receivers/SocketModeReceiver'
- `*` — re-export from './types'
- `AwsLambdaReceiver` — re-export
- `ExpressReceiver` — re-export
- `HTTPReceiver` — re-export
- `SocketModeReceiver` — re-export

### `src/middleware/builtin.ts`

- `isSlackEventMiddlewareArgsOptions` — FunctionDeclaration
- `onlyActions` — VariableDeclaration
- `onlyShortcuts` — VariableDeclaration
- `onlyCommands` — VariableDeclaration
- `onlyOptions` — VariableDeclaration
- `onlyEvents` — VariableDeclaration
- `onlyViewActions` — VariableDeclaration
- `autoAcknowledge` — VariableDeclaration
- `matchConstraints` — FunctionDeclaration
- `matchMessage` — FunctionDeclaration
- `matchCommandName` — FunctionDeclaration
- `matchEventType` — FunctionDeclaration
- `ignoreSelf` — VariableDeclaration
- `subtype` — FunctionDeclaration
- `directMention` — VariableDeclaration

### `src/middleware/process.ts`

- `processMiddleware` — FunctionDeclaration

### `src/receivers/AwsLambdaReceiver.ts`

- `AwsEvent` — TypeAliasDeclaration
- `AwsEventV1` — InterfaceDeclaration
- `AwsEventV2` — InterfaceDeclaration
- `AwsCallback` — TypeAliasDeclaration
- `ReceiverInvalidRequestSignatureHandlerArgs` — InterfaceDeclaration
- `AwsResponse` — InterfaceDeclaration
- `AwsHandler` — TypeAliasDeclaration
- `AwsLambdaReceiverOptions` — InterfaceDeclaration
- `AwsLambdaReceiver` — ClassDeclaration

### `src/receivers/BufferedIncomingMessage.ts`

- `BufferedIncomingMessage` — InterfaceDeclaration

### `src/receivers/ExpressReceiver.ts`

- `respondToSslCheck` — VariableDeclaration
- `respondToUrlVerification` — VariableDeclaration
- `ExpressReceiverOptions` — InterfaceDeclaration
- `ExpressReceiver` — ClassDeclaration
- `verifySignatureAndParseRawBody` — FunctionDeclaration
- `verifySignatureAndParseBody` — FunctionDeclaration
- `buildBodyParserMiddleware` — FunctionDeclaration

### `src/receivers/HTTPModuleFunctions.ts`

- `defaultBodyLimit` — VariableDeclaration
- `isRawBodyError` — VariableDeclaration
- `extractRetryNumFromHTTPRequest` — VariableDeclaration
- `extractRetryReasonFromHTTPRequest` — VariableDeclaration
- `parseHTTPRequestBody` — VariableDeclaration
- `parseAndVerifyHTTPRequest` — VariableDeclaration
- `isBufferedIncomingMessage` — VariableDeclaration
- `getHeader` — VariableDeclaration
- `bufferIncomingMessage` — VariableDeclaration
- `buildNoBodyResponse` — VariableDeclaration
- `buildUrlVerificationResponse` — VariableDeclaration
- `buildSSLCheckResponse` — VariableDeclaration
- `buildContentResponse` — VariableDeclaration
- `defaultDispatchErrorHandler` — VariableDeclaration
- `defaultAsyncDispatchErrorHandler` — VariableDeclaration
- `defaultProcessEventErrorHandler` — VariableDeclaration
- `defaultUnhandledRequestHandler` — VariableDeclaration
- `RequestVerificationOptions` — InterfaceDeclaration
- `ReceiverDispatchErrorHandlerArgs` — InterfaceDeclaration
- `ReceiverProcessEventErrorHandlerArgs` — InterfaceDeclaration
- `ReceiverUnhandledRequestHandlerArgs` — InterfaceDeclaration

### `src/receivers/HTTPReceiver.ts`

- `HTTPReceiverOptions` — InterfaceDeclaration
- `HTTPReceiverInstallerOptions` — InterfaceDeclaration
- `HTTPReceiver` — ClassDeclaration

### `src/receivers/HTTPResponseAck.ts`

- `AckArgs` — InterfaceDeclaration
- `HTTResponseBody` — TypeAliasDeclaration
- `HTTPResponseAck` — ClassDeclaration

### `src/receivers/ParamsIncomingMessage.ts`

- `ParamsIncomingMessage` — InterfaceDeclaration

### `src/receivers/SocketModeFunctions.ts`

- `defaultProcessEventErrorHandler` — FunctionDeclaration
- `SocketModeReceiverProcessEventErrorHandlerArgs` — InterfaceDeclaration

### `src/receivers/SocketModeReceiver.ts`

- `SocketModeReceiverOptions` — InterfaceDeclaration
- `CustomRoute` — InterfaceDeclaration
- `SocketModeReceiver` — ClassDeclaration

### `src/receivers/SocketModeResponseAck.ts`

- `SocketModeResponseBody` — TypeAliasDeclaration
- `AckArgs` — InterfaceDeclaration
- `SocketModeResponseAck` — ClassDeclaration

### `src/receivers/custom-routes.ts`

- `CustomRoute` — InterfaceDeclaration
- `ReceiverRoutes` — InterfaceDeclaration
- `buildReceiverRoutes` — FunctionDeclaration

### `src/receivers/verify-redirect-opts.ts`

- `RedirectOptions` — InterfaceDeclaration
- `verifyRedirectOpts` — FunctionDeclaration

### `src/receivers/verify-request.ts`

- `SlackRequestVerificationOptions` — InterfaceDeclaration
- `verifySlackRequest` — FunctionDeclaration
- `isValidSlackRequest` — FunctionDeclaration

### `src/receivers/verify-signing-secret.ts`

- `verifySigningSecret` — FunctionDeclaration

### `src/types/actions/block-action.ts`

- `BlockElementAction` — TypeAliasDeclaration
- `BasicElementAction` — InterfaceDeclaration
- `ButtonAction` — InterfaceDeclaration
- `FeedbackButtonsAction` — InterfaceDeclaration
- `IconButtonAction` — InterfaceDeclaration
- `StaticSelectAction` — InterfaceDeclaration
- `MultiStaticSelectAction` — InterfaceDeclaration
- `UsersSelectAction` — InterfaceDeclaration
- `MultiUsersSelectAction` — InterfaceDeclaration
- `ConversationsSelectAction` — InterfaceDeclaration
- `MultiConversationsSelectAction` — InterfaceDeclaration
- `ChannelsSelectAction` — InterfaceDeclaration
- `MultiChannelsSelectAction` — InterfaceDeclaration
- `ExternalSelectAction` — InterfaceDeclaration
- `MultiExternalSelectAction` — InterfaceDeclaration
- `OverflowAction` — InterfaceDeclaration
- `DatepickerAction` — InterfaceDeclaration
- `TimepickerAction` — InterfaceDeclaration
- `RadioButtonsAction` — InterfaceDeclaration
- `CheckboxesAction` — InterfaceDeclaration
- `PlainTextInputAction` — InterfaceDeclaration
- `RichTextInputAction` — InterfaceDeclaration
- `BlockAction` — InterfaceDeclaration
- `BlockButtonAction` — TypeAliasDeclaration
- `BlockFeedbackButtonsAction` — TypeAliasDeclaration
- `BlockIconButtonAction` — TypeAliasDeclaration
- `BlockStaticSelectAction` — TypeAliasDeclaration
- `BlockUsersSelectAction` — TypeAliasDeclaration
- `BlockConversationsSelectAction` — TypeAliasDeclaration
- `BlockChannelsSelectAction` — TypeAliasDeclaration
- `BlockExternalSelectAction` — TypeAliasDeclaration
- `BlockOverflowAction` — TypeAliasDeclaration
- `BlockDatepickerAction` — TypeAliasDeclaration
- `BlockTimepickerAction` — TypeAliasDeclaration
- `BlockRadioButtonsAction` — TypeAliasDeclaration
- `BlockCheckboxesAction` — TypeAliasDeclaration
- `BlockPlainTextInputAction` — TypeAliasDeclaration

### `src/types/actions/dialog-action.ts`

- `DialogSubmitAction` — InterfaceDeclaration
- `DialogValidation` — InterfaceDeclaration

### `src/types/actions/index.ts`

- `*` — re-export from './block-action'
- `*` — re-export from './dialog-action'
- `*` — re-export from './interactive-message'
- `SlackAction` — TypeAliasDeclaration
- `ActionConstraints` — InterfaceDeclaration
- `SlackActionMiddlewareArgs` — TypeAliasDeclaration

### `src/types/actions/interactive-message.ts`

- `InteractiveAction` — TypeAliasDeclaration
- `ButtonClick` — InterfaceDeclaration
- `MenuSelect` — InterfaceDeclaration
- `InteractiveMessage` — InterfaceDeclaration
- `InteractiveButtonClick` — TypeAliasDeclaration
- `InteractiveMenuSelect` — TypeAliasDeclaration

### `src/types/command/index.ts`

- `SlackCommandMiddlewareArgs` — InterfaceDeclaration
- `SlashCommand` — InterfaceDeclaration

### `src/types/events/index.ts`

- `SlackEventMiddlewareArgsOptions` — TypeAliasDeclaration
- `SlackEventMiddlewareArgs` — TypeAliasDeclaration
- `BaseSlackEvent` — InterfaceDeclaration
- `EventTypePattern` — TypeAliasDeclaration
- `FunctionInputs` — TypeAliasDeclaration
- `EnvelopedEvent` — InterfaceDeclaration
- `EventFromType` — TypeAliasDeclaration
- `KnownEventFromType` — TypeAliasDeclaration

### `src/types/index.ts`

- `*` — re-export from './actions'
- `*` — re-export from './command'
- `*` — re-export from './events'
- `*` — re-export from './middleware'
- `*` — re-export from './options'
- `*` — re-export from './receiver'
- `*` — re-export from './shortcuts'
- `*` — re-export from './utilities'
- `*` — re-export from './view'

### `src/types/middleware.ts`

- `AnyMiddlewareArgs` — TypeAliasDeclaration
- `AllMiddlewareArgs` — InterfaceDeclaration
- `Middleware` — TypeAliasDeclaration
- `Context` — InterfaceDeclaration
- `contextBuiltinKeys` — VariableDeclaration
- `NextFn` — TypeAliasDeclaration

### `src/types/options/index.ts`

- `SlackOptionsMiddlewareArgs` — InterfaceDeclaration
- `SlackOptions` — TypeAliasDeclaration
- `OptionsConstraints` — InterfaceDeclaration
- `OptionsSource` — TypeAliasDeclaration
- `BasicOptionsPayload` — InterfaceDeclaration
- `OptionsPayloadFromType` — TypeAliasDeclaration
- `KnownOptionsPayloadFromType` — TypeAliasDeclaration
- `BlockSuggestion` — InterfaceDeclaration
- `InteractiveMessageSuggestion` — InterfaceDeclaration
- `DialogSuggestion` — InterfaceDeclaration
- `BlockOptions` — InterfaceDeclaration
- `MessageOptions` — InterfaceDeclaration
- `DialogOptions` — InterfaceDeclaration
- `OptionGroups` — InterfaceDeclaration
- `DialogOptionGroups` — InterfaceDeclaration

### `src/types/receiver.ts`

- `ReceiverEvent` — InterfaceDeclaration
- `Receiver` — InterfaceDeclaration
- `ResponseAck` — InterfaceDeclaration

### `src/types/shortcuts/global-shortcut.ts`

- `GlobalShortcut` — InterfaceDeclaration

### `src/types/shortcuts/index.ts`

- `*` — re-export from './global-shortcut'
- `*` — re-export from './message-shortcut'
- `SlackShortcut` — TypeAliasDeclaration
- `ShortcutConstraints` — InterfaceDeclaration
- `SlackShortcutMiddlewareArgs` — TypeAliasDeclaration

### `src/types/shortcuts/message-shortcut.ts`

- `MessageShortcut` — InterfaceDeclaration

### `src/types/utilities.ts`

- `StringIndexed` — TypeAliasDeclaration
- `XOR` — TypeAliasDeclaration
- `isFulfilled` — VariableDeclaration
- `isRejected` — VariableDeclaration
- `SayArguments` — TypeAliasDeclaration
- `SayFn` — TypeAliasDeclaration
- `RespondArguments` — TypeAliasDeclaration
- `RespondFn` — TypeAliasDeclaration
- `AckFn` — TypeAliasDeclaration

### `src/types/view/index.ts`

- `SlackViewAction` — TypeAliasDeclaration
- `ViewConstraints` — InterfaceDeclaration
- `SlackViewMiddlewareArgs` — InterfaceDeclaration
- `ViewResponseUrl` — InterfaceDeclaration
- `ViewSubmitAction` — InterfaceDeclaration
- `ViewClosedAction` — InterfaceDeclaration
- `ViewStateSelectedOption` — InterfaceDeclaration
- `UploadedFile` — InterfaceDeclaration
- `ViewStateValue` — InterfaceDeclaration
- `ViewOutput` — InterfaceDeclaration
- `ViewUpdateResponseAction` — InterfaceDeclaration
- `ViewPushResponseAction` — InterfaceDeclaration
- `ViewClearResponseAction` — InterfaceDeclaration
- `ViewErrorsResponseAction` — InterfaceDeclaration
- `ViewResponseAction` — TypeAliasDeclaration

The complete machine-readable catalog is `typescript-api-catalog.json`; source locations are pinned to the fetched upstream archive.
