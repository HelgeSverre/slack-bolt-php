# Work Objects support

The initial audit against `44c78dc` identified missing builders, typed events, comment endpoints and application storage. The Work Objects milestone implements those gaps.

- Immutable entities, fields, external references, senders, comments and page builders live in `src/WorkObjects/`.
- `Message::entities()` keeps entity metadata separate from event metadata.
- Typed details/comments request events preserve generic Event handler compatibility.
- `Client::workObjects()` publishes details, unfurls, comments, authentication challenges and mutation acknowledgements.
- `Comments` checks an injected authorization policy for every read/write, sanitizes provider errors and derives UI capabilities from current permissions. `CommentProvider` supplies application-owned persistence.
- `/bolt work` demonstrates queued processing, workspace-scoped JSON storage, atomic writes, author-only edits/deletes, pagination, threaded replies and delivery deduplication.
- The SDK generator retains its pinned 271-method baseline and adds two explicitly documented methods through `scripts/web-api-supplemental-schema.json`, producing 273 request/response pairs.

Normal interaction acknowledgements and `entity.acknowledgeCommentAction` are separate. Slack does not store the application's comments; UI permissions do not replace backend authorization. Providers must enforce current authorization during mutations.

Feature availability and Slack app configuration remain prerequisites. Tests verify recorded request payloads, dispatch, provider authorization, storage and replay behavior; they do not establish availability for every app/workspace. The playground README documents setup and the example URL limitation.

Controlled development-workspace validation on 24 October 2026 confirmed notification previews, refreshed details, comment reads, edits, threaded posts and thread previews, including reading persisted comments after a worker restart. The live API rejected comment IDs longer than 25 characters; builders now enforce that limit and the demo uses 24-character IDs. Deletion and shared-link unfurls are covered by local fixtures rather than this live check.

Sources: [implementation](https://docs.slack.dev/messaging/work-objects-implementation/), [comments](https://docs.slack.dev/messaging/work-objects-comments/), [presentComments](https://docs.slack.dev/reference/methods/entity.presentComments/), [acknowledgeCommentAction](https://docs.slack.dev/reference/methods/entity.acknowledgeCommentAction/).
