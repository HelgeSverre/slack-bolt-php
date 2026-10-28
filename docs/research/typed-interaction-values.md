# Typed interaction values

`Action::input()` reads the element that triggered the action. `Action::form()` and `View::form()` expose the same reader for submitted form state:

```php
$title = $view->form()->require('title', 'input')->text();
$assignee = $view->form()->get('owner', 'select')?->selectedValue();
$labels = $view->form()->require('labels', 'select')->selectedValues();
$due = $view->form()->get('due', 'date')?->date();
$uploads = $view->form()->get('attachments', 'files')?->files() ?? [];

$choice = $action->input()->selectedValue();
$buttonValue = $action->input()->text();
```

`FormState::get()` returns null when a cell is absent or is not an object. `require()` throws `OutOfBoundsException` for that case. A present cell with a cleared selection still returns an `InputValue`; use its raw data when presence versus explicit null matters.

| Reader | Result | Controls |
| --- | --- | --- |
| `text()` | `?string` | Text/email/URL/number inputs, buttons and other scalar `value` fields |
| `selectedValue()` | `?string` | Static/external select, overflow, radio; user/channel/conversation IDs |
| `selectedValues()` | `list<string>` | Multi-select variants, checkboxes, legacy message select |
| `date()` | `?string` | Valid calendar date as `YYYY-MM-DD`, without a timezone |
| `time()` | `?string` | Time as `HH:mm`, without an assumed timezone |
| `timestamp()` | `?int` | Datetime picker UNIX seconds |
| `files()` | `list<UploadedFile>` | File input; ID, optional name/title/MIME type/size/private URL and original raw file |
| `richText()` | `?array` | Original rich-text tree, retaining future nodes and fields |

Numeric text stays a string, preserving decimal precision and zero values. Empty strings remain empty. Cleared single selections return null, and cleared multi-selections return an empty list. Malformed values are not coerced into strings, numbers or file IDs; malformed list entries are omitted from typed lists. Dates and times are checked for valid format/range. Readers extract values; application validation still decides which values are allowed.

`InputValue::$type` and `$raw` preserve unfamiliar controls. Rich text remains its structured Slack tree rather than being flattened or expanded into a class for every possible node. No network request is made when reading files; use the existing Web API client/upload helpers when file operations are needed. File URLs are metadata, not a guarantee of authorization or a download performed by the reader.

Existing `Action::$action`, `$raw`, first-action matching and identifiers are unchanged. Existing `View::$values`, `$view`, `$raw`, `value()` and `state()` retain their behavior. `Action::form()` prefers top-level `state.values` (including an explicitly empty state), with view state as a fallback only when top-level state is absent. `View::form()` reads its existing values. The new readers never mutate any payload.

Evidence: `FormStateTest` runs common controls through triggered action values, action form state and view form state, and verifies legacy readers, file metadata, unknown controls, cleared/missing cells, malformed input and first-action matching.

Protocol sources: [block actions](https://docs.slack.dev/reference/interaction-payloads/block_actions-payload/), [view interactions](https://docs.slack.dev/reference/interaction-payloads/view-interactions-payload/), [file input](https://docs.slack.dev/reference/block-kit/block-elements/file-input-element/), [rich-text input](https://docs.slack.dev/reference/block-kit/block-elements/rich-text-input-element/) and the pinned Bolt JS `ViewStateValue`/`UploadedFile` declarations.
