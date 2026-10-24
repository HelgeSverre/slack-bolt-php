import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = (process.env.THUNDER_BOLT_ROOT ?? fileURLToPath(new URL('..', import.meta.url))).replace(/\/$/, '');
const inventoryPath = join(root, 'docs', 'research', 'typescript-api-catalog.json');
const generatedRoot = join(root, 'src', 'Generated');
const scaffoldRoot = join(root, 'src', 'Scaffold');
const ownershipPath = join(generatedRoot, '.ownership.json');
const verify = process.argv.includes('--verify');

const sourceCommit = 'ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d';
const sourceUrl = `https://github.com/slackapi/bolt-js/blob/${sourceCommit}`;
const hash = (content) => createHash('sha256').update(content).digest('hex');

function pascal(value) {
  return value.split(/[^A-Za-z0-9]+/).filter(Boolean).map((part) => `${part[0].toUpperCase()}${part.slice(1)}`).join('');
}

const reserved = new Set(['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'new', 'never', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield']);

function phpMethod(method) {
  return method.split('.').at(-1);
}

function phpIdentifier(name) {
  const normalized = name.replaceAll(/[^A-Za-z0-9_]/g, '');
  const safe = normalized === '' || /^[0-9]/.test(normalized) ? `value${pascal(normalized)}` : normalized;
  return reserved.has(safe.toLowerCase()) ? `${safe}Value` : safe;
}

function phpType(type, optional = false) {
  const normalized = (type ?? '').replaceAll(' ', '');
  let mapped = 'mixed';
  if (normalized === 'string') mapped = 'string';
  else if (normalized === 'boolean') mapped = 'bool';
  else if (normalized === 'number') mapped = 'int|float';
  else if (normalized === 'string[]' || normalized === 'Array<string>') mapped = 'array';
  else if (normalized === 'Record<string,unknown>' || normalized === '{[key:string]:unknown}') mapped = 'array';
  if (!optional || mapped === 'mixed') return mapped;
  return mapped.includes('|') ? `${mapped}|null` : `?${mapped}`;
}

function declarationLocation(inventory, file, symbol) {
  return inventory.bolt.exports.find((item) => item.file === file && item.name === symbol)?.location?.line ?? null;
}

function declarationAddress(file, symbol) {
  const withoutPrefix = file.replace(/^src\//, '').replace(/\.ts$/, '');
  const segments = withoutPrefix.split('/').map(pascal);
  const fileStem = segments.pop();
  const modules = [...segments];
  if (fileStem !== symbol) modules.push(fileStem);
  const namespace = ['Thunder', 'Bolt', 'Scaffold', 'Bolt', ...modules].join('\\');
  return {
    namespace,
    path: join(scaffoldRoot, 'Bolt', ...modules, `${symbol}.php`),
  };
}

function sourceLink(file, line) {
  return `${sourceUrl}/${file}${line ? `#L${line}` : ''}`;
}

function skipDiscovery(content) {
  if (content.includes('Tempest\\Discovery\\SkipDiscovery')) return content;
  return content.replace(/(namespace [^;]+;\n\n(?:use [^\n]+;\n)*)/, '$1use Tempest\\Discovery\\SkipDiscovery;\n\n#[SkipDiscovery]\n');
}

function notPortedContent() {
  return `<?php\n\ndeclare(strict_types=1);\n\nnamespace Thunder\\Bolt\\Scaffold\\Bolt;\n\nuse Tempest\\Discovery\\SkipDiscovery;\n\n/** @internal Declaration-only scaffold; never a runtime Bolt implementation. */\n#[SkipDiscovery]\nfinal class NotPortedYet extends \\LogicException\n{\n    public static function forSymbol(string $symbol): never\n    {\n        throw new self(sprintf('Bolt JS symbol "%s" is declared for migration planning but is not implemented.', $symbol));\n    }\n}\n`;
}

function classScaffoldContent(symbol, item, address, line) {
  const methods = new Map();
  for (const member of item.members ?? []) {
    if (member.visibility !== 'public' || member.kind !== 'method' || member.name === '(call signature)') continue;
    const method = phpMethod(member.name);
    if (!methods.has(method)) methods.set(method, member);
  }
  const source = sourceLink(item.file, line);
  const body = [...methods.entries()].map(([method, member]) => `    /** Bolt JS \`${member.name}\`; source type: \`${member.type ?? 'unknown'}\`. */\n    public function ${method}(mixed ...$arguments): mixed\n    {\n        NotPortedYet::forSymbol('${symbol}::${member.name}');\n    }`).join('\n\n');
  return `<?php\n\ndeclare(strict_types=1);\n\n/**\n * Declaration-only quick scaffold for Bolt JS \`${symbol}\`.\n * Source: ${source}\n * Unsupported TypeScript signatures are deliberately represented as \`mixed\`.\n */\nnamespace ${address.namespace};\n\nuse Thunder\\Bolt\\Scaffold\\Bolt\\NotPortedYet;\n\nclass ${symbol}\n{\n    public function __construct(mixed ...$arguments)\n    {\n        NotPortedYet::forSymbol('${symbol}::__construct');\n    }${body ? `\n\n${body}` : ''}\n}\n`;
}

function interfaceScaffoldContent(symbol, item, address, line) {
  const methods = new Map();
  for (const member of item.members ?? []) {
    if (member.kind !== 'method' || member.name === '(call signature)') continue;
    const method = phpMethod(member.name);
    if (!methods.has(method)) methods.set(method, member);
  }
  const source = sourceLink(item.file, line);
  const body = [...methods.entries()].map(([method, member]) => `    /** Bolt JS \`${member.name}\`; source type: \`${member.type ?? 'unknown'}\`. */\n    public function ${method}(mixed ...$arguments): mixed;`).join('\n\n');
  return `<?php\n\ndeclare(strict_types=1);\n\n/**\n * Declaration-only quick scaffold for Bolt JS \`${symbol}\`.\n * Source: ${source}\n * Unsupported TypeScript signatures are deliberately represented as \`mixed\`.\n */\nnamespace ${address.namespace};\n\ninterface ${symbol}\n{${body ? `\n${body}\n` : ''}}\n`;
}

function optionsDtoContent(symbol, item, address, line) {
  const properties = (item.members ?? []).filter((member) => member.kind === 'property').map((member) => ({
    name: phpIdentifier(member.name),
    type: phpType(member.type, member.optional),
    sourceType: member.type ?? 'unknown',
    optional: member.optional,
  })).sort((left, right) => Number(left.optional) - Number(right.optional));
  const source = sourceLink(item.file, line);
  const parameters = properties.map((property) => `        /** Bolt JS: ${property.sourceType} */\n        public ${property.type} $${property.name}${property.optional ? ' = null' : ''},`).join('\n');
  return `<?php\n\ndeclare(strict_types=1);\n\n/**\n * Structural, declaration-only DTO scaffold for Bolt JS \`${symbol}\`.\n * Source: ${source}\n * Non-trivial TypeScript types remain \`mixed\`; this class is not a runtime contract.\n */\nnamespace ${address.namespace};\n\nfinal readonly class ${symbol}\n{\n    public function __construct(\n${parameters}\n    ) {\n    }\n}\n`;
}

function header(source) {
  return `<?php\n\ndeclare(strict_types=1);\n\n/**\n * @generated by scripts/generate-scaffold.mjs; do not edit manually.\n * Source: ${source}\n * This declaration returns a generated, typed Web API response wrapper.\n */\n`;
}

function resourceClassName(path) {
  return `${path.map(pascal).join('')}${path.length > 1 ? 'Resource' : ''}`;
}

function webApiModelName(method) {
  return method.split('.').map(pascal).join('');
}

function resourceTree(methods) {
  const root = { methods: [], children: new Map() };
  for (const method of methods) {
    let node = root;
    const parts = method.split('.');
    const leaf = parts.pop();
    for (const part of parts) {
      if (!node.children.has(part)) node.children.set(part, { methods: [], children: new Map() });
      node = node.children.get(part);
    }
    if (node.methods.some((entry) => entry.name === leaf)) throw new Error(`Duplicate Slack method: ${method}`);
    node.methods.push({ name: leaf, method });
  }
  return root;
}

function resourceEntries(tree) {
  const entries = [];
  const visit = (path, node) => {
    entries.push({ path, node });
    for (const [segment, child] of [...node.children.entries()].sort(([left], [right]) => left.localeCompare(right))) {
      visit([...path, segment], child);
    }
  };
  for (const [segment, child] of [...tree.children.entries()].sort(([left], [right]) => left.localeCompare(right))) visit([segment], child);
  return entries;
}

function resourceContent(entry, modelsByMethod) {
  const className = resourceClassName(entry.path);
  const pinnedMethodsSource = 'https://github.com/slackapi/node-slack-sdk/blob/e026671c42786248349feb6906d8ce45815f73c6/packages/web-api/src/methods.ts';
  const sources = [...new Set(entry.node.methods.map(({ method }) => (
    modelsByMethod.get(method)?.provenance?.url
      ?? pinnedMethodsSource
  )))];
  if (sources.length === 0) sources.push(pinnedMethodsSource);
  const imports = new Set([
    'use Thunder\\Bolt\\WebApi\\Client;',
    'use Thunder\\Bolt\\WebApi\\RequestPayload;',
    'use Thunder\\Bolt\\WebApi\\Response;',
  ]);
  const methods = entry.node.methods.sort((left, right) => left.name.localeCompare(right.name)).map(({ name, method }) => {
    const model = modelsByMethod.get(method);
    if (!model) return `    public function ${phpMethod(name)}(array|RequestPayload $arguments = []): Response\n    {\n        return $this->client->call('${method}', $arguments);\n    }`;
    const modelName = webApiModelName(method);
    imports.add(`use Thunder\\Bolt\\Generated\\WebApi\\Models\\Responses\\${modelName}Response;`);
    return `    public function ${phpMethod(name)}(array|RequestPayload $arguments = []): ${modelName}Response\n    {\n        return $this->client->callTyped('${method}', $arguments, ${modelName}Response::class);\n    }`;
  }).join('\n\n');
  const accessors = [...entry.node.children.entries()].sort(([left], [right]) => left.localeCompare(right)).map(([segment, child]) => {
    const childClass = resourceClassName([...entry.path, segment]);
    return `    public function ${segment}(): ${childClass}\n    {\n        return new ${childClass}($this->client);\n    }`;
  }).join('\n\n');
  const uploadV2 = className === 'Files' ? `    public function uploadV2(\\Thunder\\Bolt\\WebApi\\Uploads\\Upload ...$uploads): \\Thunder\\Bolt\\WebApi\\Uploads\\UploadResult\n    {\n        return $this->client->uploadMany(...$uploads);\n    }` : '';
  const body = [accessors, methods, uploadV2].filter(Boolean).join('\n\n');
  return `${header(sources.join('\n * Source: '))}\nnamespace Thunder\\Bolt\\Generated\\WebApi;\n\n${[...imports].sort().join('\n')}\n\nfinal readonly class ${className}\n{\n    public function __construct(private Client $client)\n    {\n    }\n\n${body}\n}\n`;
}

function traitContent(groups) {
  const accessors = groups.map((group) => {
    const className = pascal(group);
    return `    public function ${group}(): ${className}\n    {\n        return new ${className}($this);\n    }`;
  }).join('\n\n');
  return `${header('https://github.com/slackapi/node-slack-sdk/tree/e026671c42786248349feb6906d8ce45815f73c6/packages/web-api')}\nnamespace Thunder\\Bolt\\Generated\\WebApi;\n\n/**\n * Add this trait to Thunder\\Bolt\\WebApi\\Client. Resource objects are intentionally not cached so it is safe in a readonly client.\n */\ntrait HasResources\n{\n${accessors}\n}\n`;
}

function phpLiteral(value, depth = 0) {
  if (value === null) return 'null';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value);
  if (typeof value === 'string') return `'${value.replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
  const indent = '    '.repeat(depth);
  const child = '    '.repeat(depth + 1);
  if (Array.isArray(value)) return `[\n${value.map((item) => `${child}${phpLiteral(item, depth + 1)},`).join('\n')}\n${indent}]`;
  return `[\n${Object.entries(value).map(([key, item]) => `${child}${phpLiteral(key)} => ${phpLiteral(item, depth + 1)},`).join('\n')}\n${indent}]`;
}

function scaffoldContent(disposition) {
  const mapped = disposition.exports;
  return `<?php\n\ndeclare(strict_types=1);\n\nnamespace Thunder\\Bolt\\Scaffold;\n\n/**\n * Generated source map for every exported Bolt JS symbol.\n *\n * This is documentation-only: these are not runtime DTOs and do not claim feature implementation.\n */\nfinal readonly class BoltJsExportInventory\n{\n    /** @var list<array{symbol: string, kind: string, file: string, line: int|null, status: string, scaffold: string|null, source: string}> */\n    public const array EXPORTS = ${phpLiteral(mapped, 1)};\n\n    public static function notPorted(string $symbol): never\n    {\n        throw new \\LogicException(sprintf('Bolt JS symbol \"%s\" is a generated declaration scaffold, not a PHP runtime implementation.', $symbol));\n    }\n}\n`;
}

function boltDeclarations(inventory) {
  const declarations = [];
  for (const [symbol, item] of Object.entries(inventory.bolt.classes)) {
    const line = declarationLocation(inventory, item.file, symbol);
    declarations.push({ symbol, item: { ...item, file: item.file }, kind: 'class', line, address: declarationAddress(item.file, symbol) });
  }
  for (const [symbol, item] of Object.entries(inventory.bolt.interfaces)) {
    const line = declarationLocation(inventory, item.file, symbol);
    declarations.push({ symbol, item: { ...item, file: item.file }, kind: /(?:Options|Config)$/.test(symbol) ? 'options-dto' : 'interface', line, address: declarationAddress(item.file, symbol) });
  }
  const paths = new Set();
  for (const declaration of declarations) {
    if (paths.has(declaration.address.path)) throw new Error(`Declaration path collision: ${declaration.address.path}`);
    paths.add(declaration.address.path);
  }
  return declarations.sort((left, right) => left.address.path.localeCompare(right.address.path));
}

function scaffoldDisposition(inventory, declarations) {
  const bySource = new Map(declarations.map((declaration) => [`${declaration.item.file}:${declaration.symbol}`, declaration]));
  const exports = inventory.bolt.exports.map((item) => {
    const declaration = bySource.get(`${item.file}:${item.name}`);
    return {
      symbol: item.name,
      kind: item.kind,
      file: item.file,
      line: item.location?.line ?? null,
      status: declaration ? `scaffold-${declaration.kind}` : item.kind === 're-export' ? 'unresolved-re-export' : 'declaration-only',
      scaffold: declaration ? relative(root, declaration.address.path) : null,
      source: sourceLink(item.file, item.location?.line ?? null),
    };
  });
  const counts = exports.reduce((result, item) => ({ ...result, [item.status]: (result[item.status] ?? 0) + 1 }), {});
  return { schema: 1, generatedBy: 'scripts/generate-scaffold.mjs', counts, exports };
}

function unresolvedContent(exports) {
  const unresolved = exports.filter((item) => item.kind === 're-export').map((item) => ({ file: item.file, symbol: item.name, from: item.from, location: item.location }));
  return `${JSON.stringify({ schema: 1, generatedBy: 'scripts/generate-scaffold.mjs', unresolved }, null, 2)}\n`;
}

function loadOwnership() {
  if (!existsSync(ownershipPath)) return { schema: 1, files: {} };
  const ownership = JSON.parse(readFileSync(ownershipPath, 'utf8'));
  const files = {};
  for (const [path, entry] of Object.entries(ownership.files ?? {})) {
    files[path.startsWith(`${root}/`) ? relative(root, path) : path] = entry;
  }
  return { schema: 1, files };
}

function writeOwned(path, content, ownership) {
  const desiredHash = hash(content);
  const ownershipKey = relative(root, path);
  const known = ownership.files[ownershipKey];
  if (existsSync(path)) {
    const actualHash = hash(readFileSync(path, 'utf8'));
    if (!known) throw new Error(`Refusing to overwrite unowned generated path: ${path}`);
    if (actualHash !== known.sha256) throw new Error(`Refusing to overwrite manually altered generated path: ${path}`);
    if (verify && actualHash !== desiredHash) throw new Error(`Generated file is stale: ${path}`);
    if (!verify && actualHash === desiredHash) return;
  } else if (verify) {
    throw new Error(`Generated file is missing: ${path}`);
  }
  if (!verify) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content);
  }
  ownership.files[ownershipKey] = { sha256: desiredHash, generator: 'scripts/generate-scaffold.mjs' };
}

function pruneOwnedGeneratedResources(ownership, desiredPaths) {
  const resourcePrefix = `${relative(root, join(generatedRoot, 'WebApi'))}/`;
  const resourceDirectory = join(generatedRoot, 'WebApi');
  const paths = existsSync(resourceDirectory)
    ? readdirSync(resourceDirectory, { withFileTypes: true }).filter((entry) => entry.isFile() && entry.name.endsWith('.php')).map((entry) => relative(root, join(resourceDirectory, entry.name)))
    : [];
  for (const path of paths) {
    if (desiredPaths.has(path)) continue;
    const absolutePath = join(root, path);
    const entry = ownership.files[path];
    if (!entry) throw new Error(`Refusing to remove unowned generated resource orphan: ${absolutePath}`);
    if (hash(readFileSync(absolutePath, 'utf8')) !== entry.sha256) {
      throw new Error(`Refusing to remove manually altered generated path: ${absolutePath}`);
    }
    if (verify) throw new Error(`Generated resource orphaned by the current inventory: ${path}`);
    rmSync(absolutePath);
    delete ownership.files[path];
  }
  for (const path of Object.keys(ownership.files)) {
    if (!path.startsWith(resourcePrefix) || desiredPaths.has(path) || existsSync(join(root, path))) continue;
    if (verify) throw new Error(`Generated resource ownership record has no file: ${path}`);
    delete ownership.files[path];
  }
}

function main() {
  if (!existsSync(inventoryPath)) throw new Error('Missing TypeScript inventory. Run npm run extract:typescript first.');
  const inventory = JSON.parse(readFileSync(inventoryPath, 'utf8'));
  const methods = inventory.sdk.webApiMethods;
  if (!Array.isArray(methods) || methods.length === 0) throw new Error('No Web API methods were extracted from the pinned source.');
  const tree = resourceTree(methods);
  const modelsByMethod = new Map((inventory.sdk.webApiModels ?? []).map((model) => [model.method, model]));
  const resources = resourceEntries(tree);
  const rootGroups = [...tree.children.keys()].sort();
  const classNames = new Set();
  for (const resource of resources) {
    const className = resourceClassName(resource.path);
    if (classNames.has(className)) throw new Error(`Generated resource class collision: ${className}`);
    classNames.add(className);
  }
  const ownership = loadOwnership();
  const desiredResourcePaths = new Set([
    relative(root, join(generatedRoot, 'WebApi', 'HasResources.php')),
    ...resources.map((resource) => relative(root, join(generatedRoot, 'WebApi', `${resourceClassName(resource.path)}.php`))),
  ]);
  pruneOwnedGeneratedResources(ownership, desiredResourcePaths);
  for (const resource of resources) {
    writeOwned(join(generatedRoot, 'WebApi', `${resourceClassName(resource.path)}.php`), resourceContent(resource, modelsByMethod), ownership);
  }
  writeOwned(join(generatedRoot, 'WebApi', 'HasResources.php'), traitContent(rootGroups), ownership);
  const declarations = boltDeclarations(inventory);
  const disposition = scaffoldDisposition(inventory, declarations);
  writeOwned(join(scaffoldRoot, 'BoltJsExportInventory.php'), skipDiscovery(scaffoldContent(disposition)), ownership);
  writeOwned(join(root, 'docs', 'research', 'scaffold-disposition.json'), `${JSON.stringify(disposition, null, 2)}\n`, ownership);
  writeOwned(join(scaffoldRoot, 'Bolt', 'NotPortedYet.php'), skipDiscovery(notPortedContent()), ownership);
  for (const declaration of declarations) {
    const content = declaration.kind === 'class'
      ? classScaffoldContent(declaration.symbol, declaration.item, declaration.address, declaration.line)
      : declaration.kind === 'options-dto'
        ? optionsDtoContent(declaration.symbol, declaration.item, declaration.address, declaration.line)
        : interfaceScaffoldContent(declaration.symbol, declaration.item, declaration.address, declaration.line);
    writeOwned(declaration.address.path, skipDiscovery(content), ownership);
  }
  writeOwned(join(root, 'docs', 'research', 'unresolved-typescript-constructs.json'), unresolvedContent(inventory.bolt.exports), ownership);
  if (!verify) writeFileSync(ownershipPath, `${JSON.stringify(ownership, null, 2)}\n`);
  console.log(`${verify ? 'Verified' : 'Generated'} ${methods.length} Web API methods across ${rootGroups.length} root groups and ${resources.length} typed resources, ${declarations.length} Bolt declarations, and ${inventory.bolt.exports.length} Bolt export mappings.`);
}

main();
