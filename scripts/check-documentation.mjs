import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDocumentation } from './lib/documentation-checks.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const report = checkDocumentation(root, undefined, { api: true });
if (report.errors.length) {
  console.error(report.errors.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`Verified ${report.files} owned Markdown files, ${report.links} local links, ${report.imports} PHP imports, ${report.snippets} PHP snippets and ${report.apiCalls} factory/constructor references. Third-party reference archives and external URLs are not checked.`);
}
