# Modern Block Kit live acceptance — 4 November 2026

**Live blocker resolved.** The initial run found `internal_error` for a container nested inside a streamed layout. A controlled payload comparison identified omitted plain-text `emoji` settings; the serializer fix passed live start, append, completion, rendering and interaction checks. No release was published during diagnosis.

Initial runtime revision: `f1b5fc6c7a1b7f2b7ae76f249f26a9733afa060f`. Fixed runtime revision: `a51e5600edf99ba0e7695f90f971336000fd2ac8`. The single-quoted newline mistake in the layout example was also corrected and the existing message and test Home were updated and inspected again.

## Environment and evidence

Tests used the established Thunder Bolt Dev app in the Helge Sverre workspace, its direct message with the test user, and that user's Home. An isolated Socket Mode receiver handled only the fixture's actions and modal. All content was synthetic. No scopes, app settings, credentials or installation grants were changed.

The [acceptance thread](https://helgesverre.slack.com/archives/D0CBLL7LF51/p1793807829379729) retains the fixtures and handler confirmations. Its timestamp is `1793807829.379729`. Sanitized API receipts and screenshots remain in ignored `work/modern-blocks-live/`; screenshots containing surrounding workspace messages are not committed.

## Results

| Case | Observed result |
| --- | --- |
| Ordinary message: container | Accepted and visibly rendered. Plain/rich titles, subtitle and header divider displayed. The initially collapsed container expanded to reveal its children. |
| Ordinary message: layout | Accepted and visibly rendered with weighted widths, alignment, grid lines and a nested container. The corrected Service/Payments and Status/Healthy strings render on separate lines. |
| Ordinary message: data table | Accepted and rendered with rich text, numbers and action cells. Next page displayed Gamma (-1) and Delta (0). Numeric descending sorting placed Beta (10) before Alpha (2). Clicks before and after pagination/sorting delivered row_1, row_3 and row_2 respectively. |
| Ordinary messages: charts | Pie, grouped bar, area and line variants rendered with their native accessible chart descriptions. Pie showed the 75/25 split; Cartesian charts displayed both series and labeled axes. Rendering in this Slack client does not establish identical styling across clients. |
| Modal | A real button opened a modal containing an input nested in a layout. Submitting `Live nested input verified` closed it and the handler recorded the exact value match, then posted a confirmation in the thread. |
| Home | Published all four new block types to the test user. Inspected the actual dashboard, operated collapse and a table row action, and verified the handler receipt. The Publish test Home message button also produced a `home_publish` receipt. |
| Stream: section baseline | Start and stop succeeded; final message visible. |
| Stream: standalone container | Start and stop succeeded; native collapsible container rendered. |
| Stream: table | Start and stop succeeded; native table rendered. Clicking its Alpha row button delivered row_1 and posted a confirmation. |
| Stream: line chart | Start and stop succeeded; both plotted series, axes and legend visibly rendered. |
| Stream: layout → section | Start and stop succeeded, both with and without an explicit fallback. Layout content visible in completed messages. |
| Stream: container → layout → section | Start and stop succeeded; nested content rendered in the completed container. |
| Stream: layout → container → section | Initially failed with `internal_error`; passed after the explicit text-setting fix. See diagnosis and retest below. |

API inspection of the fixture thread confirmed successful streams were `completed`. It is separate evidence from the rendered UI and interaction checks above.

## Initial failing case

The minimal failing typed block is:

```php
use Thunder\Bolt\BlockKit\{Container, Layout, LayoutItem, LayoutRow, Section};

$layout = Layout::make('Nested stream layout',
    LayoutRow::make(
        LayoutItem::make(Container::make('Nested', Section::text('Content'))),
    ),
);
```

Sending this through `Assistant\Streaming\Blocks::make($layout)` to `chat.startStream` returned `internal_error`. These controlled variants also failed:

- The original multi-row layout, alone and alongside other streamed blocks.
- Replacing the nested section with a rich-text block.
- Explicitly specifying the container's standard width and false values for collapse/header flags.
- Starting a section-only stream successfully, then supplying the nested layout as final `chat.stopStream` blocks.

The same composition passed in `chat.postMessage` and `views.publish`. Removing the container from the streamed layout passed, as did reversing the composition to container → layout → section. The initial inference was a streaming-specific nesting limitation. The follow-up below disproved that inference: the composition works with explicit text settings.

No failed write was blindly retried. Failed finalization left one baseline stream open; it was explicitly stopped without replacement blocks. A final inspection/settle pass left no fixture streams in progress and reset the fixture session to active. Task-owned receivers/workers were stopped. Boltling Home was republished from the existing playground store and visibly verified with its existing level, XP and caretaker history.

## Diagnosis and verified fix

Hand-written JSON sent directly through Guzzle reproduced the same `internal_error`, as did form-encoded chunks. This bypassed native builders and the package transport. Explicit block IDs, item width and an empty layout fallback did not resolve it.

Slack accepted the minimal block as an ordinary message. Reading that message back supplied Slack's normalized shape; streaming the normalized shape succeeded. Isolating the extra fields showed that explicitly adding `emoji: true` to **both** plain-text objects (container title and section text) resolved the error. Adding it to only one still failed. Slack documents [`emoji` as optional](https://docs.slack.dev/reference/block-kit/composition-objects/text-object/), so this is an observed parser compatibility requirement, not a new general Block Kit requirement.

`Text::toArray()` now always emits its existing plain-text `emoji` setting. Explicit `false` is preserved; markdown output is unchanged. Public factory methods and raw-array output are unchanged. A regression test failed before the fix and passes after it, checking the actual nested streaming chunk and both default fields.

The original live command, `stream layout_nested`, then passed. The richer original `stream combined`, rich-text child, explicit container settings, `stream append_layout` and `stream final_layout` also started and completed successfully. Successful message timestamps include:

| Fixed case | Timestamp |
| --- | --- |
| Minimal nested layout | `1793816716.618209` |
| Combined container/layout/table/chart | `1793816717.721099` |
| Final blocks on stop | `1793816718.641469` |
| Rich-text child | `1793816790.750409` |
| Explicit container flags | `1793816791.690269` |
| Nested layout appended to an existing stream | `1793816818.793029` |

The actual web client rendered the fixed nested layout and children. In the combined stream, expanding the container revealed its content; table pagination displayed Gamma/Delta, numeric descending sorting placed Beta (10) before Alpha (2), and clicking Gamma delivered row_3. All ordinary fixture messages were updated using the fixed serializer. The nested modal was reopened and submitted successfully with an exact value match. The Home publication button delivered its event; Home visibly rendered all four new block types and its Alpha action delivered row_1. The normal Boltling dashboard was restored afterwards.

The full suite passed with **1,181 tests and 6,608 assertions**. Mago format, lint and analysis passed their configured error checks; existing advisory counts remained 114 lint issues and 291 analysis issues. The throwaway raw-HTTP diagnostic was removed; the maintained live fixture retains the append case. No automatic nesting rewrite or failed-write retry was added.

## Repeating the check

[`scripts/smoke-modern-blocks.php`](../../scripts/smoke-modern-blocks.php) requires `SLACK_LIVE_TESTS=1`, `SLACK_LIVE_WRITES=1`, and explicit `SLACK_LIVE_TEAM`, `SLACK_LIVE_CHANNEL` and `SLACK_LIVE_USER`. Credentials can be loaded in-process using `SLACK_LIVE_ENV_FILE`, or supplied through `SLACK_LIVE_TOKEN`/`SLACK_BOT_TOKEN` and `SLACK_APP_TOKEN`. They are never printed.

Run `post` to create fixtures, `listen` to receive interactions, `home` to temporarily publish the test dashboard, `stream CASE` for one controlled streaming case, and `inspect` to check final stream state. `update layout` updates the recorded message instead of duplicating it. `settle` closes remaining fixture streams and returns its agent session to active. Restore the ordinary demo Home afterwards.

The script reuses its ignored receipt and skips already-posted cases. Archive that receipt before testing a different runtime revision; a reused receipt is not fresh acceptance. Only run one interaction receiver at a time. The listener stops after fifteen minutes or SIGTERM/SIGINT. These commands collect protocol evidence; an operator must still inspect rendering and exercise the UI. Follow the [hard live release gate](../development.md#live-release-verification) before publishing.
