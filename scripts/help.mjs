const commands = [
  ['npm run sync:references', 'Download the pinned upstream tarballs, preserve licences, and write source hashes.'],
  ['npm run extract:typescript', 'Parse pinned TypeScript with the compiler API and write API inventories.'],
  ['npm run generate:web-api-models', 'Generate native endpoint request/response models and the type coverage inventory.'],
  ['npm run generate:scaffold', 'Generate inert PHP declaration scaffolds and typed Web API resource classes.'],
  ['npm run generate', 'Extract the API inventories and generate PHP output from synced references.'],
  ['npm run verify:generated', 'Fail if generated output differs from the ownership manifest or desired output.'],
  ['npm run test:tooling', 'Run generator ownership, consistency, and interface checks.'],
];

console.log('Thunder Bolt reference tooling');
console.log('');
for (const [command, description] of commands) {
  console.log(`  ${command.padEnd(43)} ${description}`);
}
console.log('');
console.log('Install the locked development tools: npm ci');
console.log('First rebuild: npm run sync:references, then npm run generate');
