# Release gate

Before every release, read and complete [the live release requirements](docs/development.md#live-release-verification). This is a hard user requirement: live Slack tests must verify all new or changed user-visible behavior in the established smoke-test workspace, including actual rendering and interactions. Passing CI, serialization tests or API calls alone does not satisfy this gate.

Record the tested revision, cases, visible outcomes and failures. Fix and retest failures before publishing. If a required live check is blocked or unavailable, leave the release unpublished and report the blocker; do not treat missing verification as a pass.
