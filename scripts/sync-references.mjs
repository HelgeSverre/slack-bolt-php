import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = fileURLToPath(new URL('..', import.meta.url));
const workspace = join(root, 'work', 'reference-sources');
const docs = join(root, 'docs', 'research');
const reference = join(docs, 'reference');

const sources = [
  {
    id: 'bolt-js',
    repository: 'slackapi/bolt-js',
    ref: 'ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d',
    tag: 'v5.1.0',
    archive: 'https://codeload.github.com/slackapi/bolt-js/tar.gz/ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d',
  },
  {
    id: 'node-slack-sdk',
    repository: 'slackapi/node-slack-sdk',
    ref: 'e026671c42786248349feb6906d8ce45815f73c6',
    tag: '@slack/web-api@8.1.1',
    archive: 'https://codeload.github.com/slackapi/node-slack-sdk/tar.gz/e026671c42786248349feb6906d8ce45815f73c6',
    package: { name: '@slack/web-api', version: '8.1.1', path: 'packages/web-api' },
  },
  {
    id: 'node-slack-oauth',
    repository: 'slackapi/node-slack-sdk',
    ref: '70e15b8442745d215f9ae259a2c970b306aff51a',
    tag: '@slack/oauth@4.0.0',
    annotatedTag: '3162a88f13252a305213c2418f7524aa60ce4b3f',
    archive: 'https://codeload.github.com/slackapi/node-slack-sdk/tar.gz/70e15b8442745d215f9ae259a2c970b306aff51a',
    package: { name: '@slack/oauth', version: '4.0.0', path: 'packages/oauth' },
  },
  {
    id: 'node-slack-socket-mode',
    repository: 'slackapi/node-slack-sdk',
    ref: 'e0fa7455eedf8db98990c98ea6aa151a8b9f0597',
    tag: '@slack/socket-mode@3.0.1',
    archive: 'https://codeload.github.com/slackapi/node-slack-sdk/tar.gz/e0fa7455eedf8db98990c98ea6aa151a8b9f0597',
    package: { name: '@slack/socket-mode', version: '3.0.1', path: 'packages/socket-mode' },
  },
  {
    id: 'node-slack-types',
    repository: 'slackapi/node-slack-sdk',
    ref: 'c663dc06c362cffbb11773cc85228eb4f943630c',
    tag: '@slack/types@3.1.0',
    annotatedTag: 'cccc3b52a0645f620a1dffab54c2c1b044c56992',
    archive: 'https://codeload.github.com/slackapi/node-slack-sdk/tar.gz/c663dc06c362cffbb11773cc85228eb4f943630c',
    package: { name: '@slack/types', version: '3.1.0', path: 'packages/types' },
  },
];

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const fileHash = (path) => sha256(readFileSync(path));

function fetchBytes(url) {
  return fetch(url, { headers: { 'user-agent': 'thunder-bolt-reference-tooling' } }).then(async (response) => {
    if (!response.ok) throw new Error(`GET ${url} failed: ${response.status} ${response.statusText}`);
    return Buffer.from(await response.arrayBuffer());
  });
}

function rootDirectory(path) {
  const entries = readdirSync(path).filter((entry) => statSync(join(path, entry)).isDirectory());
  if (entries.length !== 1) throw new Error(`Expected one archive root in ${path}; found ${entries.join(', ')}`);
  return join(path, entries[0]);
}

function copyIfPresent(sourceRoot, destination, candidates) {
  const copied = [];
  for (const candidate of candidates) {
    const source = join(sourceRoot, candidate);
    if (!existsSync(source) || !statSync(source).isFile()) continue;
    const target = join(destination, basename(candidate));
    cpSync(source, target);
    copied.push({ source: candidate, target: relative(root, target), sha256: fileHash(target) });
  }
  return copied;
}

async function main() {
  mkdirSync(workspace, { recursive: true });
  mkdirSync(reference, { recursive: true });
  const manifest = {
    schema: 1,
    generatedBy: 'scripts/sync-references.mjs',
    sources: [],
    documents: [],
    referenceUrls: [
      { label: 'Tempest 3.20.0 release', url: 'https://github.com/tempestphp/tempest-framework/releases/tag/v3.20.0' },
      { label: 'Tempest framework source', url: 'https://github.com/tempestphp/tempest-framework/tree/v3.20.0' },
      { label: 'Tempest documentation', url: 'https://tempestphp.com/' },
      { label: 'Laravel framework source', url: 'https://github.com/laravel/framework' },
      { label: 'Laravel documentation', url: 'https://laravel.com/docs' },
      { label: 'Slack Bolt JavaScript reference', url: 'https://docs.slack.dev/tools/bolt-js/reference/' },
    ],
  };

  for (const source of sources) {
    const archivePath = join(workspace, `${source.id}-${source.ref}.tar.gz`);
    const extractPath = join(workspace, `${source.id}-${source.ref}`);
    const archive = await fetchBytes(source.archive);
    writeFileSync(archivePath, archive);
    rmSync(extractPath, { recursive: true, force: true });
    mkdirSync(extractPath, { recursive: true });
    execFileSync('tar', ['-xzf', archivePath, '-C', extractPath]);
    const sourceRoot = rootDirectory(extractPath);
    const licenseDir = join(reference, source.id);
    mkdirSync(licenseDir, { recursive: true });
    const preserved = copyIfPresent(sourceRoot, licenseDir, ['LICENSE', 'LICENSE.md', 'package.json']);
    if (source.package) {
      const packageJson = join(sourceRoot, source.package.path, 'package.json');
      if (!existsSync(packageJson)) throw new Error(`Pinned package path missing: ${source.package.path}`);
      const target = join(licenseDir, `${source.package.name.replace('@slack/', '')}-package.json`);
      cpSync(packageJson, target);
      preserved.push({
        package: `${source.package.name}@${source.package.version}`,
        source: `${source.package.path}/package.json`,
        target: relative(root, target),
        sha256: fileHash(target),
      });
    }
    manifest.sources.push({ ...source, archiveSha256: fileHash(archivePath), extractedRoot: relative(root, sourceRoot), preserved });
  }

  const llmsUrl = 'https://docs.slack.dev/llms.txt';
  const llmsPath = join(reference, 'slack-docs-llms.txt');
  writeFileSync(llmsPath, await fetchBytes(llmsUrl));
  manifest.documents.push({ label: 'Slack docs index (llms.txt)', url: llmsUrl, path: relative(root, llmsPath), sha256: fileHash(llmsPath) });

  const boltRoot = join(workspace, `bolt-js-${sources[0].ref}`);
  const boltSource = rootDirectory(boltRoot);
  const selected = copyIfPresent(boltSource, join(reference, 'bolt-js'), ['README.md', 'docs/README.md', 'docs/getting-started.md']);
  manifest.documents.push({ label: 'Selected Markdown from pinned Bolt source', selected });

  writeFileSync(join(docs, 'upstream-manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  console.log(`Synced ${manifest.sources.length} source archives and ${manifest.documents.length} document records.`);
}

await main();
