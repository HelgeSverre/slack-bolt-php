# Generated Web API model coverage

Generated from the pinned Slack TypeScript declarations at https://github.com/slackapi/node-slack-sdk/blob/e026671c42786248349feb6906d8ce45815f73c6/packages/web-api/src/types, plus explicit official-documentation supplements when listed below.

- 273 endpoint request/response model pairs
- 636 interned nested structural DTO shapes across 8474 endpoint occurrences
- 29 request fields and 6504 response fields deliberately remain raw

## Fidelity limits

- Native scalar, array, and structural object fields are typed and hydrated recursively through six structural levels.
- Polymorphic, indexed, and TypeScript-only fields remain raw rather than receiving an unsound PHP type.
- All unknown response fields remain in Response::$data; all unknown request fields can be passed through Request::$extra.

The accompanying JSON lists every endpoint and every raw field with its reason.
