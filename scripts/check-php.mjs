import { readdir, readFile } from 'node:fs/promises';
import { extname, join, relative, resolve } from 'node:path';
import Engine from 'php-parser';

const engine = new Engine({ parser: { extractDoc: true, suppressErrors: false }, ast: { withPositions: true } });
const root = resolve('.');
const ignored = new Set(['vendor', 'node_modules']);

async function files(directory) {
  const result = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (ignored.has(entry.name)) continue;
    const path = join(directory, entry.name);
    if (entry.isDirectory()) result.push(...await files(path));
    else if (extname(entry.name) === '.php' || entry.name === 'spark') result.push(path);
  }
  return result;
}

let failures = 0;
for (const file of await files(root)) {
  try {
    engine.parseCode(await readFile(file, 'utf8'), relative(root, file));
  } catch (error) {
    failures += 1;
    process.stderr.write(`${relative(root, file)}: ${error.message}\n`);
  }
}

if (failures > 0) process.exit(1);
process.stdout.write('Sintaxis PHP analizada correctamente.\n');
