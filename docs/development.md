# Development

Use PHP 8.5+ and `composer install`. Development dependencies pin Mago 1.50.0 and PHPUnit; the framework runtime does not require Mago or Node. The Composer Mago wrapper selects the platform binary. A matching globally installed `mago` also works.

```sh
composer test
composer analyse
composer lint
composer format:check
composer format
```

`analyse` runs Mago's analyzer; `lint` runs its linter. Configuration lives in `mago.toml`. The analyzer and linter cover handwritten source and generated SDK code. Experimental declaration scaffolds are excluded. Tests run under PHPUnit and are formatted with the application code; they are excluded from source analysis and production lint rules. Third-party vendor symbols are available to the analyzer.

Generated output is excluded from formatting because the generators own its bytes and hashes. Edit the generator and regenerate instead of formatting generated files by hand. The declaration-only scaffolds remain separate from working SDK models.

The lint policy retains security and correctness rules. Arbitrary class-size and complexity thresholds, forced named literal arguments, and suggestions against ordinary `isset()` calls are disabled; these rules would distort the API without improving its behavior. Mago's default error severity is the failure gate. Warnings and suggestions remain visible for review. No analyzer suppression baseline is committed.

## PHPDoc policy

Write native property, parameter, union, enum, and return types first. Do not repeat them in a docblock. Keep PHPDoc when it adds information PHP cannot express: `list<Block>`, a callback's input/output, class-string generics, meaningful wire shapes, or the reason for a protocol decision. Prefer a native value object to a growing options-array shape. Avoid copying upstream prose onto every generated property; keep provenance and machine-readable coverage alongside the generator.

Immutable builder methods return a new value and use `#[NoDiscard]`. Retain that return value. Factories expose required fields; fluent methods configure optional fields. Arrays remain explicit escape hatches for forward-compatible Slack payloads.

## Reference generation

```sh
npm ci
npm run test:tooling
npm run verify:generated
```

To rebuild from the pinned, hashed upstream source archives:

```sh
npm run sync:references
npm run generate
```

The TypeScript compiler is a development tool, locked by `package-lock.json`. Generated PHP and its model inventory are committed, so applications require no JavaScript tooling. Ownership checks reject hand-edited generated files and unexpected orphans.

Linux/macOS CI checks PHP syntax and CLI startup, PHPUnit, Mago, and generator determinism. Local checks do not establish live Slack behavior or prove a remote CI run succeeded.

## Documentation and fresh-project checks

Documentation is plain Markdown in `docs/`, edited and reviewed with the implementation. [docs/README.md](README.md) is the entry point; add new adopter guides there and update the [public interface map](reference.md) when a public seam changes. Keep current usage in guides and dated investigation/acceptance evidence in `research/`. Link to an existing canonical guide when another page needs the same explanation.

Install commands should omit package version constraints so they resolve the latest compatible release. Keep exact versions where they identify historical evidence, a tested upstream baseline or an upgrade requirement. Use relative links between repository files; they follow the selected branch or release tag on GitHub. Documentation changes use the existing repository checks and release workflow.

```sh
npm run verify:docs
composer test:project
composer test:vcs-project
```

`verify:docs` needs Node and installed Composer dependencies. It checks repository-owned Markdown links, images, heading anchors, maintained PHP imports, snippet syntax, and referenced public factories and constructors. It does not execute examples, validate every chained method or argument, or check external URLs. Dated research notes receive link checks; their historical API examples are excluded. Current Slack configuration instructions live in [Slack app setup](guides/slack-app-setup.md), with a verification date and screenshots.

The project smoke checks install dependencies into disposable applications. `test:project` covers the local path source. `test:vcs-project` snapshots the current package inputs into a disposable Git repository, installs through Composer's VCS resolver, then detaches that source to prove the generated application works without a path symlink. Neither check publishes a package or contacts Slack. Composer may download dependencies. The VCS smoke explicitly targets `dev-main` in its disposable repository; ordinary Packagist scaffolding selects the compatible stable minor. Consumers can also select compatible Git tags/constraints from 0.3.0 onward. The VCS smoke exercises the public testing toolkit and typed App Home delivery with production dependencies only.

CI runs the documentation and fresh-project checks on Linux and macOS, a focused CLI suite on Windows, generated-reference checks, and the disposable Docker deployment smoke test.
