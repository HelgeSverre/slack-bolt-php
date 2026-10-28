# Message filters, workflow-token policy and SSL probes

Implemented 28 October 2026 against Bolt JS 5.1.0 at `ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d`. This closes three compatibility items from the [parity audit](bolt-js-parity-audit.md), preserving PHP's middleware and inline acknowledgement model.

## Composable message filters

```php
use Thunder\Bolt\Middleware\MessageFilter;
use Thunder\Bolt\Payloads\Events\Message;
use Thunder\Bolt\Reply;

$app->message('',
    MessageFilter::subtype('file_share'),
    MessageFilter::directMention(),
    function (Message $message, Reply $reply): void {
        $reply->inThread('Received your file.');
    },
);
```

Factories return ordinary `Middleware` values, usable globally or within a listener's chain. Non-matching messages skip that chain; independent listeners still run. A global message filter also excludes non-message deliveries; prefer listener middleware when an app handles multiple payload families. `subtype()` matches the raw top-level message subtype exactly, including changed-message payloads without top-level text. A missing subtype does not match an explicit string subtype.

`directMention()` matches a leading Slack user mention of the authorized installation's bot, after ECMAScript-compatible whitespace. `<@U123>` and `<@U123|label>` match; a mention later in text, another user, a channel, URL or broadcast mention does not. It does not remove the mention or overwrite text, captures or context. Missing bot user identity throws an actionable LogicException, matching Bolt's explicit missing-context behavior; configure `SLACK_BOT_USER_ID`, return it from the authorizer or use explicit static-token verification. It does not perform a network lookup. As in Bolt, the identity requirement is checked even if directMention is used globally on a non-message delivery; with identity present, non-message deliveries do not match.

Primary contract: [Bolt built-in middleware](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/middleware/builtin.ts#L340-L390). Implementation: `src/Middleware/MessageFilter.php`. Behavioral evidence: `tests/Middleware/MessageFilterTest.php`.

## Workflow-token attachment

`BoltConfig::attachFunctionToken` defaults to `true`, matching Bolt. The Tempest configuration reads `SLACK_ATTACH_FUNCTION_TOKEN` (also defaulting to true) and rejects invalid boolean values rather than silently changing credential selection.

```php
$config = new BoltConfig(
    token: $installationToken,
    signingSecret: $signingSecret,
    attachFunctionToken: false,
);
```

With attachment enabled, a function delivery or function-scoped interaction uses its workflow token where available. With attachment disabled, it uses the ordinary authorized bot token, falling back to an authorized user token. The same selected client reaches method/constructor injection, Reply and complete/fail helpers; the application/root client is unchanged. An installation authorizer still runs and may reject the delivery in either mode.

Opting out requires usable installation credentials. A body containing only a workflow token is not sufficient: HTTP intake reports unconfigured authorization, and direct dispatch rejects before handlers. The execution ID, typed inputs and live execution context remain available; this flag controls automatic token selection, not erasure of the original payload/context. Durable jobs still reject implicit capture of workflow execution credentials. Socket Mode uses the same App dispatch policy, without introducing a second credential selector. Its command preflight rejects a function-only app with attachment disabled and no installation authorization before resolving the runner or opening a connection.

Primary contract: [Bolt token selection](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/App.ts#L1606-L1613), [completion client](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/context/create-function-complete.ts). Implementation: `src/BoltConfig.php`, `src/App.php`, `src/Integration/bolt.config.php`. Evidence: `tests/Functions/TokenAttachmentPolicyTest.php` plus the existing function runtime tests for default workflow credentials and scope isolation.

## SSL-check receipt

The HTTP receiver recognizes a single scalar form field `ssl_check=1` and returns an empty 200. URL-encoded keys/values and normalized form media types (including charset parameters) work. This branch needs no signing secret or bot token because it never dispatches the application: all other fields, including a smuggled command, JSON payload or response URL, are discarded. It invokes no authorizer, middleware, handlers, stores or Slack transport.

Only the top-level form probe is recognized. JSON/text bodies, other values, nested/array-shaped probes, duplicate probe fields and PHP-normalized lookalike keys do not qualify and retain ordinary verification. This deliberately rejects ambiguous forms rather than relying on PHP's last-value-wins parser. Probe inspection is bounded to 1,000 form fields; larger forms return to ordinary signature verification. The configured body-size limit applies before probe detection. Signature verification for actual Slack deliveries remains unchanged.

This is receipt of a connectivity probe, not authentication or acknowledgement of an app event. It does not add a GET route; the stock Slack endpoint remains POST. [Slack documents the probe](https://docs.slack.dev/interactivity/implementing-slash-commands/) and [Bolt sanitizes its body before bypassing verification](https://github.com/slackapi/bolt-js/blob/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d/src/receivers/HTTPModuleFunctions.ts#L87-L104). PHP ends processing directly instead of passing a sanitized body further through the receiver. Implementation: `src/Http/Receiver.php`; evidence: `tests/Http/SslCheckTest.php` and unchanged signature/rejection tests.

## Validation

Passed 904 tests / 5,400 assertions, the production-dependency fresh-project smoke check, seven reference-tooling tests, generated/transport/parity verification and Mago checks with no errors (advisories remain). Independent standards and specification reviews passed after adding the Socket Mode preflight regression test. Tests use fixtures/recording transports; this slice sends no live Slack messages and makes no paid-workflow live-validation claim.
