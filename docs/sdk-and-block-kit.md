# Messages, Block Kit, and views

Thunder Bolt builders are immutable values: build a reusable part, then retain the returned value from each fluent call.

```php
use Thunder\Bolt\BlockKit\{Actions, Button, Input, PlainTextInput, Section};
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Views\Modal;

$message = Message::blocks(
    Section::markdown('*Deployment ready*'),
    Actions::make(Button::make('Approve', 'approve')->primary()),
)->fallbackText('Deployment ready.');

$modal = Modal::make('Deploy',
    Input::make('Reason', PlainTextInput::make('reason')->multiline())->id('reason'),
)->callbackId('deploy')->submit('Deploy')->close('Cancel');
```

Pass a `Modal` directly to `ViewResponse::update()` or `ViewResponse::push()`. `RawBlock::fromArray()` and `RawElement::fromArray()` preserve raw Slack Block Kit additions through explicit extension points; discriminator checks do not establish full Slack schema validation. `RichText::fromElements()` validates the rich-text container while retaining Slack's nested rich-text element shape.

Dedicated factories cover the pinned block, element, composition, and view families. Raw extension points allow future Slack additions without pretending to validate unknown schemas.

## Metadata and attachments

```php
use Thunder\Bolt\Messages\{Attachment, AttachmentField, Message};

$message = Message::attachments(
    Attachment::text('Build complete')
        ->color('good')
        ->fields(AttachmentField::make('Version', '1.2.3')->short()),
)->fallbackText('Build complete')
    ->metadata('build_complete', ['version' => '1.2.3'])
    ->unfurlLinks(false);
```

`Message::markdown()` emits Slack's `markdown_text` field. `mrkdwn()` controls parsing of ordinary `text`; those are distinct Slack fields. `Parse` is a native enum. Threading, reply broadcast, authorship, link/media unfurls, metadata, attachments, and blocks remain immutable optional modifiers.

## Rich text and specialist blocks

```php
use Thunder\Bolt\BlockKit\{RichText, RichTextSection, RichTextText, RichTextUserMention};

$block = RichText::make(
    RichTextSection::make(
        RichTextText::make('Assigned to '),
        RichTextUserMention::for('U123'),
    ),
);
```

Dedicated builders cover the pinned rich-text leaves and containers, selectors and input families, specialist video/table/card/carousel/task/plan blocks, and feedback/icon/workflow controls. See the [complete family mapping](research/block-kit-coverage.md). `Blocks` provides discoverable convenience factories for specialist blocks, with the same native contracts as their named classes.

Modal labels use plain text up to 24 characters. A modal with input blocks requires `submit()` before serialization. `Home` supports callback IDs, external IDs, and private metadata. Builders preserve explicit `false` and zero values and omit absent optional fields.
