import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const ignoredDirectories = new Set(['vendor', 'node_modules', '.git', '.thunder-bolt', '.tempest', 'work', 'reference']);

export function documentationFiles(root) {
  const files = [];
  const visit = (directory) => {
    if (!fs.existsSync(directory)) return;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      if (entry.isSymbolicLink() || ignoredDirectories.has(entry.name)) continue;
      const file = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(file);
      else if (entry.name.endsWith('.md')) files.push(file);
    }
  };
  for (const name of ['README.md', 'UPGRADING.md', 'CHANGELOG.md', 'CONTRIBUTING.md']) {
    if (fs.existsSync(path.join(root, name))) files.push(path.join(root, name));
  }
  for (const directory of ['docs', 'examples', 'tests/Fixtures']) visit(path.join(root, directory));
  return files.sort();
}

function fences(text) {
  const blocks = [];
  const lines = text.split('\n');
  const prose = [...lines];
  let current;
  for (let index = 0; index < lines.length; index++) {
    const opening = lines[index].match(/^ {0,3}(`{3,}|~{3,})(.*)$/);
    if (!current && opening) {
      current = { marker: opening[1], language: opening[2].trim(), line: index + 1, lines: [] };
      prose[index] = '';
    } else if (current) {
      prose[index] = '';
      if (lines[index].trim() === current.marker) {
        blocks.push({ ...current, code: current.lines.join('\n') });
        current = undefined;
      } else current.lines.push(lines[index]);
    }
  }
  return { blocks, prose: prose.join('\n') };
}

function headingAnchors(prose) {
  const counts = new Map();
  const anchors = new Set();
  for (const line of prose.split('\n')) {
    const heading = line.match(/^ {0,3}#{1,6}\s+(.+?)(?:\s+#+)?$/);
    if (!heading) continue;
    const base = heading[1].replace(/<[^>]*>/g, '').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
      .toLowerCase().replace(/[^\p{L}\p{N}\p{M}\-_ ]/gu, '').replace(/ /g, '-');
    const count = counts.get(base) ?? 0;
    anchors.add(count ? `${base}-${count}` : base);
    counts.set(base, count + 1);
  }
  for (const match of prose.matchAll(/\bid=["']([^"']+)["']/g)) anchors.add(match[1]);
  return anchors;
}

export function checkDocumentation(root, files = documentationFiles(root), { php = 'php', syntax = true, api = false } = {}) {
  const result = { files: files.length, links: 0, imports: 0, snippets: 0, apiCalls: 0, errors: [] };
  const apiChecks = [];
  const report = (file, line, message) => result.errors.push(`${path.relative(root, file)}:${line}: ${message}`);
  const inside = (file) => file === root || file.startsWith(root + path.sep);
  for (const file of files) {
    const original = fs.readFileSync(file, 'utf8');
    const { blocks, prose } = fences(original);
    const links = [...prose.matchAll(/!?\[[^\]\n]*\]\(\s*(<[^>\n]+>|[^)\s]+)(?:\s+["'][^)]*["'])?\s*\)/g)];
    for (const definition of prose.matchAll(/^ {0,3}\[[^\]]+\]:\s*(<[^>\n]+>|\S+)/gm)) links.push(definition);
    for (const match of links) {
      const destination = match[1].replace(/^<|>$/g, '');
      if (/^[a-z][a-z0-9+.-]*:/i.test(destination) || destination.startsWith('//')) continue;
      const line = prose.slice(0, match.index).split('\n').length;
      let location, fragment;
      try {
        const hash = destination.indexOf('#');
        location = decodeURIComponent(hash < 0 ? destination : destination.slice(0, hash));
        fragment = hash < 0 ? '' : decodeURIComponent(destination.slice(hash + 1));
      } catch {
        report(file, line, `Invalid URL encoding: ${destination}`);
        continue;
      }
      const target = location === '' ? file : path.resolve(path.dirname(file), location);
      result.links++;
      if (!inside(target)) {
        report(file, line, `Use a repository-relative link: ${destination}`);
      } else if (['work', 'vendor', 'node_modules', '.git', '.thunder-bolt', '.tempest'].includes(path.relative(root, target).split(path.sep)[0])) {
        report(file, line, `Link depends on private or generated local state: ${destination}`);
      } else if (!fs.existsSync(target)) {
        report(file, line, `Missing link target: ${destination}`);
      } else if (fragment && fs.statSync(target).isFile()) {
        const contents = fs.readFileSync(target, 'utf8');
        if (/^L\d+(?:-L?\d+)?$/.test(fragment)) {
          const last = Number(fragment.match(/\d+$/)[0]);
          if (last > contents.split('\n').length) report(file, line, `Source line no longer exists: ${destination}`);
        } else if (target.endsWith('.md') && !headingAnchors(fences(contents).prose).has(fragment)) {
          report(file, line, `Missing heading anchor: ${destination}`);
        }
      }
    }
    // Dated audits remain historical records; current guides/examples get API and syntax checks.
    if (path.relative(root, file).split(path.sep).join('/').startsWith('docs/research/')) continue;
    for (const block of blocks.filter(block => block.language === 'php')) {
      const aliases = new Map();
      for (const match of block.code.matchAll(/^use\s+([^;]+);/gm)) {
        const statement = match[1].trim();
        const separator = "\\";
        const prefix = "Thunder\\Bolt\\";
        if (!statement.startsWith(prefix)) continue;
        const brace = statement.indexOf('{');
        const names = brace < 0 ? [statement] : statement.slice(brace + 1, statement.lastIndexOf('}')).split(',').map(name => statement.slice(0, brace) + name.trim());
        for (const imported of names) {
          const parts = imported.split(/\s+as\s+/i);
          const name = parts[0].trim();
          aliases.set(parts[1]?.trim() ?? name.split(separator).at(-1), name);
          result.imports++;
          const relative = name.slice(prefix.length).split(separator).join('/') + '.php';
          if (!fs.existsSync(path.join(root, 'src', relative))) report(file, block.line, 'Unknown Thunder Bolt import: ' + name);
        }
      }
      for (const call of block.code.matchAll(/\b([A-Za-z_]\w*)::([A-Za-z_]\w*)\s*\(/g)) {
        if (aliases.has(call[1])) apiChecks.push({class: aliases.get(call[1]), method: call[2], kind: 'static', file, line: block.line});
      }
      for (const call of block.code.matchAll(/\bnew\s+([A-Za-z_]\w*)\s*\(/g)) {
        if (aliases.has(call[1])) apiChecks.push({class: aliases.get(call[1]), kind: 'construct', file, line: block.line});
      }
      if (!syntax) continue;
      result.snippets++;
      const hasHeader = /^\s*<\?php\b/.test(block.code);
      const candidates = [hasHeader ? block.code : '<?php\n' + block.code];
      if (!hasHeader && /^\s*(?:public|protected|private)\s+(?:static\s+)?function\b/m.test(block.code)) {
        const imports = [...block.code.matchAll(/^use [^;]+;\s*$/gm)].map(match => match[0]);
        const body = block.code.replace(/^use [^;]+;\s*$/gm, '');
        candidates.push('<?php\n' + imports.join('\n') + '\nfinal class DocumentationExample {\n' + body + '\n}');
      }
      let error;
      for (const candidate of candidates) {
        const lint = spawnSync(php, ['-l'], { input: candidate, encoding: 'utf8' });
        error = lint.status === 0 ? null : (lint.stderr || lint.stdout || lint.error?.message || 'unknown error').trim();
        if (!error) break;
      }
      if (error) report(file, block.line, 'PHP snippet does not parse: ' + error);
    }
  }
  if (api) {
    if (!fs.existsSync(path.join(root, 'vendor', 'autoload.php'))) {
      result.errors.push('Install Composer dependencies before checking documented APIs.');
    } else {
      const reflected = spawnSync(php, [path.join(root, 'scripts', 'check-documentation-api.php')], {input: JSON.stringify(apiChecks), encoding: 'utf8', timeout: 30000, maxBuffer: 2 * 1024 * 1024});
      if (reflected.status !== 0) result.errors.push('Documentation API reflection failed: ' + (reflected.stderr || reflected.error?.message || 'unknown error'));
      else {
        result.apiCalls = apiChecks.length;
        for (const error of JSON.parse(reflected.stdout)) {
          const check = apiChecks[error.index];
          report(check.file, check.line, error.message);
        }
      }
    }
  }
  return result;
}
