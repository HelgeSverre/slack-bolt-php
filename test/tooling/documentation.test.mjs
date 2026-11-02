import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { checkDocumentation, documentationFiles } from '../../scripts/lib/documentation-checks.mjs';

function fixture(run) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'bolt-docs-check-'));
  try {
    fs.mkdirSync(path.join(root, 'docs'), { recursive: true });
    fs.mkdirSync(path.join(root, 'src'), { recursive: true });
    run(root);
  } finally { fs.rmSync(root, { recursive: true, force: true }); }
}

test('checks local destinations, duplicate heading anchors, images and source lines', () => fixture(root => {
  fs.writeFileSync(path.join(root, 'docs', 'guide.md'), '# Repeated\n# Repeated\n');
  fs.writeFileSync(path.join(root, 'src', 'Thing.php'), '<?php\nclass Thing {}\n');
  fs.writeFileSync(path.join(root, 'README.md'), '[guide](docs/guide.md#repeated-1)\n[source](src/Thing.php#L2)\n![missing](docs/missing.jpg)\n[bad heading](docs/guide.md#gone)\n[bad line](src/Thing.php#L999)\n');
  const report = checkDocumentation(root, undefined, { syntax: false });
  assert.equal(report.links, 5);
  assert.equal(report.errors.length, 3);
  assert.match(report.errors.join('\n'), /missing.jpg/);
  assert.match(report.errors.join('\n'), /heading anchor/);
  assert.match(report.errors.join('\n'), /Source line/);
}));

test('ignores sample Markdown fences and third-party archives, validates framework imports', () => fixture(root => {
  fs.mkdirSync(path.join(root, 'docs', 'research', 'reference'), { recursive: true });
  fs.writeFileSync(path.join(root, 'docs', 'research', 'reference', 'upstream.md'), '[missing](bad.md)');
  fs.writeFileSync(path.join(root, 'README.md'), '```md\n[sample](not-real.md)\n```\n```php\nuse Thunder\\Bolt\\Thing;\nuse Thunder\\Bolt\\Missing;\n```\n');
  fs.writeFileSync(path.join(root, 'src', 'Thing.php'), '<?php');
  const report = checkDocumentation(root, undefined, { syntax: false });
  assert.equal(documentationFiles(root).length, 1);
  assert.equal(report.links, 0);
  assert.equal(report.imports, 2);
  assert.equal(report.errors.length, 1);
  assert.match(report.errors[0], /Unknown Thunder Bolt import/);
}));

test('rejects private filesystem escapes and malformed URL encodings', () => fixture(root => {
  fs.writeFileSync(path.join(root, 'README.md'), '[private](../secret.md)\n[invalid](bad%zz.md)\n[external](https://example.test/not-checked)\n');
  const report = checkDocumentation(root, undefined, { syntax: false });
  assert.equal(report.errors.length, 2);
  assert.match(report.errors.join('\n'), /repository-relative/);
  assert.match(report.errors.join('\n'), /Invalid URL encoding/);
}));

test('expands grouped framework imports and aliases', () => fixture(root => {
  fs.mkdirSync(path.join(root, 'src', 'Messages'));
  fs.writeFileSync(path.join(root, 'src', 'Messages', 'Message.php'), '<?php');
  fs.writeFileSync(path.join(root, 'src', 'Messages', 'Other.php'), '<?php');
  fs.writeFileSync(path.join(root, 'README.md'), '```php\nuse Thunder\\Bolt\\Messages\\{Message as Card, Other};\nCard::text("hello");\n```\n');
  const report = checkDocumentation(root, undefined, { syntax: false });
  assert.equal(report.imports, 2);
  assert.deepEqual(report.errors, []);
}));

test('rejects links to ignored local state even when files exist', () => fixture(root => {
  fs.mkdirSync(path.join(root, 'work'));
  fs.writeFileSync(path.join(root, 'work', 'upstream.php'), '<?php');
  fs.writeFileSync(path.join(root, 'README.md'), '[local capture](work/upstream.php)\n');
  const report = checkDocumentation(root, undefined, { syntax: false });
  assert.equal(report.errors.length, 1);
  assert.match(report.errors[0], /private or generated local state/);
}));
