# Modern Block Kit live acceptance — 4 November 2026

**Release status: blocked.** Slack returned `internal_error` for a container nested inside a streamed layout. No new version, tag or release was published.

Runtime revision: `f1b5fc6c7a1b7f2b7ae76f249f26a9733afa060f`. The local changes made during acceptance are the release gate, this record, documentation corrections and the reusable fixture; package runtime code is unchanged. The single-quoted newline mistake in the layout example was corrected and the existing message and test Home were updated and inspected again.

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
| Stream: layout → container → section | **Failed:** Slack `internal_error`. See the controlled comparisons below. |

API inspection of the fixture thread confirmed successful streams were `completed`. It is separate evidence from the rendered UI and interaction checks above.

## Unresolved streaming case

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

The same composition passed in `chat.postMessage` and `views.publish`. Removing the container from the streamed layout passed, as did reversing the composition to container → layout → section. This suggests a Slack streaming-specific limitation, but does not establish the cause or prove a server defect. There is no verified runtime fix or automatic degradation in the library.

No failed write was blindly retried. Failed finalization left one baseline stream open; it was explicitly stopped without replacement blocks. A final inspection/settle pass left no fixture streams in progress and reset the fixture session to active. Task-owned receivers/workers were stopped. Boltling Home was republished from the existing playground store and visibly verified with its existing level, XP and caretaker history.

## Repeating the check

[`scripts/smoke-modern-blocks.php`](../../scripts/smoke-modern-blocks.php) requires `SLACK_LIVE_TESTS=1`, `SLACK_LIVE_WRITES=1`, and explicit `SLACK_LIVE_TEAM`, `SLACK_LIVE_CHANNEL` and `SLACK_LIVE_USER`. Credentials can be loaded in-process using `SLACK_LIVE_ENV_FILE`, or supplied through `SLACK_LIVE_TOKEN`/`SLACK_BOT_TOKEN` and `SLACK_APP_TOKEN`. They are never printed.

Run `post` to create fixtures, `listen` to receive interactions, `home` to temporarily publish the test dashboard, `stream CASE` for one controlled streaming case, and `inspect` to check final stream state. `update layout` updates the recorded message instead of duplicating it. `settle` closes remaining fixture streams and returns its agent session to active. Restore the ordinary demo Home afterwards.

The script reuses its ignored receipt and skips already-posted cases. Archive that receipt before testing a different runtime revision; a reused receipt is not fresh acceptance. Only run one interaction receiver at a time. The listener stops after fifteen minutes or SIGTERM/SIGINT. These commands collect protocol evidence; an operator must still inspect rendering and exercise the UI. Follow the [hard live release gate](../development.md#live-release-verification) before publishing.
