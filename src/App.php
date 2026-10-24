<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Throwable;
use Thunder\Bolt\Attributes\Action as ActionAttribute;
use Thunder\Bolt\Attributes\Event as EventAttribute;
use Thunder\Bolt\Attributes\Message as MessageAttribute;
use Thunder\Bolt\Attributes\Options as OptionsAttribute;
use Thunder\Bolt\Attributes\Shortcut as ShortcutAttribute;
use Thunder\Bolt\Attributes\SlackFunction as FunctionAttribute;
use Thunder\Bolt\Attributes\SlashCommand as SlashCommandAttribute;
use Thunder\Bolt\Attributes\View as ViewAttribute;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Authorization\Authorizer;
use Thunder\Bolt\Authorization\CallbackAuthorizer;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\DeliveryContext;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Exceptions\DoubleAcknowledgement;
use Thunder\Bolt\Exceptions\DuplicateListener;
use Thunder\Bolt\Exceptions\HandlerFailures;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Exceptions\MissingAcknowledgement;
use Thunder\Bolt\Exceptions\MissingCommandResponse;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Functions\ExecutionContext;
use Thunder\Bolt\Invocation\AcknowledgementValidator;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Invocation\HandlerInvoker;
use Thunder\Bolt\Invocation\Interaction;
use Thunder\Bolt\Invocation\InvocationPipeline;
use Thunder\Bolt\Invocation\InvocationScope;
use Thunder\Bolt\Middleware\Middleware;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\Event as GenericEvent;
use Thunder\Bolt\Payloads\Events\AgentSessionEvent;
use Thunder\Bolt\Payloads\Events\AgentSessionStopped;
use Thunder\Bolt\Payloads\Events\AgentSessionTitleChanged;
use Thunder\Bolt\Payloads\Events\AppMention;
use Thunder\Bolt\Payloads\Events\AppUninstalled;
use Thunder\Bolt\Payloads\Events\AssistantThreadContextChanged;
use Thunder\Bolt\Payloads\Events\AssistantThreadEvent;
use Thunder\Bolt\Payloads\Events\AssistantThreadStarted;
use Thunder\Bolt\Payloads\Events\ChannelMembership;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\Events\EntityDetailsRequested;
use Thunder\Bolt\Payloads\Events\EntityRequest;
use Thunder\Bolt\Payloads\Events\FileShared;
use Thunder\Bolt\Payloads\Events\FunctionExecuted;
use Thunder\Bolt\Payloads\Events\LinkShared;
use Thunder\Bolt\Payloads\Events\MemberJoinedChannel;
use Thunder\Bolt\Payloads\Events\MemberLeftChannel;
use Thunder\Bolt\Payloads\Events\Message as MessageEvent;
use Thunder\Bolt\Payloads\Events\ReactionAdded;
use Thunder\Bolt\Payloads\Events\ReactionEvent;
use Thunder\Bolt\Payloads\Events\ReactionRemoved;
use Thunder\Bolt\Payloads\Events\TokensRevoked;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Payloads\Shortcut;
use Thunder\Bolt\Payloads\SlashCommand;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\Acknowledgement;
use Thunder\Bolt\Routing\HandlerTarget;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\ListenerDescriptor;
use Thunder\Bolt\Routing\ListenerKind;
use Thunder\Bolt\Routing\Matcher;
use Thunder\Bolt\Routing\Regex;
use Thunder\Bolt\WebApi\Client;

final class App
{
    /** @var list<ListenerDescriptor> */
    private array $registeredListeners = [];

    /** @var array<string, string> */
    private array $listenerSources = [];

    /** @var list<callable|Middleware> */
    private array $globalMiddleware = [];

    private ?Closure $errorHandler = null;

    private ?Assistant $registeredAssistant = null;

    private readonly HandlerInvoker $invoker;

    private readonly InvocationPipeline $pipeline;

    private readonly ConversationStore|false $conversationStore;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Container $container,
        private readonly Client $client,
        private readonly BoltConfig $config,
        private ?Authorizer $authorizer = null,
        ConversationStore|false|null $conversationStore = null,
        ?LoggerInterface $logger = null,
    ) {
        if ($authorizer !== null && !$container instanceof GenericContainer) {
            throw new \InvalidArgumentException('Delivery-scoped authorization requires Tempest GenericContainer.');
        }
        $this->invoker = new HandlerInvoker($container);
        $this->pipeline = new InvocationPipeline();
        $this->conversationStore = $conversationStore ?? new MemoryConversationStore();
        $this->logger = $logger ?? new NullLogger();
    }

    public function use(Middleware|callable $middleware): self
    {
        $this->globalMiddleware[] = $middleware;

        return $this;
    }

    public function assistant(Assistant $assistant): self
    {
        if ($this->registeredAssistant !== null) {
            throw new \LogicException('An application can register one assistant.');
        }
        $assistant->validate();
        foreach ([
            'assistant_thread_started' => $assistant->started,
            'assistant_thread_context_changed' => $assistant->contextChanged,
            'message' => $assistant->message,
        ] as $event => $handler) {
            if ($handler !== null) {
                $this->validatePayloadCompatibility(ListenerDescriptor::event($event, $handler->target));
            }
        }
        $this->registeredAssistant = $assistant;
        $store = $this->conversationStore === false
            ? new \Thunder\Bolt\Assistant\Stores\SlackMetadataThreadContextStore()
            : new \Thunder\Bolt\Assistant\Stores\StoredThreadContextStore($this->conversationStore);
        $runtime = new \Thunder\Bolt\Assistant\Runtime($assistant, $this->container, $this->invoker, $store);
        return $this->use(fn(Delivery $delivery, Closure $next): mixed => $runtime->handle(
            $delivery,
            $next,
            $this->authorizer !== null,
        ));
    }

    public function error(callable $handler): self
    {
        $this->errorHandler = Closure::fromCallable($handler);

        return $this;
    }

    public function authorizeUsing(Authorizer|callable $authorizer): self
    {
        if (!$this->container instanceof GenericContainer) {
            throw new \InvalidArgumentException('Delivery-scoped authorization requires Tempest GenericContainer.');
        }
        $this->authorizer = $authorizer instanceof Authorizer
            ? $authorizer
            : new CallbackAuthorizer(Closure::fromCallable($authorizer));

        return $this;
    }

    public function hasAuthorization(?array $body = null): bool
    {
        return (
            $this->authorizer !== null
            || $this->config->token !== ''
            || $body !== null
            && ExecutionContext::fromArray($body)?->workflowToken() !== null
        );
    }

    public function hasFunctionListeners(): bool
    {
        return array_any(
            $this->registeredListeners,
            static fn(ListenerDescriptor $listener): bool => (
                $listener->kind === ListenerKind::Function
                || $listener->kind === ListenerKind::Event
                && $listener->selector === 'function_executed'
            ),
        );
    }

    public function function(string $callbackId, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));
        return $this->registerDescriptor(ListenerDescriptor::function(
            $callbackId,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    /** @param string|Regex $selector @param (callable|Middleware|string|array) ...$middlewareAndHandler */
    public function event(string|Regex $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::event(
            is_string($selector) ? $selector : $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    /** @param string|Regex $selector @param (callable|Middleware|string|array) ...$middlewareAndHandler */
    public function message(string|Regex $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::message(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    /** @param string|Regex $selector @param (callable|Middleware|string|array) ...$middlewareAndHandler */
    public function command(string|Regex $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::command(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    public function action(string|Regex|InteractionConstraints $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::action(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    public function shortcut(string|Regex|InteractionConstraints $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::shortcut(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    public function options(string|Regex|InteractionConstraints $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::options(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    public function view(string|Regex|InteractionConstraints $selector, mixed ...$middlewareAndHandler): self
    {
        [$handler, $middleware] = $this->registrationArguments(array_values($middlewareAndHandler));

        return $this->registerDescriptor(ListenerDescriptor::view(
            $selector,
            HandlerTarget::from($handler),
            $middleware,
        ));
    }

    /**
     * Cache-safe listener registration seam used by BoltDiscovery.
     *
     * @internal Application authors should use the fluent listener methods or method attributes.
     */
    public function registerDescriptor(ListenerDescriptor $descriptor): self
    {
        $this->validatePayloadCompatibility($descriptor);
        $key = $descriptor->key();

        if (isset($this->listenerSources[$key])) {
            throw DuplicateListener::forSources($this->listenerSources[$key], $descriptor->source);
        }

        $this->listenerSources[$key] = $descriptor->source;
        $this->registeredListeners[] = $descriptor;

        return $this;
    }

    /** Register attributes on a class when Tempest discovery is not in use. */
    public function registerClass(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Listener class {$class} does not exist.");
        }

        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $target = HandlerTarget::classMethod($class, $method->getName());
            $source = "{$class}::{$method->getName()}";

            foreach ($method->getAttributes(EventAttribute::class) as $attribute) {
                $this->registerDescriptor(ListenerDescriptor::event(
                    $attribute->newInstance()->type,
                    $target,
                    source: "{$source} #[Event]",
                ));
            }
            foreach ($method->getAttributes(FunctionAttribute::class) as $attribute) {
                $this->registerDescriptor(ListenerDescriptor::function(
                    $attribute->newInstance()->callbackId,
                    $target,
                    source: "{$source} #[SlackFunction]",
                ));
            }

            foreach ($method->getAttributes(MessageAttribute::class) as $attribute) {
                $this->registerDescriptor(ListenerDescriptor::message(
                    $attribute->newInstance()->contains,
                    $target,
                    source: "{$source} #[Message]",
                ));
            }

            foreach ($method->getAttributes(SlashCommandAttribute::class) as $attribute) {
                $this->registerDescriptor(ListenerDescriptor::command(
                    $attribute->newInstance()->name,
                    $target,
                    source: "{$source} #[SlashCommand]",
                ));
            }

            foreach ([
                ActionAttribute::class => 'action',
                ShortcutAttribute::class => 'shortcut',
                OptionsAttribute::class => 'options',
                ViewAttribute::class => 'view',
            ] as $attributeClass => $factory) {
                foreach ($method->getAttributes($attributeClass) as $attribute) {
                    $value = $attribute->newInstance();
                    $constraints =
                        $value instanceof ActionAttribute || $value instanceof OptionsAttribute
                            ? new InteractionConstraints(
                                actionId: $value->actionId === '' ? null : $value->actionId,
                                blockId: $value->blockId,
                                callbackId: $value->callbackId,
                                type: $value->type,
                            )
                            : new InteractionConstraints(callbackId: $value->callbackId, type: $value->type);
                    $this->registerDescriptor(ListenerDescriptor::{$factory}(
                        $constraints,
                        $target,
                        source: "{$source} #[{$factory}]",
                    ));
                }
            }
        }

        return $this;
    }

    /**
     * A stable CLI-oriented summary. Every item has `kind`, `selector`, `target`, and `source`.
     *
     * @return list<array{kind:string, selector:string, target:string, source:string}>
     */
    public function listeners(): array
    {
        $listeners = array_map(
            static fn(ListenerDescriptor $listener): array => $listener->summary(),
            $this->registeredListeners,
        );
        if ($this->registeredAssistant !== null) {
            foreach ([
                'assistant_thread_started' => $this->registeredAssistant->started,
                'assistant_thread_context_changed' => $this->registeredAssistant->contextChanged,
                'message.im (thread replies)' => $this->registeredAssistant->message,
            ] as $selector => $handler) {
                $listeners[] = [
                    'kind' => 'assistant',
                    'selector' => $selector,
                    'target' => $handler?->target->key() ?? 'default context persistence',
                    'source' => 'Assistant ' . ($handler?->target->key() ?? 'default context persistence'),
                ];
            }
        }
        return $listeners;
    }

    /**
     * Dispatch a trusted, parsed Slack payload. HTTP signature verification belongs to Http\Receiver.
     *
     * @param  array<array-key, mixed>  $body
     * @param  array<array-key, mixed>  $metadata
     */
    public function dispatch(array $body, array $metadata = []): ?Acknowledgement
    {
        $payload = $this->payloadFrom($body);
        $functionContext = $payload instanceof FunctionExecuted
            ? $payload->execution
            : ExecutionContext::fromArray($body);
        $source = AuthorizationSource::fromArray($body);
        try {
            $lifecycle =
                ($body['type'] ?? null) === 'event_callback'
                && in_array($body['event']['type'] ?? null, ['app_uninstalled', 'tokens_revoked'], true);
            $authorization = $lifecycle
                ? new Authorization(
                    teamId: $source->teamId,
                    enterpriseId: $source->enterpriseId,
                    isEnterpriseInstall: $source->isEnterpriseInstall,
                )
                : $this->authorizer?->authorize($source) ?? new Authorization(
                    botToken: $this->config->token,
                    botUserId: $this->config->botUserId,
                );
            $client = $this->client->withToken(
                $lifecycle ? '' : $functionContext?->workflowToken() ?? $authorization->token(),
            );
        } catch (Throwable $exception) {
            $this->reportFailures([$exception], $payload, $metadata);

            // Reporting an authorization failure cannot turn an unauthorised
            // delivery into a handler invocation or successful acknowledgement.
            throw $exception;
        }

        if ($this->shouldIgnore($payload, $authorization)) {
            return null;
        }

        $reply = $this->replyFor($payload, $client);
        $context = DeliveryContext::fromArray($body, $source, $authorization);
        $store = $this->conversationStore === false ? null : $this->conversationStore;
        $conversation = new ConversationContext($store, $context->conversation, $this->logger);
        $thread = new ThreadContext($store, $context->thread, $this->logger);
        $scope =
            $this->authorizer === null && $functionContext?->workflowToken() === null
                ? null
                : InvocationScope::create($this->container, $client, $authorization);
        $execution = $functionContext === null ? null : new Execution($client, $functionContext);
        $responses = [];
        $failures = [];

        try {
            $globalResult = $this->pipeline->run(
                $this->globalMiddleware,
                new Delivery(
                    $payload,
                    $reply,
                    $metadata,
                    null,
                    client: $client,
                    authorization: $authorization,
                    authorizationSource: $source,
                    conversation: $conversation,
                    thread: $thread,
                    execution: $execution,
                ),
                function () use (
                    $payload,
                    $reply,
                    $metadata,
                    $client,
                    $authorization,
                    $source,
                    $scope,
                    $conversation,
                    $thread,
                    $execution,
                    &$responses,
                    &$failures,
                ): mixed {
                    foreach ($this->registeredListeners as $listener) {
                        $matches = Matcher::match($listener, $payload);
                        if ($matches === null) {
                            continue;
                        }

                        try {
                            $delivery = new Delivery(
                                $payload,
                                $reply,
                                $metadata,
                                $listener,
                                $matches['matches'],
                                $matches['constraintMatches'],
                                $client,
                                $authorization,
                                $source,
                                $conversation,
                                $thread,
                                execution: $execution,
                            );
                            $result = $this->pipeline->run(
                                $listener->middleware,
                                $delivery,
                                fn(): mixed => $this->invoker->invoke($listener->handler, $delivery, $scope),
                            );

                            if ($result instanceof Acknowledgement) {
                                $responses[] = AcknowledgementValidator::forPayload($payload, $result);
                            }
                        } catch (Throwable $throwable) {
                            $failures[] = $throwable;
                        }
                    }

                    return null;
                },
            );

            if ($globalResult instanceof Acknowledgement) {
                $responses[] = AcknowledgementValidator::forPayload($payload, $globalResult);
            }
        } catch (Throwable $throwable) {
            $failures[] = $throwable;
        }

        $this->reportFailures($failures, $payload, $metadata);

        $expected = AcknowledgementValidator::expected($payload);
        if ($expected === null) {
            return null;
        }
        $responseName = substr($expected, (int) strrpos($expected, '\\') + 1);
        $identity = $payload instanceof SlashCommand ? $payload->command : $payload->type;
        if (!is_string($identity)) {
            throw new InvalidPayload('Slack payload acknowledgement identity must be a string.');
        }

        return match (count($responses)) {
            0 => throw $payload instanceof SlashCommand
                ? new MissingCommandResponse($identity)
                : new MissingAcknowledgement($identity, $responseName),
            1 => $responses[0],
            default => throw new DoubleAcknowledgement($identity, $responseName),
        };
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{0: Closure|string|array<mixed>, 1: list<callable|Middleware>}
     */
    private function registrationArguments(array $arguments): array
    {
        if ($arguments === []) {
            throw InvalidListener::invalidHandler();
        }

        $handler = array_pop($arguments);
        if (is_callable($handler) && !$handler instanceof Closure) {
            $handler = Closure::fromCallable($handler);
        }
        if (!$handler instanceof Closure && !is_string($handler) && !is_array($handler)) {
            throw InvalidListener::invalidHandler();
        }

        $middleware = [];
        foreach ($arguments as $candidate) {
            if (!$candidate instanceof Middleware && !is_callable($candidate)) {
                throw new \InvalidArgumentException('Listener middleware must implement Middleware or be callable.');
            }
            $middleware[] = $candidate;
        }

        return [$handler, $middleware];
    }

    private function payloadFrom(array $body): object
    {
        if (array_key_exists('command', $body)) {
            return SlashCommand::fromArray($body);
        }
        if (Interaction::kind($body) !== null) {
            return Interaction::fromArray($body);
        }

        $event = is_array($body['event'] ?? null) ? $body['event'] : $body;

        return match ($event['type'] ?? null) {
            'app_mention' => AppMention::fromArray($event),
            'message' => MessageEvent::fromArray($event),
            'function_executed' => FunctionExecuted::fromArray($event),
            'assistant_thread_started' => AssistantThreadStarted::fromArray($event),
            'assistant_thread_context_changed' => AssistantThreadContextChanged::fromArray($event),
            'entity_details_requested' => EntityDetailsRequested::fromArray($event),
            'entity_comments_requested' => EntityCommentsRequested::fromArray($event),
            'reaction_added' => ReactionAdded::fromArray($event),
            'reaction_removed' => ReactionRemoved::fromArray($event),
            'member_joined_channel' => MemberJoinedChannel::fromArray($event),
            'member_left_channel' => MemberLeftChannel::fromArray($event),
            'link_shared' => LinkShared::fromArray($event),
            'file_shared' => FileShared::fromArray($event),
            'app_uninstalled' => AppUninstalled::fromArray($event),
            'tokens_revoked' => TokensRevoked::fromArray($event),
            'agent_session_stopped' => AgentSessionStopped::fromArray($event),
            'agent_session_title_changed' => AgentSessionTitleChanged::fromArray($event),
            default => GenericEvent::fromArray($event),
        };
    }

    private function shouldIgnore(object $payload, Authorization $authorization): bool
    {
        if (!$this->config->ignoreSelf) {
            return false;
        }
        if (
            $payload instanceof MessageEvent
            && $authorization->botId !== null
            && ($payload->raw['subtype'] ?? null) === 'bot_message'
            && ($payload->raw['bot_id'] ?? null) === $authorization->botId
        ) {
            return true;
        }
        if (
            $payload instanceof GenericEvent
            && in_array($payload->type, ['member_joined_channel', 'member_left_channel'], true)
        ) {
            return false;
        }

        $userId = match (true) {
            $payload instanceof AppMention,
            $payload instanceof MessageEvent,
            $payload instanceof GenericEvent,
                => $payload->userId,
            default => null,
        };

        return $authorization->botUserId !== null && $userId === $authorization->botUserId;
    }

    private function replyFor(object $payload, Client $client): Reply
    {
        [$channelId, $threadTs, $userId] = match (true) {
            $payload instanceof AppMention, $payload instanceof MessageEvent => [
                $payload->channelId,
                $payload->threadTs ?? $payload->ts,
                $payload->userId,
            ],
            $payload instanceof GenericEvent => [
                $payload->channelId,
                $payload->threadTs ?? $payload->ts,
                $payload->userId,
            ],
            $payload instanceof SlashCommand => [$payload->channelId, null, $payload->userId],
            $payload instanceof Action, $payload instanceof Shortcut => [
                $payload->channelId,
                $payload->threadTs ?? $payload->messageTs,
                $payload->userId,
            ],
            default => [null, null, null],
        };

        $responseUrl = match (true) {
            $payload instanceof SlashCommand,
            $payload instanceof Action,
            $payload instanceof Shortcut,
                => $payload->responseUrl,
            $payload instanceof View => $payload->raw['response_url']
            ?? $payload->raw['response_urls'][0]['response_url']
            ?? null,
            default => null,
        };

        return new Reply(
            $client,
            $channelId,
            $threadTs,
            $userId,
            is_string($responseUrl) && $responseUrl !== '' ? $responseUrl : null,
        );
    }

    /**
     * @param  list<Throwable>  $failures
     * @param  array<array-key, mixed>  $metadata
     */
    private function reportFailures(array $failures, object $payload, array $metadata): void
    {
        if ($failures === []) {
            return;
        }

        $aggregate = new HandlerFailures($failures);
        if ($this->errorHandler === null) {
            throw count($failures) === 1 ? $failures[0] : $aggregate;
        }

        try {
            ($this->errorHandler)($aggregate, $payload, $metadata);
        } catch (Throwable $handlerFailure) {
            throw new HandlerFailures([...$failures, $handlerFailure]);
        }
    }

    private function validatePayloadCompatibility(ListenerDescriptor $descriptor): void
    {
        if ($descriptor->kind === ListenerKind::Event && $descriptor->selector instanceof Regex) {
            return;
        }

        $expectedPayload = match (true) {
            $descriptor->kind === ListenerKind::Message => MessageEvent::class,
            $descriptor->kind === ListenerKind::SlashCommand => SlashCommand::class,
            $descriptor->kind === ListenerKind::Action => Action::class,
            $descriptor->kind === ListenerKind::Shortcut => Shortcut::class,
            $descriptor->kind === ListenerKind::Options => Options::class,
            $descriptor->kind === ListenerKind::View => View::class,
            $descriptor->kind === ListenerKind::Function => FunctionExecuted::class,
            default => match ($descriptor->selector) {
                'app_mention' => AppMention::class,
                'message' => MessageEvent::class,
                'function_executed' => FunctionExecuted::class,
                'assistant_thread_started' => AssistantThreadStarted::class,
                'assistant_thread_context_changed' => AssistantThreadContextChanged::class,
                'entity_details_requested' => EntityDetailsRequested::class,
                'entity_comments_requested' => EntityCommentsRequested::class,
                'reaction_added' => ReactionAdded::class,
                'reaction_removed' => ReactionRemoved::class,
                'member_joined_channel' => MemberJoinedChannel::class,
                'member_left_channel' => MemberLeftChannel::class,
                'link_shared' => LinkShared::class,
                'file_shared' => FileShared::class,
                'app_uninstalled' => AppUninstalled::class,
                'tokens_revoked' => TokensRevoked::class,
                'agent_session_stopped' => AgentSessionStopped::class,
                'agent_session_title_changed' => AgentSessionTitleChanged::class,
                default => GenericEvent::class,
            },
        };

        $declaredPayloadTypes = [];
        foreach ($this->handlerReflection($descriptor->handler)->getParameters() as $parameter) {
            foreach ($this->classTypes($parameter) as $type) {
                if (in_array(
                    $type,
                    [
                        AppMention::class,
                        MessageEvent::class,
                        SlashCommand::class,
                        GenericEvent::class,
                        FunctionExecuted::class,
                        AssistantThreadEvent::class,
                        AssistantThreadStarted::class,
                        AssistantThreadContextChanged::class,
                        EntityRequest::class,
                        EntityDetailsRequested::class,
                        EntityCommentsRequested::class,
                        ReactionEvent::class,
                        ReactionAdded::class,
                        ReactionRemoved::class,
                        ChannelMembership::class,
                        MemberJoinedChannel::class,
                        MemberLeftChannel::class,
                        LinkShared::class,
                        FileShared::class,
                        AppUninstalled::class,
                        TokensRevoked::class,
                        AgentSessionEvent::class,
                        AgentSessionStopped::class,
                        AgentSessionTitleChanged::class,
                        Action::class,
                        Shortcut::class,
                        Options::class,
                        View::class,
                    ],
                    true,
                )) {
                    $declaredPayloadTypes[] = $type;
                }
            }
        }

        if (
            $declaredPayloadTypes === []
            || array_any(
                $declaredPayloadTypes,
                static fn(string $type): bool => class_exists($type) && is_a($expectedPayload, $type, true),
            )
        ) {
            return;
        }

        throw new InvalidListener(
            $descriptor->kind->value
            . ' listener expects '
            . implode('|', $declaredPayloadTypes)
            . ", but {$descriptor->source} receives {$expectedPayload}.",
        );
    }

    private function handlerReflection(HandlerTarget $target): ReflectionFunctionAbstract
    {
        $callable = $target->callable();

        if ($callable instanceof Closure) {
            return new ReflectionFunction($callable);
        }
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }
        if (class_exists($callable)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }

    /** @return list<class-string> */
    private function classTypes(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $classes = [];

        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                $name = $candidate->getName();
                if (class_exists($name) || interface_exists($name)) {
                    $classes[] = $name;
                }
            }
        }

        return $classes;
    }
}
