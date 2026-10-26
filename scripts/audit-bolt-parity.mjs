import { existsSync, readFileSync, writeFileSync } from 'node:fs';

const root = new URL('../', import.meta.url);
const read = (path) => JSON.parse(readFileSync(new URL(path, root), 'utf8'));
const audit = read('docs/research/bolt-js-parity.json');
const catalog = read('docs/research/typescript-api-catalog.json').bolt;
const check = (condition, message) => { if (!condition) throw new Error(message); };
const sameKeys = (actual, expected, label) => check(JSON.stringify([...actual].sort()) === JSON.stringify([...expected].sort()), `${label} has unmapped or stale entries`);
const features = new Map(audit.features.map((feature) => [feature.id, feature]));
check(features.size === audit.features.length, 'Duplicate feature IDs');
check(catalog.label === `bolt-js@${audit.baseline.version}`, 'Version baseline mismatch');
check(catalog.projectRoot.includes(audit.baseline.commit), 'Commit baseline mismatch');
const paths = catalog.files.map(({ path }) => path);
for (const feature of audit.features) {
  check(feature.status in audit.definitions, `Unknown status: ${feature.id}`);
  check(feature.assessment.length > 0 && feature.php.length > 0 && feature.upstream.length > 0, `Missing evidence: ${feature.id}`);
  if (feature.status === 'covered') check(feature.tests.length > 0, `Covered capability has no test evidence: ${feature.id}`);
  for (const path of [...feature.php, ...feature.tests]) {
    check(existsSync(new URL(path, root)), `Missing PHP/test evidence: ${path}`);
    check(!path.startsWith('src/Scaffold/'), `Scaffold is not runtime evidence: ${path}`);
  }
  for (const path of feature.upstream) check(paths.includes(path), `Unknown upstream evidence: ${path}`);
}
const app = catalog.files.find(({ path }) => path === 'src/App.ts');
for (const [name, mapping] of [['App', audit.appMembers], ['AppOptions', audit.appOptions]]) {
  const declaration = app.exports.find((entry) => entry.name === name);
  const members = [...new Set(declaration.members.filter(({ visibility }) => !['private', 'protected'].includes(visibility)).map(({ name }) => name))];
  sameKeys(Object.keys(mapping), members, name);
  for (const [member, entry] of Object.entries(mapping)) {
    check(features.has(entry.feature) && entry.status in audit.definitions && entry.assessment, `Invalid ${name}.${member} mapping`);
  }
}
const builtin = catalog.files.find(({ path }) => path === 'src/middleware/builtin.ts');
sameKeys(Object.keys(audit.builtinMiddleware), builtin.exports.filter(({ kind }) => kind !== 're-export').map(({ name }) => name), 'Built-in middleware');
sameKeys(Object.keys(audit.upstreamModules), paths, 'Source modules');
for (const id of [...Object.values(audit.builtinMiddleware), ...Object.values(audit.upstreamModules).flat()]) check(features.has(id), `Unknown feature: ${id}`);

const cell = (text) => text.replaceAll('|', '\\|').replaceAll('\n', ' ');
const source = (path) => `[${path}](https://github.com/slackapi/bolt-js/blob/${audit.baseline.commit}/${path})`;
const local = (path) => `[${path}](../../${path})`;
const linkFeature = (id) => `[${id}](#${id})`;
const lines = [
  '# Bolt JS parity matrix', '',
  'Generated from [the reviewed audit manifest](bolt-js-parity.json) by `node scripts/audit-bolt-parity.mjs`. The human verdict and priorities are in [the audit](bolt-js-parity-audit.md).', '',
  `Baseline: Bolt JS ${audit.baseline.version}, commit \`${audit.baseline.commit}\`. PHP revision: \`${audit.phpRevision}\`. Audited ${audit.auditedAt}.`, '',
  'Mapping presence is a bookkeeping check, not proof of behavioral equality. No percentage is reported: features differ in importance, and excluded Node APIs/type aliases are not missing PHP capabilities.', '',
  '## Status definitions', '',
  ...Object.entries(audit.definitions).map(([status, definition]) => `- **${status}**: ${definition}`), '',
  '## Capability evidence', '',
];
for (const feature of audit.features) {
  lines.push(`### ${feature.id}`, '', `**${feature.area} — ${feature.status}.** ${feature.assessment}`, '',
    `PHP: ${feature.php.map(local).join(', ')}.`, '',
    feature.tests.length ? `Behavioral evidence: ${feature.tests.map(local).join(', ')}.` : 'No dedicated behavioral test is cited for the missing/excluded contract; the source establishes its absence or scope.', '',
    `Upstream: ${feature.upstream.map(source).join(', ')}.`, '');
}
for (const [title, mapping] of [['All AppOptions', audit.appOptions], ['All public App member names (overloads grouped)', audit.appMembers]]) {
  lines.push(`## ${title}`, '', '| Upstream | Status | Capability | PHP assessment |', '| --- | --- | --- | --- |');
  for (const [name, entry] of Object.entries(mapping)) lines.push(`| \`${cell(name)}\` | ${entry.status} | ${linkFeature(entry.feature)} | ${cell(entry.assessment)} |`);
  lines.push('');
}
lines.push('## All built-in middleware exports', '', '| Upstream export | Reviewed capability |', '| --- | --- |');
for (const [name, id] of Object.entries(audit.builtinMiddleware)) lines.push(`| \`${name}\` | ${linkFeature(id)} |`);
lines.push('', '## All extracted source modules', '', 'Every module is assigned for review. This does not turn every TypeScript alias/re-export in the module into an implemented PHP class. The separate scaffold inventory still describes declarations only.', '', '| Upstream module | Reviewed capabilities |', '| --- | --- |');
for (const [path, ids] of Object.entries(audit.upstreamModules)) lines.push(`| ${source(path)} | ${ids.map(linkFeature).join(', ')} |`);
lines.push('');
const output = `${lines.join('\n')}`;
const target = new URL('docs/research/bolt-js-parity-matrix.md', root);
if (process.argv.includes('--verify')) check(readFileSync(target, 'utf8') === output, 'Parity matrix is stale; run node scripts/audit-bolt-parity.mjs');
else writeFileSync(target, output);
console.log(`Verified ${audit.features.length} capability assessments, ${Object.keys(audit.appOptions).length} options, ${Object.keys(audit.appMembers).length} public member names, ${Object.keys(audit.builtinMiddleware).length} built-ins and ${paths.length} source modules. This checks inventory consistency, not complete parity.`);
