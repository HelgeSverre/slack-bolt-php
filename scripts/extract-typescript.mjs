import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import ts from 'typescript';

const root = fileURLToPath(new URL('..', import.meta.url));
const references = join(root, 'work', 'reference-sources');
const output = join(root, 'docs', 'research');
const catalogPath = join(output, 'typescript-api-catalog.json');
const boltCommit = 'ffbe0b6c03a6b1e16b3d5d3c5c9a8ab3effcbc1d';
const sdkCommit = 'e026671c42786248349feb6906d8ce45815f73c6';
const typesCommit = 'c663dc06c362cffbb11773cc85228eb4f943630c';

function pascal(value) {
  return value.split(/[^A-Za-z0-9]+/).filter(Boolean).map((part) => `${part[0].toUpperCase()}${part.slice(1)}`).join('');
}

function walk(directory) {
  const entries = readdirSync(directory, { withFileTypes: true });
  return entries.flatMap((entry) => {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) return walk(path);
    return entry.name.endsWith('.ts') && !entry.name.endsWith('.d.ts') && !entry.name.endsWith('.test.ts') ? [path] : [];
  });
}

function findExtractedRoot(prefix) {
  const candidate = join(references, prefix);
  if (!existsSync(candidate)) throw new Error(`Missing ${candidate}. Run npm run sync:references first.`);
  const entries = readdirSync(candidate, { withFileTypes: true }).filter((entry) => entry.isDirectory());
  if (entries.length !== 1) throw new Error(`Expected a single archive root beneath ${candidate}.`);
  return join(candidate, entries[0].name);
}

function hasModifier(node, kind) {
  return (node.modifiers ?? []).some((modifier) => modifier.kind === kind);
}

function isExported(node) {
  return hasModifier(node, ts.SyntaxKind.ExportKeyword) || hasModifier(node, ts.SyntaxKind.DefaultKeyword);
}

function sourceLocation(source, node) {
  const position = source.getLineAndCharacterOfPosition(node.getStart(source));
  return { line: position.line + 1, column: position.character + 1 };
}

function typeText(node, source) {
  return node ? node.getText(source).replace(/\s+/g, ' ').trim() : null;
}

function members(node, source) {
  return node.members.map((member) => {
    const name = member.name?.getText(source) ?? '(call signature)';
    const method = ts.isMethodDeclaration(member) || ts.isMethodSignature(member) || ts.isConstructSignatureDeclaration(member);
    const property = ts.isPropertyDeclaration(member) || ts.isPropertySignature(member);
    return {
      name,
      kind: method ? 'method' : property ? 'property' : ts.SyntaxKind[member.kind],
      optional: Boolean(member.questionToken),
      visibility: hasModifier(member, ts.SyntaxKind.PrivateKeyword) ? 'private' : hasModifier(member, ts.SyntaxKind.ProtectedKeyword) ? 'protected' : 'public',
      parameters: method ? member.parameters.map((parameter) => ({ name: parameter.name.getText(source), type: typeText(parameter.type, source), optional: Boolean(parameter.questionToken || parameter.initializer) })) : [],
      type: typeText(member.type, source),
      location: sourceLocation(source, member),
    };
  });
}

function declarationRecord(node, source) {
  const name = node.name?.getText(source) ?? 'default';
  const kind = ts.SyntaxKind[node.kind];
  const record = { name, kind, location: sourceLocation(source, node) };
  if (ts.isClassDeclaration(node) || ts.isInterfaceDeclaration(node)) {
    record.members = members(node, source);
  }
  if (ts.isFunctionDeclaration(node)) {
    record.parameters = node.parameters.map((parameter) => ({ name: parameter.name.getText(source), type: typeText(parameter.type, source), optional: Boolean(parameter.questionToken || parameter.initializer) }));
    record.returnType = typeText(node.type, source);
  }
  if (ts.isTypeAliasDeclaration(node)) record.type = typeText(node.type, source);
  return record;
}

function parseTree(projectRoot, label, sourceDirectory = 'src') {
  const files = walk(join(projectRoot, sourceDirectory)).sort();
  const inventory = { label, projectRoot: relative(root, projectRoot), files: [], exports: [], interfaces: {}, classes: {} };
  for (const file of files) {
    const contents = readFileSync(file, 'utf8');
    const source = ts.createSourceFile(file, contents, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
    const relativeFile = relative(projectRoot, file);
    const fileRecord = { path: relativeFile, exports: [] };
    source.forEachChild((node) => {
      if (ts.isExportDeclaration(node)) {
        const names = node.exportClause && ts.isNamedExports(node.exportClause)
          ? node.exportClause.elements.map((element) => element.name.text)
          : ['*'];
        for (const name of names) {
          const item = { name, kind: 're-export', from: node.moduleSpecifier?.getText(source) ?? null, location: sourceLocation(source, node) };
          fileRecord.exports.push(item);
          inventory.exports.push({ file: relativeFile, ...item });
        }
        return;
      }
      if (!isExported(node)) return;
      if (!(ts.isClassDeclaration(node) || ts.isInterfaceDeclaration(node) || ts.isFunctionDeclaration(node) || ts.isTypeAliasDeclaration(node) || ts.isEnumDeclaration(node) || ts.isVariableStatement(node))) return;
      if (ts.isVariableStatement(node)) {
        for (const declaration of node.declarationList.declarations) {
          const item = { name: declaration.name.getText(source), kind: 'VariableDeclaration', location: sourceLocation(source, declaration), type: typeText(declaration.type, source) };
          fileRecord.exports.push(item);
          inventory.exports.push({ file: relativeFile, ...item });
        }
        return;
      }
      const item = declarationRecord(node, source);
      fileRecord.exports.push(item);
      inventory.exports.push({ file: relativeFile, ...item });
      if (ts.isInterfaceDeclaration(node)) inventory.interfaces[item.name] = { file: relativeFile, members: item.members };
      if (ts.isClassDeclaration(node)) inventory.classes[item.name] = { file: relativeFile, members: item.members };
    });
    inventory.files.push(fileRecord);
  }
  return inventory;
}

function extractWebApiMethods(projectRoot) {
  const sourcePath = join(projectRoot, 'src', 'methods.ts');
  const source = ts.createSourceFile(sourcePath, readFileSync(sourcePath, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
  const methods = new Set();
  const visit = (node) => {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text.startsWith('bindApiCall')) {
      for (const argument of node.arguments) {
        if (ts.isStringLiteral(argument) && /^[a-z][A-Za-z0-9]*(?:\.[A-Za-z0-9]+)+$/.test(argument.text)) methods.add(argument.text);
      }
    }
    ts.forEachChild(node, visit);
  };
  visit(source);
  return [...methods].sort();
}

/**
 * Capture each direct Web API method plus a bounded nested result graph.
 * Generation interns structurally identical nested objects, while polymorphic
 * and index-signature fields remain raw rather than receiving unsound PHP types.
 */
function extractWebApiModels(projectRoot) {
  const methodsPath = join(projectRoot, 'src', 'methods.ts');
  const requestFiles = walk(join(projectRoot, 'src', 'types', 'request'));
  const responseFiles = walk(join(projectRoot, 'src', 'types', 'response'));
  const program = ts.createProgram([methodsPath, ...requestFiles, ...responseFiles], {
    allowJs: false,
    noEmit: true,
    skipLibCheck: true,
    target: ts.ScriptTarget.ESNext,
    module: ts.ModuleKind.NodeNext,
    moduleResolution: ts.ModuleResolutionKind.NodeNext,
  });
  const checker = program.getTypeChecker();
  const source = program.getSourceFile(methodsPath);
  if (!source) throw new Error(`Unable to parse ${methodsPath}.`);

  const propertyRecord = (symbol, fallback) => {
    const declaration = symbol.valueDeclaration ?? symbol.declarations?.[0] ?? fallback;
    const propertyType = checker.getTypeOfSymbolAtLocation(symbol, declaration);
    return {
      name: symbol.getName(),
      optional: Boolean(symbol.flags & ts.SymbolFlags.Optional),
      type: checker.typeToString(propertyType, declaration, ts.TypeFormatFlags.NoTruncation),
    };
  };
  const propertiesFor = (typeNode) => {
    const type = checker.getTypeFromTypeNode(typeNode);
    const mergeUnion = (variants) => {
      const properties = new Map();
      for (const [index, variant] of variants.entries()) {
        const present = new Set();
        for (const symbol of checker.getPropertiesOfType(variant)) {
          if (symbol.getName().startsWith('__@')) continue;
          const property = propertyRecord(symbol, typeNode);
          present.add(property.name);
          const known = properties.get(property.name);
          if (!known) {
            properties.set(property.name, { ...property, optional: property.optional || index > 0 });
            continue;
          }
          known.optional ||= property.optional;
          if (known.type !== property.type) {
            known.type = [...new Set(`${known.type} | ${property.type}`.split(' | ').filter((item) => item !== 'never'))].join(' | ');
          }
        }
        for (const [name, property] of properties) {
          if (!present.has(name)) property.optional = true;
        }
      }
      return properties;
    };
    const properties = new Map();
    for (const component of type.isIntersection() ? type.types : [type]) {
      const componentProperties = mergeUnion(component.isUnion() ? component.types : [component]);
      for (const [name, property] of componentProperties) {
        const known = properties.get(name);
        if (!known) {
          properties.set(name, property);
          continue;
        }
        known.optional &&= property.optional;
        if (known.type !== property.type) {
          known.type = [...new Set(`${known.type} | ${property.type}`.split(' | ').filter((item) => item !== 'never'))].join(' | ');
        }
      }
    }
    return [...properties.values()].sort((left, right) => left.name.localeCompare(right.name));
  };
  const nestedFor = (typeNode) => {
    const reservedClassNames = new Set(['List', 'Self', 'Parent', 'Static', 'Null', 'True', 'False', 'Enum', 'Match', 'Readonly']);
    const usedNames = new Set();
    const nameFor = (path) => {
      const candidate = path.map(pascal).join('');
      const safe = reservedClassNames.has(candidate) ? `${candidate}Value` : candidate;
      let name = safe;
      let suffix = 2;
      while (usedNames.has(name)) name = `${safe}${suffix++}`;
      usedNames.add(name);
      return name;
    };
    const childFor = (symbol, location, path, ancestors) => {
      let propertyType = checker.getTypeOfSymbolAtLocation(symbol, location);
      let list = false;
      const arrayMember = (type) => {
        if (checker.isArrayType(type) || checker.isTupleType(type)) return type;
        if (type.isIntersection()) return type.types.find((part) => checker.isArrayType(part) || checker.isTupleType(part));
        return null;
      };
      const arrayType = arrayMember(propertyType);
      if (arrayType) {
        const element = checker.getTypeArguments(arrayType)[0];
        if (!element) return null;
        propertyType = element;
        list = true;
      }
      const variants = propertyType.isUnion()
        ? propertyType.types.filter((variant) => !((variant.flags & ts.TypeFlags.Null) || (variant.flags & ts.TypeFlags.Undefined)))
        : [propertyType];
      if (variants.length !== 1) return null;
      const value = variants[0];
      if (value.flags & (ts.TypeFlags.Any | ts.TypeFlags.Unknown | ts.TypeFlags.StringLike | ts.TypeFlags.NumberLike | ts.TypeFlags.BooleanLike | ts.TypeFlags.ESSymbolLike | ts.TypeFlags.BigIntLike)) return null;
      const identity = value.id ?? checker.typeToString(value);
      if (ancestors.has(identity) || path.length > 5) return null;
      const members = checker.getPropertiesOfType(value).filter((member) => !member.getName().startsWith('__@'));
      if (members.length === 0) return null;
      const nextAncestors = new Set(ancestors);
      nextAncestors.add(identity);
      const properties = members.map((member) => propertyRecord(member, location)).sort((left, right) => left.name.localeCompare(right.name));
      const nextPath = [...path, symbol.getName()];
      return {
        wire: symbol.getName(),
        className: nameFor(nextPath),
        list,
        properties,
        nested: members.map((member) => childFor(member, location, nextPath, nextAncestors)).filter(Boolean).sort((left, right) => left.wire.localeCompare(right.wire)),
      };
    };
    const rootType = checker.getTypeFromTypeNode(typeNode);
    return checker.getPropertiesOfType(rootType)
      .filter((symbol) => !symbol.getName().startsWith('__@'))
      .map((symbol) => childFor(symbol, symbol.valueDeclaration ?? symbol.declarations?.[0] ?? typeNode, [], new Set()))
      .filter(Boolean)
      .sort((left, right) => left.wire.localeCompare(right.wire));
  };

  const models = [];
  const visit = (node) => {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text.startsWith('bindApiCall')
      && node.typeArguments?.length === 2) {
      const method = node.arguments.find((argument) => ts.isStringLiteral(argument));
      if (method && ts.isStringLiteral(method)) {
        const request = node.typeArguments[0];
        const response = node.typeArguments[1];
        models.push({
          method: method.text,
          request: { name: request.getText(source), properties: propertiesFor(request) },
          response: { name: response.getText(source), properties: propertiesFor(response), nested: nestedFor(response) },
        });
      }
    }
    ts.forEachChild(node, visit);
  };
  visit(source);
  return models.sort((left, right) => left.method.localeCompare(right.method));
}

function markdownBoltSummary(inventory) {
  const app = inventory.classes.App;
  const options = inventory.interfaces.AppOptions;
  const lines = [
    '# Bolt JS v5.1.0 extracted API surface',
    '',
    `Generated from ${inventory.files.length} TypeScript source files using the TypeScript compiler API.`,
    '',
    '## App public surface',
    '',
    ...(app?.members ?? []).filter((member) => member.visibility === 'public').map((member) => `- \`${member.name}\` — ${member.kind}${member.type ? `: \`${member.type}\`` : ''}`),
    '',
    '## AppOptions keys and types',
    '',
    ...(options?.members ?? []).filter((member) => member.kind === 'property').map((member) => `- \`${member.name}${member.optional ? '?' : ''}\` — \`${member.type}\``),
    '',
    '## Export groups by source module',
    '',
    ...inventory.files.filter((file) => file.exports.length > 0).flatMap((file) => [
      `### \`${file.path}\``,
      '',
      ...file.exports.map((item) => `- \`${item.name}\` — ${item.kind}${item.from ? ` from ${item.from}` : ''}`),
      '',
    ]),
    'The complete machine-readable catalog is `typescript-api-catalog.json`; source locations are pinned to the fetched upstream archive.',
    '',
  ];
  return lines.join('\n');
}

function main() {
  mkdirSync(output, { recursive: true });
  const bolt = parseTree(findExtractedRoot(`bolt-js-${boltCommit}`), 'bolt-js@5.1.0');
  const sdkRoot = join(findExtractedRoot(`node-slack-sdk-${sdkCommit}`), 'packages', 'web-api');
  const sdk = parseTree(sdkRoot, '@slack/web-api@8.1.1');
  sdk.webApiMethods = extractWebApiMethods(sdkRoot);
  sdk.webApiModels = extractWebApiModels(sdkRoot);
  sdk.convenienceHelpers = [{ name: 'files.uploadV2', status: 'convenience-helper', reason: 'SDK-side upload orchestration; PHP exposes Client::upload()/uploadMany() and files()->uploadV2(), never a direct endpoint.' }];
  const typesRoot = join(findExtractedRoot(`node-slack-types-${typesCommit}`), 'packages', 'types');
  const sdkTypes = parseTree(typesRoot, '@slack/types@3.1.0');
  const catalog = { schema: 1, generatedBy: 'scripts/extract-typescript.mjs', bolt, sdk, sdkTypes };
  const inventory = {
    schema: 1,
    generatedBy: 'scripts/extract-typescript.mjs',
    catalog: 'typescript-api-catalog.json',
    bolt: { label: bolt.label, files: bolt.files.length, exports: bolt.exports.length, classes: Object.keys(bolt.classes).sort(), interfaces: Object.keys(bolt.interfaces).sort() },
    sdk: { label: sdk.label, files: sdk.files.length, exportedSymbols: sdk.exports.length, webApiMethods: sdk.webApiMethods, webApiModelCount: sdk.webApiModels.length, modelCatalog: 'typescript-api-catalog.json#sdk.webApiModels', convenienceHelpers: sdk.convenienceHelpers },
    sdkTypes: { label: sdkTypes.label, files: sdkTypes.files.length, exportedSymbols: sdkTypes.exports.length, classes: Object.keys(sdkTypes.classes).sort(), interfaces: Object.keys(sdkTypes.interfaces).sort() },
  };
  writeFileSync(catalogPath, `${JSON.stringify(catalog, null, 2)}\n`);
  writeFileSync(join(output, 'api-parity-inventory.json'), `${JSON.stringify(inventory, null, 2)}\n`);
  writeFileSync(join(output, 'bolt-js-api-surface.md'), markdownBoltSummary(bolt));
  writeFileSync(join(output, 'node-sdk-api-surface.json'), `${JSON.stringify({ schema: 1, catalog: 'typescript-api-catalog.json', label: sdk.label, files: sdk.files.length, webApiMethods: sdk.webApiMethods, convenienceHelpers: sdk.convenienceHelpers }, null, 2)}\n`);
  console.log(`Extracted ${bolt.files.length} Bolt, ${sdk.files.length} Web API, and ${sdkTypes.files.length} Slack Types TypeScript files.`);
}

main();
