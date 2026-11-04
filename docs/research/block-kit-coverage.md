# Block Kit builder coverage

Reference: pinned `packages/types/src/block-kit/{blocks,block-elements,composition-objects}.ts` in the [source manifest](upstream-manifest.json). This inventory maps the pinned wire families to working native factories, rather than counting raw wrappers as typed builders.

Current catalog check — 4 November 2026: all 22 top-level types in Slack's live block reference have dedicated builders. `Container`, `Layout`, `DataTable` and `DataVisualization` supplement the pinned upstream families below using Slack's current schemas. See the maintained [block and element catalog](../sdk-and-block-kit.md#block-catalog); block-type coverage does not establish full field/surface conformance or exhaustive element coverage.

| Current-reference addition | Native values and validation |
| --- | --- |
| [Container](https://docs.slack.dev/reference/block-kit/blocks/container-block/) | `Container`; title, children, collapse options, icon and width |
| [Layout](https://docs.slack.dev/reference/block-kit/blocks/layout-block/) | `Layout`, `LayoutRow`, `LayoutItem`; child/fallback types, row sizes, width rules and nesting |
| [Data table](https://docs.slack.dev/reference/block-kit/blocks/data-table-block/) | `DataTable`, `DataTableRow`, `DataTableCell`; rectangular rows, header rules, text/number/rich/action cells and pagination |
| [Data visualization](https://docs.slack.dev/reference/block-kit/blocks/data-visualization-block/) | `DataVisualization`, `Chart`, `ChartAxis`, `ChartSeries`, `ChartPoint`; pie/bar/area/line, finite numbers, exact category coverage and series uniqueness |

`Blocks::{container,layout,dataTable,dataVisualization}` are convenience factories. Tests in `tests/BlockKit/ModernBlocksTest.php` cover wire shapes, immutability, boundaries, invalid nesting, chart/table composition budgets and message/view/streaming integration. The existing pinned reference archive and generated models are unchanged; these additions are native implementations checked against the current official contracts. Live rendering in eligible workspaces remains separate from serialization/validation coverage.

| Pinned family | PHP builder |
| --- | --- |
| Actions, Context, Divider, Header, Image, Input, Section | Matching named classes; block IDs, fields/accessories, hints, dispatch, and expansion where supported |
| Alert, Card, Carousel, File, Markdown, Video | Matching named classes and `Blocks` convenience factories with native required arguments |
| ContextActions, TaskCard, Plan | Matching named classes and `Blocks::{contextActions,taskCard,plan}` |
| Table and its rows/cells/column settings | `Table`, `TableRow`, `TableCell`, `TableColumn`; raw-text and rich-text cells |
| RichText block | `RichText::make(RichTextBlockElement ...)` |
| Rich-text containers | `RichTextSection`, `RichTextList`, `RichTextQuote`, `RichTextPreformatted` |
| Rich-text text/link/date/color/emoji | `RichTextText`, `RichTextLink`, `RichTextDate`, `RichTextColor`, `RichTextEmoji` |
| Rich-text broadcast/channel/team/user/usergroup mentions | Matching `RichText*Mention` classes |
| Rich-text styling | `RichTextStyle` and immutable leaf/container modifiers |
| Button and image elements | `Button`, `ImageElement::{url,file}` |
| FeedbackButtons, IconButton, WorkflowButton, URLSourceElement | Matching named classes; URLSourceElement is a composition value rather than a standalone actionable control |
| Static/external select, plain-text input | `StaticSelect`, `ExternalSelect`, `PlainTextInput` |
| Users/channels/conversations and multi variants | `Picker::{users,multiUsers,channels,multiChannels,conversations,multiConversations}` |
| Multi-static/multi-external, checkboxes, radio, overflow | `Picker::{multiStatic,multiExternal,checkboxes,radio,overflow}` |
| Date, time, datetime | `Picker::{date,time,dateTime}` |
| Email, URL, number, file, rich-text inputs | `Picker::{email,url,number,file,richText}` |
| Confirm / ConfirmationDialog | `Confirm`; plain-text labels and primary/danger styles |
| Plain-text / mrkdwn / raw-text composition | `Text::{plain,markdown}` and `TableCell::text()` |
| Plain-text and mrkdwn options / groups | `Option::{text,markdown}`, `OptionGroup`; markdown options are restricted to radio buttons/checkboxes |
| Conversation filter | `Picker::conversationTypes()`, `excludeBotUsers()`, `excludeExternalSharedChannels()` |
| Dispatch action configuration | `PlainTextInput::dispatchOn()` and `Picker::dispatchOn()` |
| Slack file image source / URL image source | `SlackFile::{id,url}`, `ImageElement::{file,url}` |
| Home, modal, legacy workflow-step views | `Views\Home`, `Views\Modal`, `Views\WorkflowStep` |
| Message / metadata / legacy attachments | `Messages\Message`, `MessageMetadata`, `Attachment`, `AttachmentField`, `AttachmentMarkdown` |

`RawBlock`, `RawElement`, and `RichText::fromElements()` remain explicit forward-compatibility escape hatches. Their discriminator checks do not establish complete Slack schema validation. Dedicated builders validate required fields, common limits, mutually exclusive sources, and control placement; Slack still enforces contextual restrictions, permissions, workspace capabilities, and remaining cross-field rules.

Workflow-step view serialization retains the pinned SDK type. It does not add a runtime listener for Slack's retired Steps from Apps feature. Outbound message and view APIs remain separate from inbound acknowledgement values.
