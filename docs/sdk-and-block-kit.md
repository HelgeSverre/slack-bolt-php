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

Use the [block catalog](#block-catalog) to choose a layout, the [element catalog](#element-catalog) for controls, and [composition objects](#composition-objects) for their shared values. All builders below live in `Thunder\Bolt\BlockKit` unless noted otherwise.

## Block catalog

Blocks are the top-level entries passed to `Message::blocks()`, `Modal::make()` or `Home::make()`. Elements such as buttons and selects go inside a compatible block. For example, put buttons in `Actions`, a labeled form control in `Input`, and small text/image annotations in `Context`.

The following table lists every dedicated block builder in Thunder Bolt. Source links show the full native signatures; factory examples show the entry point rather than every optional modifier.

| Slack `type` | PHP factory | Use it for |
| --- | --- | --- |
| `actions` | [`Actions::make($button, $select)`](../src/BlockKit/Actions.php) | A row of interactive controls |
| `alert` | [`Alert::make('Check the configuration', 'warning')`](../src/BlockKit/Alert.php) | A notice with a severity level |
| `card` | [`Card::make()->title('Release')->body('Ready to deploy')`](../src/BlockKit/Card.php) | A compact card with optional imagery and buttons |
| `carousel` | [`Carousel::make($firstCard, $secondCard)`](../src/BlockKit/Carousel.php) | A horizontally browsable set of cards |
| `container` | [`Container::make('Summary', $section)`](../src/BlockKit/Container.php) | A titled wrapper with optional collapse controls |
| `context` | [`Context::make(Text::plain('Updated just now'))`](../src/BlockKit/Context.php) | Small supporting text and images |
| `context_actions` | [`ContextActions::make($feedback, $iconButton)`](../src/BlockKit/ContextActions.php) | Feedback and icon controls |
| `data_table` | [`DataTable::make('Tasks', $header, $row)`](../src/BlockKit/DataTable.php) | Accessible, paginated data with typed numeric and action cells |
| `data_visualization` | [`DataVisualization::make('Traffic', $chart)`](../src/BlockKit/DataVisualization.php) | A pie, bar, area or line chart |
| `divider` | [`Divider::make()`](../src/BlockKit/Divider.php) | A visual separator |
| `file` | [`File::remote('external-file-id')`](../src/BlockKit/File.php) | A previously registered remote file; this does not upload a file |
| `header` | [`Header::make('Deployment')`](../src/BlockKit/Header.php) | A plain-text heading |
| `image` | [`Image::make(ImageElement::url($url, 'Build diagram'))`](../src/BlockKit/Image.php) | A standalone image; Slack file sources are also supported |
| `input` | [`Input::make('Reason', PlainTextInput::make('reason'))`](../src/BlockKit/Input.php) | A labeled form control, with optional hint and optional/dispatch settings |
| `layout` | [`Layout::make('Summary', $row)`](../src/BlockKit/Layout.php) | Side-by-side blocks arranged in rows |
| `markdown` | [`Markdown::make('## Release notes')`](../src/BlockKit/Markdown.php) | A dedicated Markdown block |
| `plan` | [`Plan::make('Deploy', $firstTask, $secondTask)`](../src/BlockKit/Plan.php) | A titled group of task cards |
| `rich_text` | [`RichText::make($section, $list)`](../src/BlockKit/RichText.php) | Structured text, lists, quotes, code and mentions |
| `section` | [`Section::markdown('*Ready*')`](../src/BlockKit/Section.php), `text()` or `fields()` | General text, field columns and an optional accessory |
| `table` | [`Table::make($headerRow, $dataRow)`](../src/BlockKit/Table.php) | Rows and cells, with optional column settings |
| `task_card` | [`TaskCard::make('deploy', 'Deploy release', 'in_progress')`](../src/BlockKit/TaskCard.php) | A task's status, details, output and sources |
| `video` | [`Video::make('Demo', $videoUrl, $thumbnailUrl, 'Demo video')`](../src/BlockKit/Video.php) | An embedded video player, subject to Slack's video requirements |

`Blocks` also provides convenience factories for specialist blocks: `alert()`, `card()`, `carousel()`, `container()`, `contextActions()`, `dataTable()`, `dataVisualization()`, `file()`, `layout()`, `markdown()`, `plan()`, `table()`, `taskCard()` and `video()`. They return the same named builder classes.

### Coverage and extension points

Checked against [Slack's block reference](https://docs.slack.dev/reference/block-kit/blocks/) on **4 November 2026**: all 22 listed block types have dedicated native builders above. This is block-type coverage, not exhaustive field or workspace/surface conformance. The [family mapping](research/block-kit-coverage.md) distinguishes the pinned upstream families from additions checked against Slack's current reference.

`RawBlock::fromArray()` remains available for additional wire shapes. Raw serialization does not establish that a workspace or destination supports a block. Known layout/container child restrictions and composition limits also apply when a raw block is passed through those builders.

### Placement and limits

Slack's general limits are 50 blocks per message and 100 per modal or Home view. Nested layout/container blocks consume the enclosing builder's block budget too. Individual block/element limits and supported surfaces also apply; a block being constructible does not make it valid on every surface. See [Slack's block reference](https://docs.slack.dev/reference/block-kit/blocks/) for those restrictions.

`Image` is a whole block; `ImageElement` is the image value used by it and by compatible nested placements. `Markdown` is a block, `Text::markdown()` creates a `mrkdwn` composition object, and `Message::markdown()` sets the message's `markdown_text` field. These are separate wire shapes.

## Containers and layouts

`Container` accepts a plain-text or `RichText` title and up to ten supported child blocks. `richTextTitle()` adds a rich title alongside a plain fallback; Slack gives the rich title precedence. Subtitle, icon, width, collapse flags and header divider are immutable options. `collapsed()` sets the default state; pair it with `collapsible()` to make that state effective.

```php
use Thunder\Bolt\BlockKit\{Container, Section};

$container = Container::make('Deployment summary',
    Section::markdown('*Production* is ready.'),
)->width('full')->collapsible()->collapsed()->id('deployment_summary');
```

`Layout` uses [`LayoutRow`](../src/BlockKit/LayoutRow.php) and [`LayoutItem`](../src/BlockKit/LayoutItem.php) values: one to five rows, each with one to three items. Item widths are relative weights: narrow = 1, standard = 2, wide = 3. Alignment uses `start`, `center` or `end`. Slack stacks items on mobile; source order remains reading order.

```php
use Thunder\Bolt\BlockKit\{Layout, LayoutItem, LayoutRow, Section};

$layout = Layout::make('Release overview',
    LayoutRow::make(
        LayoutItem::make(Section::markdown("*Service*\nPayments"))->width('wide'),
        LayoutItem::make(Section::markdown("*Status*\nHealthy"))->width('narrow'),
    ),
)->labelVisible()->gridLines('rows')
    ->fallback(Section::markdown('*Payments*: healthy.'));
```

Both builders check allowed child types and the two-level nesting limit: layout → container → section is valid; layout → container → layout → section is rejected. Markdown blocks are not layout children; use a section with `mrkdwn` text or a rich-text block. A row can hold at most one carousel, data table or data visualization; a visualization must receive more than half the row's relative width.

Supply `fallback()` with stable blocks when the layout carries essential information: older clients do not automatically derive fallback content from the rows. `labelVisible(false)` hides the label; the hidden label is not an accessible name. Use a separate header when the group needs heading semantics. See Slack's [container](https://docs.slack.dev/reference/block-kit/blocks/container-block/) and [layout](https://docs.slack.dev/reference/block-kit/blocks/layout-block/) contracts.

## Interactive data tables

`DataTable` is separate from `Table`: it supports pagination and data-table cell types. Its required caption provides accessible table context. Pass the header first, followed by one to 200 data rows; all rows need the same one to twenty columns. Header cells cannot be rich text.

```php
use Thunder\Bolt\BlockKit\{Button, DataTable, DataTableCell, DataTableRow};

$table = DataTable::make('Open tasks',
    DataTableRow::make(
        DataTableCell::text('Task'),
        DataTableCell::text('Points'),
        DataTableCell::text('Action'),
    ),
    DataTableRow::make(
        DataTableCell::text('Review deployment'),
        DataTableCell::number(3, '3 points'),
        DataTableCell::action(
            Button::make('Open', 'open_task')->value('task_123'),
            DataTableCell::text('Open task'),
        ),
    ),
)->pageSize(10)->rowHeaderColumn(0)->id('open_tasks');
```

[`DataTableCell`](../src/BlockKit/DataTableCell.php) has `text()`, `number()`, `richText()` and `action()` factories; [`DataTableRow`](../src/BlockKit/DataTableRow.php) holds the cells. Number cells keep a numeric sort value and a display label, defaulting the label to the number. Action cells take a `Button` and an optional text/number fallback; their clicks use ordinary [action handlers](http-interactivity.md). Use the button value to identify the row.

`pageSize()` accepts 1–100; `rowHeaderColumn()` is a zero-based column index. The builder checks a 20,000-character cell-text budget per table. Message serialization also checks the combined data-table budget across body blocks and attachments. Rich-text character accounting covers nested text fields; Slack enforces its complete rendering/accounting rules and workspace capabilities. See the [data table contract](https://docs.slack.dev/reference/block-kit/blocks/data-table-block/).

## Data visualizations

Use a [`Chart`](../src/BlockKit/Chart.php) value inside `DataVisualization`. Pie charts take positive [`ChartPoint`](../src/BlockKit/ChartPoint.php) segments. Bar, area and line charts take a [`ChartAxis`](../src/BlockKit/ChartAxis.php) plus named [`ChartSeries`](../src/BlockKit/ChartSeries.php) values:

```php
use Thunder\Bolt\BlockKit\{Chart, ChartAxis, ChartPoint, ChartSeries, DataVisualization};

$traffic = ChartSeries::make('API',
    ChartPoint::make('Mon', 120),
    ChartPoint::make('Tue', 150),
);

$chart = DataVisualization::make('Daily requests',
    Chart::line(ChartAxis::categories('Mon', 'Tue')->yLabel('Requests'), $traffic),
)->id('daily_requests');

$pie = DataVisualization::make('Request sources',
    Chart::pie(ChartPoint::make('API', 75), ChartPoint::make('Web', 25)),
);
```

Use `Chart::bar()`, `area()` or `line()` with the same axis/series interface. Series names must be unique and each series must contain exactly one point for every axis category. Axis category order controls display order. Cartesian values may be zero or negative; pie values must be positive. All numeric values must be finite.

Titles and axis titles allow 50 characters; category/point labels and series names allow 20. Charts accept up to twelve segments or series, and a series has at most twenty points. Message builders enforce at most two data visualizations, including nested blocks and attachments. Typed streaming block chunks check their own batch; Slack enforces limits across the complete stream. See the [data visualization contract](https://docs.slack.dev/reference/block-kit/blocks/data-visualization-block/).

Live acceptance on 4 November found that Slack accepted and rendered these new blocks in ordinary messages and Home, but returned `internal_error` for a container nested inside a streamed layout. Standalone streamed containers, tables, charts and simple layouts succeeded. This remains an unresolved release blocker; see the [live validation record](research/modern-blocks-live-validation.md) for the tested combinations and limits of that result.

Container, data table and data visualization blocks are supported in messages and Home, not modals; modal serialization rejects them, including nested occurrences. Layout supports modals too, with surface-compatible children. A modal with an input nested inside a layout still needs `submit()`. Workspace feature access and the remaining Slack-side restrictions apply independently of builder validation.

## Element catalog

These are all of Thunder Bolt's dedicated control and image element factories. The single- and multi-select variants have distinct Slack types even when they share one PHP builder class.

| Slack `type` | PHP factory | Purpose |
| --- | --- | --- |
| `button` | [`Button::make('Approve', 'approve')`](../src/BlockKit/Button.php) | A button action; optional URL/value/style/confirmation |
| `icon_button` | [`IconButton::make($icon, 'Details', 'details')`](../src/BlockKit/IconButton.php) | A labeled icon control |
| `feedback_buttons` | [`FeedbackButtons::make('Helpful', 'yes', 'Unhelpful', 'no', 'feedback')`](../src/BlockKit/FeedbackButtons.php) | Paired positive/negative feedback controls |
| `workflow_button` | [`WorkflowButton::make('Run workflow', $triggerUrl)`](../src/BlockKit/WorkflowButton.php) | Start a workflow through its trigger URL |
| `image` | [`ImageElement::url($url, 'Description')`](../src/BlockKit/ImageElement.php) or `file($slackFile, 'Description')` | Nested image content |
| `static_select` | [`StaticSelect::make('priority', ...$options)`](../src/BlockKit/StaticSelect.php) or `groups('priority', ...$groups)` | One choice from application-defined options |
| `external_select` | [`ExternalSelect::make('project')`](../src/BlockKit/ExternalSelect.php) | One choice loaded through an options listener |
| `multi_static_select` | [`Picker::multiStatic('labels', ...$options)`](../src/BlockKit/Picker.php) | Multiple fixed choices |
| `multi_external_select` | `Picker::multiExternal('projects')` | Multiple dynamically loaded choices |
| `users_select`, `multi_users_select` | `Picker::users('owner')`, `Picker::multiUsers('reviewers')` | One or multiple Slack users |
| `channels_select`, `multi_channels_select` | `Picker::channels('channel')`, `Picker::multiChannels('channels')` | One or multiple public channels |
| `conversations_select`, `multi_conversations_select` | `Picker::conversations('destination')`, `Picker::multiConversations('destinations')` | Conversations with optional filters |
| `checkboxes` | `Picker::checkboxes('features', ...$options)` | Multiple checkbox choices |
| `radio_buttons` | `Picker::radio('priority', ...$options)` | One radio choice |
| `overflow` | `Picker::overflow('more', ...$options)` | A compact overflow menu |
| `datepicker` | `Picker::date('due')` | A calendar date |
| `timepicker` | `Picker::time('start')` | A time, with optional timezone |
| `datetimepicker` | `Picker::dateTime('when')` | A date and time |
| `plain_text_input` | [`PlainTextInput::make('reason')`](../src/BlockKit/PlainTextInput.php) | Plain-text entry; optional multiline/length/dispatch settings |
| `email_text_input` | `Picker::email('email')` | Email entry |
| `url_text_input` | `Picker::url('website')` | URL entry |
| `number_input` | `Picker::number('quantity')` | Numeric entry, with optional decimals and range |
| `file_input` | `Picker::file('attachment')` | File selection, with optional file-type/count constraints |
| `rich_text_input` | `Picker::richText('description')` | Rich-text entry |

The string passed to a picker/input factory is its `action_id`. An enclosing input block's `id()` sets a separate `block_id`; both identify submitted view state. Use `Input` around form controls rather than placing them directly in a modal's block list:

```php
use Thunder\Bolt\BlockKit\{Input, Option, Picker, StaticSelect};
use Thunder\Bolt\Views\Modal;

$modal = Modal::make('Create task',
    Input::make('Owner', Picker::users('owner'))->id('owner'),
    Input::make('Priority', StaticSelect::make('priority',
        Option::text('Normal', 'normal'),
        Option::text('Urgent', 'urgent'),
    ))->id('priority'),
    Input::make('Due date', Picker::date('due'))->id('due')->optional(),
)->callbackId('create_task')->submit('Create')->close('Cancel');
```

External selects need an [options listener](http-interactivity.md); their builder does not fetch choices. `Picker` modifiers are type-specific: for example, `allowDecimals()` applies to number inputs and `fileTypes()` to file inputs. Unsupported combinations throw rather than silently emitting a different control.

This is the native PHP element catalog, not every entry in [Slack's evolving element reference](https://docs.slack.dev/reference/block-kit/block-elements/). Additional families such as citations, tags and canvas/work-object mentions do not have dedicated builders here. `RawElement` covers compatible element placements; use `RichText::fromElements()` for nested rich-text wire shapes without typed builders.

## Composition objects

These values support blocks and elements; they are not standalone blocks.

| Value | PHP factory/helper |
| --- | --- |
| Plain text and `mrkdwn` | [`Text::plain($text)` / `Text::markdown($text)`](../src/BlockKit/Text.php) |
| Menu options and option groups | [`Option::text($label, $value)` / `Option::markdown($label, $value)`](../src/BlockKit/Option.php), [`OptionGroup::make($label, ...$options)`](../src/BlockKit/OptionGroup.php) |
| Confirmation dialog | [`Confirm::make(...)`](../src/BlockKit/Confirm.php) |
| Slack-hosted image source | [`SlackFile::id($id)` / `SlackFile::url($url)`](../src/BlockKit/SlackFile.php) |
| Task source link (`url`) | [`URLSourceElement::make($url, $label)`](../src/BlockKit/URLSourceElement.php) |
| Table rows, cells and columns | [`TableRow::make(...$cells)`](../src/BlockKit/TableRow.php), [`TableCell::text($text)` / `richText($richText)`](../src/BlockKit/TableCell.php), [`TableColumn::make()->align('right')->wrap()`](../src/BlockKit/TableColumn.php) |

Markdown option labels are supported by the radio/checkbox builders; static/external select options require plain text. Conversation filters and dispatch-action configuration are fluent modifiers on `Picker` and `PlainTextInput`, rather than separate configuration classes.

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

### Rich-text containers and leaves

`RichText` takes containers, which hold leaf values. These are distinct from form controls such as `PlainTextInput` and `Picker::richText()`.

| Rich-text value | PHP factory |
| --- | --- |
| Section | [`RichTextSection::make(...$leaves)`](../src/BlockKit/RichTextSection.php) |
| Bulleted or ordered list | [`RichTextList::bullet(...$sections)` / `ordered(...$sections)`](../src/BlockKit/RichTextList.php) |
| Quote | [`RichTextQuote::make(...$leaves)`](../src/BlockKit/RichTextQuote.php) |
| Preformatted code | [`RichTextPreformatted::make(...$textOrLinks)`](../src/BlockKit/RichTextPreformatted.php) |
| Text | [`RichTextText::make($text)`](../src/BlockKit/RichTextText.php) |
| Link | [`RichTextLink::to($url, $label)`](../src/BlockKit/RichTextLink.php) |
| Date | [`RichTextDate::at($timestamp, $format)`](../src/BlockKit/RichTextDate.php) |
| Color | [`RichTextColor::hex($hex)`](../src/BlockKit/RichTextColor.php) |
| Emoji | [`RichTextEmoji::named($name)`](../src/BlockKit/RichTextEmoji.php) |
| User mention | [`RichTextUserMention::for($userId)`](../src/BlockKit/RichTextUserMention.php) |
| User group mention | [`RichTextUsergroupMention::for($usergroupId)`](../src/BlockKit/RichTextUsergroupMention.php) |
| Channel mention | [`RichTextChannelMention::for($channelId)`](../src/BlockKit/RichTextChannelMention.php) |
| Team mention | [`RichTextTeamMention::for($teamId)`](../src/BlockKit/RichTextTeamMention.php) |
| Broadcast mention | [`RichTextBroadcastMention::here()` / `channel()` / `everyone()`](../src/BlockKit/RichTextBroadcastMention.php) |

[`RichTextStyle`](../src/BlockKit/RichTextStyle.php) supplies reusable leaf styles. Keep raw nested data in `RichText::fromElements()` when Slack adds a shape that these leaf/container builders do not yet model.

### A small table

```php
use Thunder\Bolt\BlockKit\{Table, TableCell, TableColumn, TableRow};

$table = Table::make(
    TableRow::make(TableCell::text('Service'), TableCell::text('Status')),
    TableRow::make(TableCell::text('Payments'), TableCell::text('Healthy')),
)->columns(TableColumn::make()->wrap(), TableColumn::make());
```

`TaskCard` and `Plan` are block builders. For incremental tool progress during a streamed response, use the separate `Assistant\Streaming\TaskUpdate` and `PlanUpdate` chunk values described in [streaming progress](guides/streaming-progress.md).

Modal labels use plain text up to 24 characters. A modal with input blocks requires `submit()` before serialization. `Home` supports callback IDs, external IDs, and private metadata. Builders preserve explicit `false` and zero values and omit absent optional fields.
