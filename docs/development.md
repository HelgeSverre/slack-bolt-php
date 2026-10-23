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
