import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const project = fileURLToPath(new URL('../..', import.meta.url));
const node = process.execPath;

function execute(script, args = [], environment = {}) {
  return spawnSync(node, [join(project, script), ...args], {
    cwd: project,
    encoding: 'utf8',
    env: { ...process.env, ...environment },
  });
}

test('help names the reproducible tooling commands', () => {
  const output = execFileSync(node, [join(project, 'scripts/help.mjs')], { encoding: 'utf8' });
  assert.match(output, /npm ci/);
  assert.match(output, /npm run sync:references/);
  assert.match(output, /npm run extract:typescript/);
  assert.match(output, /npm run verify:generated/);
});

test('generator is deterministic and refuses a manually changed owned file', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'thunder-bolt-generator-'));
  try {
    const catalog = join(project, 'docs/research/typescript-api-catalog.json');
    const fixtureCatalog = join(fixture, 'docs/research/typescript-api-catalog.json');
    mkdirSync(dirname(fixtureCatalog), { recursive: true });
    cpSync(catalog, fixtureCatalog, { recursive: false });
    const environment = { THUNDER_BOLT_ROOT: fixture };

    const generated = execute('scripts/generate-scaffold.mjs', [], environment);
    assert.equal(generated.status, 0, generated.stderr);
    const verified = execute('scripts/generate-scaffold.mjs', ['--verify'], environment);
    assert.equal(verified.status, 0, verified.stderr);

    const chat = join(fixture, 'src/Generated/WebApi/Chat.php');
    writeFileSync(chat, `${readFileSync(chat, 'utf8')}\n// Manual alteration\n`);
    const refused = execute('scripts/generate-scaffold.mjs', [], environment);
    assert.notEqual(refused.status, 0);
    assert.match(refused.stderr, /Refusing to overwrite manually altered generated path/);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('Web API model generator is deterministic and covers every extracted method', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'thunder-bolt-models-'));
  try {
    const fixtureCatalog = join(fixture, 'docs/research/typescript-api-catalog.json');
    mkdirSync(dirname(fixtureCatalog), { recursive: true });
    cpSync(join(project, 'docs/research/typescript-api-catalog.json'), fixtureCatalog, { recursive: false });
    const environment = { THUNDER_BOLT_ROOT: fixture };

    const generated = execute('scripts/generate-web-api-models.mjs', [], environment);
    assert.equal(generated.status, 0, generated.stderr);
    const verified = execute('scripts/generate-web-api-models.mjs', ['--verify'], environment);
    assert.equal(verified.status, 0, verified.stderr);

    const catalog = JSON.parse(readFileSync(fixtureCatalog, 'utf8'));
    assert.deepEqual(new Set(catalog.sdk.webApiMethods), new Set(catalog.sdk.webApiModels.map((model) => model.method)));
    assert.match(readFileSync(join(fixture, 'src/Generated/WebApi/Models/Responses/ChatPostMessageResponse.php'), 'utf8'), /extends Response implements TypedResponse/);
    const coverage = JSON.parse(readFileSync(join(fixture, 'docs/research/web-api-model-coverage.json'), 'utf8'));
    assert.equal(coverage.methods, catalog.sdk.webApiMethods.length);
    assert.match(readFileSync(join(fixture, 'src/Generated/WebApi/Models/Requests/EntityPresentCommentsRequest.php'), 'utf8'), /public array\|null \$comments = null/);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('verification detects a generated resource orphan', () => {
  const fixture = mkdtempSync(join(tmpdir(), 'thunder-bolt-orphan-'));
  try {
    const fixtureCatalog = join(fixture, 'docs/research/typescript-api-catalog.json');
    mkdirSync(dirname(fixtureCatalog), { recursive: true });
    cpSync(join(project, 'docs/research/typescript-api-catalog.json'), fixtureCatalog, { recursive: false });
    const environment = { THUNDER_BOLT_ROOT: fixture };
    assert.equal(execute('scripts/generate-scaffold.mjs', [], environment).status, 0);

    const orphan = join(fixture, 'src/Generated/WebApi/Legacy.php');
    writeFileSync(orphan, '<?php\n');
    const verified = execute('scripts/generate-scaffold.mjs', ['--verify'], environment);
    assert.notEqual(verified.status, 0);
    assert.match(verified.stderr, /Refusing to remove unowned generated resource orphan/);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('every extracted Bolt export has one scaffold disposition', () => {
  const catalog = JSON.parse(readFileSync(join(project, 'docs/research/typescript-api-catalog.json'), 'utf8'));
  const disposition = JSON.parse(readFileSync(join(project, 'docs/research/scaffold-disposition.json'), 'utf8'));
  const accountedFor = Object.values(disposition.counts).reduce((total, count) => total + count, 0);

  assert.equal(disposition.exports.length, catalog.bolt.exports.length);
  assert.equal(accountedFor, catalog.bolt.exports.length);
  assert.equal(disposition.counts['scaffold-class'], Object.keys(catalog.bolt.classes).length);
  assert.equal(disposition.counts['scaffold-interface'] + disposition.counts['scaffold-options-dto'], Object.keys(catalog.bolt.interfaces).length);
  assert.ok(disposition.exports.every((item) => typeof item.status === 'string' && item.status.length > 0));
});

test('generated resources preserve dotted Slack paths as nested PHP calls', () => {
  const generated = join(project, 'src/Generated/WebApi');
  const admin = readFileSync(join(generated, 'Admin.php'), 'utf8');
  const adminConversations = readFileSync(join(generated, 'AdminConversationsResource.php'), 'utf8');
  const conversations = readFileSync(join(generated, 'Conversations.php'), 'utf8');
  const entity = readFileSync(join(generated, 'Entity.php'), 'utf8');

  assert.match(admin, /function conversations\(\): AdminConversationsResource/);
  assert.match(adminConversations, /function archive\(array\|RequestPayload \$arguments = \[\]\): AdminConversationsArchiveResponse/);
  assert.match(adminConversations, /callTyped\('admin\.conversations\.archive', \$arguments, AdminConversationsArchiveResponse::class\)/);
  assert.match(conversations, /function list\(array\|RequestPayload \$arguments = \[\]\): ConversationsListResponse/);
  assert.match(conversations, /callTyped\('conversations\.list', \$arguments, ConversationsListResponse::class\)/);
  assert.match(entity, /function presentComments\(array\|RequestPayload \$arguments = \[\]\): EntityPresentCommentsResponse/);
  assert.match(entity, /function acknowledgeCommentAction\(array\|RequestPayload \$arguments = \[\]\): EntityAcknowledgeCommentActionResponse/);
  assert.match(entity, /docs\.slack\.dev\/reference\/methods\/entity\.presentComments/);
});

test('declaration scaffolds are excluded from Tempest discovery', () => {
  const scaffold = readFileSync(join(project, 'src/Scaffold/Bolt/App.php'), 'utf8');
  const map = readFileSync(join(project, 'src/Scaffold/BoltJsExportInventory.php'), 'utf8');
  assert.match(scaffold, /use Tempest\\Discovery\\SkipDiscovery;/);
  assert.match(scaffold, /#\[SkipDiscovery\]/);
  assert.match(map, /#\[SkipDiscovery\]/);
});
