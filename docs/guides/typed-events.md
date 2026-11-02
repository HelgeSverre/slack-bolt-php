# Typed events

Event handlers can use native payload classes rather than reading Slack's wire fields throughout application code. The event catalog is shared by dispatch, listener compatibility checks, and `make:listener`; a generated handler uses the same class the runtime supplies.

## App Home

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Payloads\Events\AppHomeOpened;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\WebApi\Client;

final readonly class WorkspaceHome
{
    public function __construct(private Client $client) {}

    #[Event('app_home_opened')]
    public function __invoke(AppHomeOpened $event): void
    {
        if ($event->tab !== 'home') {
            return;
        }
        $this->client->views()->publish([
            'user_id' => $event->userId,
            'view' => Home::make(Section::markdown('*Workspace dashboard*'))->toArray(),
        ]);
    }
}
```

An App Home event has no published `view` until a view exists. Channel, tab, timestamp, view, and current app `context` are optional; `raw` retains the original event. Receiving app context here does not implement the separate modern agent-context lifecycle.

## Added event families

| Slack event | Native payload | Useful fields |
| --- | --- | --- |
| `app_home_opened` | `AppHomeOpened` | `userId`, `channelId`, `tab`, `view`, `context`, `eventTs` |
| `channel_created` | `ChannelCreated` | `channelId`, `name`, `creatorId`, `channel` |
| `channel_rename` | `ChannelRenamed` | `channelId`, `name`, `channel` |
| `channel_archive` | `ChannelArchived` | `channelId`, `userId`, `eventTs` |
| `channel_unarchive` | `ChannelUnarchived` | `channelId`, `userId`, `eventTs` |
| `channel_deleted` | `ChannelDeleted` | `channelId`, `eventTs` |
| `message_metadata_posted` | `MessageMetadataPosted` | `messageTs`, `metadata`, app/bot/team/user IDs |
| `message_metadata_updated` | `MessageMetadataUpdated` | `metadata`, `previousMetadata`, `messageTs` |
| `message_metadata_deleted` | `MessageMetadataDeleted` | `previousMetadata`, `messageTs`, `deletedTs` |

Classes live under `Thunder\Bolt\Payloads\Events`. Shared bases `ChannelEvent` and `MessageMetadataEvent` support handlers that deliberately accept more than one related event type. Existing mention, message, reaction, membership, file, authorization lifecycle, assistant, function, Work Object, and agent-session payloads remain supported.

```sh
php bolt make:listener WorkspaceHome --event=app_home_opened
php bolt make:listener MetadataChanged --event=message_metadata_updated
```

## Received metadata

```php
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Payloads\Events\MessageMetadataUpdated;

final readonly class MetadataChanged
{
    #[Event('message_metadata_updated')]
    public function __invoke(MessageMetadataUpdated $event): void
    {
        $current = $event->metadata;
        $previous = $event->previousMetadata;
        // Reconcile $current?->eventPayload with your application's domain state.
    }
}
```

Received metadata uses `Payloads\Values\Metadata`, with `eventType`, `eventPayload`, and `raw`. It preserves inbound extension fields and does not impose the outbound `Messages\MessageMetadata` builder's restrictions. Timestamps stay strings. A message timestamp does not establish its thread root; metadata events do not invent one.

Metadata written by the authorized bot is still delivered to observers; normalizing `user_id` does not make `ignoreSelf` suppress these notifications.

Subscribe to these events and configure matching `metadata_subscriptions` filters in Slack. Typed decoding does not alter your app manifest or grant scopes. [Slack setup](slack-app-setup.md#work-objects-unfurls-and-metadata) explains that configuration.

## Compatibility

The added payloads extend the generic `Payloads\Event`; existing handlers accepting that base still work and receive the exact original `raw` array. Unknown event types continue using the generic payload. A known event with malformed required fields fails as an invalid payload before listeners run. This is deliberately incremental typing, not a promise to model every Slack event or nested field.

Source schemas: [App Home](https://docs.slack.dev/reference/events/app_home_opened/), [channel creation](https://docs.slack.dev/reference/events/channel_created/), [channel rename](https://docs.slack.dev/reference/events/channel_rename/), [posted metadata](https://docs.slack.dev/reference/events/message_metadata_posted/), [updated metadata](https://docs.slack.dev/reference/events/message_metadata_updated/), [deleted metadata](https://docs.slack.dev/reference/events/message_metadata_deleted/).
