import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = (process.env.THUNDER_BOLT_ROOT ?? fileURLToPath(new URL('..', import.meta.url))).replace(/\/$/, '');
const inventoryPath = join(root, 'docs', 'research', 'typescript-api-catalog.json');
const generatedRoot = join(root, 'src', 'Generated', 'WebApi', 'Models');
const ownershipPath = join(root, 'src', 'Generated', '.ownership.json');
const coveragePath = join(root, 'docs', 'research', 'web-api-model-coverage.json');
const coverageSummaryPath = join(root, 'docs', 'research', 'web-api-model-coverage.md');
const verify = process.argv.includes('--verify');
const source = 'https://github.com/slackapi/node-slack-sdk/blob/e026671c42786248349feb6906d8ce45815f73c6/packages/web-api';
const hash = (content) => createHash('sha256').update(content).digest('hex');
const reserved = new Set(['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace', 'new', 'never', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield']);

function pascal(value) {
  return value.split(/[^A-Za-z0-9]+/).filter(Boolean).map((part) => `${part[0].toUpperCase()}${part.slice(1)}`).join('');
}

function modelName(method) {
  return method.split('.').map(pascal).join('');
}

function propertyName(name) {
  const normalized = name.replace(/_([a-zA-Z0-9])/g, (_, letter) => letter.toUpperCase()).replace(/[^A-Za-z0-9_]/g, '');
  const safe = normalized === '' || /^\d/.test(normalized) ? `value${pascal(normalized)}` : normalized;
  return reserved.has(safe.toLowerCase()) || ['data', 'extra'].includes(safe.toLowerCase()) ? `${safe}Value` : safe;
}

function splitTopLevelUnion(type) {
  const parts = [];
  let start = 0;
  let depth = 0;
  for (let index = 0; index < type.length; index += 1) {
    const character = type[index];
    if ('<({['.includes(character)) depth += 1;
    else if ('>)}]'.includes(character)) depth -= 1;
    else if (character === '|' && depth === 0) {
      parts.push(type.slice(start, index).trim());
      start = index + 1;
    }
  }
  parts.push(type.slice(start).trim());
  return parts.filter(Boolean);
}

function phpType(sourceType, optional) {
  const type = sourceType.replace(/\s+/g, ' ').trim();
  const mapped = new Set();
  for (const piece of splitTopLevelUnion(type)) {
    if (piece === 'never' || piece === 'undefined') continue;
    if (piece === 'null') {
      mapped.add('null');
      continue;
    }
    if (piece === 'string' || /^".*"$/.test(piece) || /^'.*'$/.test(piece)) {
      mapped.add('string');
      continue;
    }
    if (piece === 'boolean' || piece === 'true' || piece === 'false') {
      mapped.add('bool');
      continue;
    }
    if (piece === 'number' || /^\d+(?:\.\d+)?$/.test(piece)) {
      mapped.add('int');
      mapped.add('float');
      continue;
    }
    if (piece.includes('[]') || piece.includes('Array<') || piece.startsWith('Record<') || piece.startsWith('{')) {
      mapped.add('array');
      continue;
    }
    mapped.add('mixed');
  }
  if (optional) mapped.add('null');
  if (mapped.has('mixed')) return 'mixed';
  if (mapped.size === 0) return 'mixed';
  return ['array', 'bool', 'float', 'int', 'string', 'null'].filter((candidate) => mapped.has(candidate)).join('|');
}

function sensitive(property) {
  return /(token|secret|password|code)/i.test(property.name);
}

function loadOwnership() {
  if (!existsSync(ownershipPath)) return { schema: 1, files: {} };
  const ownership = JSON.parse(readFileSync(ownershipPath, 'utf8'));
  return { schema: 1, files: ownership.files ?? {} };
}

function writeOwned(path, content, ownership) {
  const key = relative(root, path);
  const desired = hash(content);
  const known = ownership.files[key];
  if (existsSync(path)) {
    const actual = hash(readFileSync(path, 'utf8'));
    if (!known) throw new Error(`Refusing to overwrite unowned generated path: ${path}`);
    if (actual !== known.sha256) throw new Error(`Refusing to overwrite manually altered generated path: ${path}`);
    if (verify && actual !== desired) throw new Error(`Generated file is stale: ${path}`);
    if (!verify && actual === desired) return;
  } else if (verify) {
    throw new Error(`Generated file is missing: ${path}`);
  }
  if (!verify) {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content);
  }
  ownership.files[key] = { sha256: desired, generator: 'scripts/generate-web-api-models.mjs' };
}

function writeDerived(path, content) {
  if (verify) {
    if (!existsSync(path)) throw new Error(`Generated coverage report is missing: ${path}`);
    if (readFileSync(path, 'utf8') !== content) throw new Error(`Generated coverage report is stale: ${path}`);
    return;
  }
  mkdirSync(dirname(path), { recursive: true });
  writeFileSync(path, content);
}

function allPhpFiles(directory) {
  if (!existsSync(directory)) return [];
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? allPhpFiles(path) : entry.name.endsWith('.php') ? [path] : [];
  });
}

function prune(ownership, desiredPaths) {
  for (const absolute of allPhpFiles(generatedRoot)) {
    const path = relative(root, absolute);
    if (desiredPaths.has(path)) continue;
    const known = ownership.files[path];
    if (!known) throw new Error(`Refusing to remove unowned generated model: ${absolute}`);
    if (hash(readFileSync(absolute, 'utf8')) !== known.sha256) throw new Error(`Refusing to remove manually altered generated path: ${absolute}`);
    if (verify) throw new Error(`Generated model orphaned by the current inventory: ${path}`);
    rmSync(absolute);
    delete ownership.files[path];
  }
}

function normalize(properties) {
  const names = new Set();
  return properties.map((property) => {
    const base = propertyName(property.name);
    let name = base;
    let suffix = 2;
    while (names.has(name)) name = `${base}${suffix++}`;
    names.add(name);
    return { ...property, phpName: name, phpType: phpType(property.type, property.optional) };
  }).sort((left, right) => Number(left.optional) - Number(right.optional) || left.phpName.localeCompare(right.phpName));
}

function requestContent(model) {
  const className = `${modelName(model.method)}Request`;
  const properties = normalize(model.request.properties);
  const parameters = properties.map((property) => `        ${sensitive(property) ? '#[\\SensitiveParameter] ' : ''}public ${property.phpType} $${property.phpName}${property.optional ? ' = null' : ''},`).join('\n');
  const fields = properties.map((property) => property.optional
    ? `        if ($this->${property.phpName} !== null) $data['${property.name}'] = $this->${property.phpName};`
    : `        $data['${property.name}'] = $this->${property.phpName};`).join('\n');
  return `<?php

declare(strict_types=1);

/**
 * @generated by scripts/generate-web-api-models.mjs; do not edit manually.
 * Source: ${source}/src/types/request
 * Complex TypeScript values remain mixed; pass unsupported fields through $extra.
 */
namespace Thunder\\Bolt\\Generated\\WebApi\\Models\\Requests;

use Thunder\\Bolt\\WebApi\\RequestPayload;

final readonly class ${className} implements RequestPayload
{
    public function __construct(
${parameters}${parameters ? '\n' : ''}        public array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        $data = $this->extra;
${fields}

        return $data;
    }
}
`;
}

function hydrator(property) {
  const present = `$data['${property.name}'] ?? null`;
  const value = `$data['${property.name}']`;
  const invalid = property.optional ? 'null' : `throw new \\UnexpectedValueException('Slack returned an invalid ${property.name} field.')`;
  if (property.phpType === 'mixed') return present;
  const types = new Set(property.phpType.split('|'));
  const guards = [];
  if (types.has('array')) guards.push(`is_array(${present})`);
  if (types.has('bool')) guards.push(`is_bool(${present})`);
  if (types.has('float') || types.has('int')) guards.push(`is_int(${present}) || is_float(${present})`);
  if (types.has('string')) guards.push(`is_string(${present})`);
  if (types.has('null')) guards.push(`(${present}) === null`);
  if (guards.length === 0) return present;
  return `(${guards.join(' || ')}) ? ${types.has('null') ? present : value} : ${invalid}`;
}

function nestedHydrator(property, nested) {
  const present = `$data['${property.name}'] ?? null`;
  const value = `$data['${property.name}']`;
  if (!nested) return hydrator(property);
  if (nested.list) {
    return `is_array(${present}) ? array_map(static fn (mixed $value): mixed => is_array($value) ? ${nested.className}::fromArray($value) : $value, ${value}) : null`;
  }
  return `is_array(${present}) ? ${nested.className}::fromArray(${value}) : null`;
}

function nestedContent(nested) {
  const properties = normalize(nested.properties);
  const nestedByWire = new Map((nested.nested ?? []).map((child) => [child.wire, child]));
  const parameters = properties.map((property) => {
    const child = nestedByWire.get(property.name);
    if (!child) return `        ${sensitive(property) ? '#[\\SensitiveParameter] ' : ''}public ${property.phpType.includes('mixed') ? 'mixed' : property.phpType} $${property.phpName}${property.optional ? ' = null' : ''},`;
    return `        ${sensitive(property) ? '#[\\SensitiveParameter] ' : ''}public ${child.list ? 'array|null' : `?${child.className}`} $${property.phpName} = null,`;
  }).join('\n');
  const hydrationArguments = properties.map((property) => `            ${property.phpName}: ${nestedHydrator(property, nestedByWire.get(property.name))},`).join('\n');
  return `<?php

declare(strict_types=1);

/**
 * @generated by scripts/generate-web-api-models.mjs; do not edit manually.
 * Source: ${source}/src/types/response
 * Nested unsupported fields remain available in inherited $data.
 */
namespace Thunder\\Bolt\\Generated\\WebApi\\Models\\Responses\\Common;

use Thunder\\Bolt\\WebApi\\ResponseObject;

final readonly class ${nested.className} extends ResponseObject
{
    private function __construct(array $data,
${parameters}
    ) {
        parent::__construct($data);
    }

    public static function fromArray(array $data): self
    {
        return new self($data,
${hydrationArguments}
        );
    }
}
`;
}

function nestedFingerprint(nested) {
  return JSON.stringify({
    list: nested.list,
    properties: nested.properties.map(({ name, optional, type }) => ({ name, optional, type })),
    nested: (nested.nested ?? []).map((child) => ({ wire: child.wire, shape: nestedFingerprint(child) })),
  });
}

function internNestedModels(models) {
  const shapes = new Map();
  const collect = (nested) => {
    for (const child of nested.nested ?? []) collect(child);
    const fingerprint = nestedFingerprint(nested);
    if (!shapes.has(fingerprint)) shapes.set(fingerprint, nested);
    return fingerprint;
  };
  for (const model of models) for (const nested of model.response.nested ?? []) collect(nested);
  const names = new Map();
  const usedNames = new Set();
  for (const [fingerprint, nested] of shapes) {
    const base = nested.className;
    const name = usedNames.has(base) ? `${base}${hash(fingerprint).slice(0, 8)}` : base;
    usedNames.add(name);
    names.set(fingerprint, name);
  }
  const materialize = (nested) => {
    const fingerprint = nestedFingerprint(nested);
    return { ...nested, className: names.get(fingerprint), nested: (nested.nested ?? []).map(materialize) };
  };
  return {
    models: models.map((model) => ({ ...model, response: { ...model.response, nested: (model.response.nested ?? []).map(materialize) } })),
    nested: [...shapes.entries()].map(([fingerprint, nested]) => ({ ...materialize(nested), fingerprint })).sort((left, right) => left.className.localeCompare(right.className)),
  };
}

function flattenNested(nested, prefix = []) {
  return nested.flatMap((item) => [
    { ...item, path: [...prefix, item.wire] },
    ...flattenNested(item.nested ?? [], [...prefix, item.wire]),
  ]);
}

function unresolved(properties, nestedByWire, prefix = '') {
  return properties
    .filter((property) => !nestedByWire.has(property.name) && phpType(property.type, property.optional) === 'mixed')
    .map((property) => ({ field: `${prefix}${property.name}`, reason: 'TypeScript type is polymorphic, indexed, or otherwise not representable as a native PHP property type; preserved in raw data.' }));
}

function coverageReport(inventory, sourceModels, commonNested) {
  const endpoints = sourceModels.map((model) => {
    const responseNested = model.response.nested ?? [];
    const directNested = new Map(responseNested.map((item) => [item.wire, item]));
    const descendants = flattenNested(responseNested);
    const responseUnresolved = [
      ...unresolved(model.response.properties, directNested),
      ...descendants.flatMap((item) => unresolved(item.properties, new Map((item.nested ?? []).map((child) => [child.wire, child])), `${item.path.join('.')}.`)),
    ];
    const requestUnresolved = unresolved(model.request.properties, new Map());
    return {
      method: model.method,
      request: {
        class: `Thunder\\Bolt\\Generated\\WebApi\\Models\\Requests\\${modelName(model.method)}Request`,
        fields: model.request.properties.length,
        unresolved: requestUnresolved,
        unknownFields: 'The $extra map preserves fields unknown to the pinned TypeScript declaration.',
      },
      response: {
        class: `Thunder\\Bolt\\Generated\\WebApi\\Models\\Responses\\${modelName(model.method)}Response`,
        fields: model.response.properties.length,
        nestedOccurrences: descendants.length,
        unresolved: responseUnresolved,
        unknownFields: 'Inherited $data preserves fields unknown to the pinned TypeScript declaration.',
      },
    };
  });
  const unresolvedRequests = endpoints.reduce((count, endpoint) => count + endpoint.request.unresolved.length, 0);
  const unresolvedResponses = endpoints.reduce((count, endpoint) => count + endpoint.response.unresolved.length, 0);
  const nestedOccurrences = endpoints.reduce((count, endpoint) => count + endpoint.response.nestedOccurrences, 0);
  const report = {
    schema: 1,
    source: `${source}/src/types`,
    methods: endpoints.length,
    generated: {
      requestModels: endpoints.length,
      responseModels: endpoints.length,
      commonNestedShapes: commonNested.length,
      nestedOccurrences,
    },
    limits: [
      'Native scalar, array, and structural object fields are typed and hydrated recursively through six structural levels.',
      'Polymorphic, indexed, and TypeScript-only fields remain raw rather than receiving an unsound PHP type.',
      'All unknown response fields remain in Response::$data; all unknown request fields can be passed through Request::$extra.',
    ],
    unresolved: { requestFields: unresolvedRequests, responseFields: unresolvedResponses },
    endpoints,
  };
  const markdown = [
    '# Generated Web API model coverage',
    '',
    `Generated from the pinned Slack TypeScript declarations at ${report.source}.`,
    '',
    `- ${report.methods} endpoint request/response model pairs`,
    `- ${report.generated.commonNestedShapes} interned nested structural DTO shapes across ${nestedOccurrences} endpoint occurrences`,
    `- ${unresolvedRequests} request fields and ${unresolvedResponses} response fields deliberately remain raw`,
    '',
    '## Fidelity limits',
    '',
    ...report.limits.map((limit) => `- ${limit}`),
    '',
    'The accompanying JSON lists every endpoint and every raw field with its reason.',
    '',
  ].join('\n');
  return { json: `${JSON.stringify(report, null, 2)}\n`, markdown };
}

function responseContent(model) {
  const className = `${modelName(model.method)}Response`;
  const properties = normalize(model.response.properties);
  const nestedByWire = new Map((model.response.nested ?? []).map((nested) => [nested.wire, nested]));
  const imports = [];
  const parameters = properties.map((property) => {
    const nested = nestedByWire.get(property.name);
    if (!nested) return `        ${sensitive(property) ? '#[\\SensitiveParameter] ' : ''}public ${property.phpType.includes('mixed') ? 'mixed' : property.phpType} $${property.phpName}${property.optional ? ' = null' : ''},`;
    imports.push(`use Thunder\\Bolt\\Generated\\WebApi\\Models\\Responses\\Common\\${nested.className};`);
    const type = nested.list ? 'array|null' : `?${nested.className}`;
    return `        ${sensitive(property) ? '#[\\SensitiveParameter] ' : ''}public ${type} $${property.phpName} = null,`;
  }).join('\n');
  const hydrationArguments = properties.map((property) => `            ${property.phpName}: ${nestedHydrator(property, nestedByWire.get(property.name))},`).join('\n');
  return `<?php

declare(strict_types=1);

/**
 * @generated by scripts/generate-web-api-models.mjs; do not edit manually.
 * Source: ${source}/src/types/response
 * Unknown and deeply nested response fields remain available in inherited $data.
 */
namespace Thunder\\Bolt\\Generated\\WebApi\\Models\\Responses;

use Thunder\\Bolt\\WebApi\\Response;
use Thunder\\Bolt\\WebApi\\TypedResponse;
${[...new Set(imports)].join('\n')}

final readonly class ${className} extends Response implements TypedResponse
{
    private function __construct(array $data,
${parameters}
    ) {
        parent::__construct($data);
    }

    public static function fromResponse(Response $response): static
    {
        $data = $response->data;

        return new self($data,
${hydrationArguments}
        );
    }
}
`;
}

function main() {
  if (!existsSync(inventoryPath)) throw new Error('Missing TypeScript inventory. Run npm run extract:typescript first.');
  const inventory = JSON.parse(readFileSync(inventoryPath, 'utf8'));
  const sourceModels = inventory.sdk?.webApiModels;
  if (!Array.isArray(sourceModels) || sourceModels.length === 0) throw new Error('The inventory has no Web API model schema. Run npm run extract:typescript first.');
  const methods = new Set(inventory.sdk?.webApiMethods ?? []);
  const modelMethods = new Set(sourceModels.map((model) => model.method));
  if (methods.size !== modelMethods.size || [...methods].some((method) => !modelMethods.has(method))) throw new Error('Every extracted Web API method must have one generated request/response model.');
  const { models, nested } = internNestedModels(sourceModels);
  const ownership = loadOwnership();
  const desired = new Set();
  for (const model of models) {
    desired.add(relative(root, join(generatedRoot, 'Requests', `${modelName(model.method)}Request.php`)));
    desired.add(relative(root, join(generatedRoot, 'Responses', `${modelName(model.method)}Response.php`)));
  }
  for (const model of nested) {
    desired.add(relative(root, join(generatedRoot, 'Responses', 'Common', `${model.className}.php`)));
  }
  prune(ownership, desired);
  for (const model of models) {
    writeOwned(join(generatedRoot, 'Requests', `${modelName(model.method)}Request.php`), requestContent(model), ownership);
    writeOwned(join(generatedRoot, 'Responses', `${modelName(model.method)}Response.php`), responseContent(model), ownership);
  }
  for (const model of nested) {
    writeOwned(join(generatedRoot, 'Responses', 'Common', `${model.className}.php`), nestedContent(model), ownership);
  }
  const coverage = coverageReport(inventory, sourceModels, nested);
  writeDerived(coveragePath, coverage.json);
  writeDerived(coverageSummaryPath, coverage.markdown);
  if (!verify) writeFileSync(ownershipPath, `${JSON.stringify(ownership, null, 2)}\n`);
  console.log(`${verify ? 'Verified' : 'Generated'} ${models.length} Web API request/response model pairs.`);
}

main();
