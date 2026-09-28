#!/usr/bin/env node
// Component Check runner.
//
// Started by `php craft component-check/test`; can also be run by hand
// against a manifest from `php craft component-check/discover --json`:
//
//   node vendor/b10k/craft-component-check/runner/run.mjs \
//     --manifest manifest.json --results results.json
//
// Progress goes to stderr; stdout stays empty. Results always land in the
// results file — including fatal errors, as `error` — so the PHP side can
// report every outcome the same way.

import fs from 'node:fs';
import path from 'node:path';
import { loadPlaywright } from './lib/playwright.mjs';
import { runManifest } from './lib/runner.mjs';

function parseArgs(argv) {
  const args = { manifest: null, results: null, quiet: false, headed: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--manifest') args.manifest = argv[++i];
    else if (a === '--results') args.results = argv[++i];
    else if (a === '--quiet') args.quiet = true;
    else if (a === '--headed') args.headed = true;
  }
  return args;
}

function writeResults(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(data, null, 2));
}

const args = parseArgs(process.argv.slice(2));

if (!args.manifest) {
  process.stderr.write('Usage: run.mjs --manifest <file> [--results <file>] [--quiet] [--headed]\n');
  process.exit(2);
}

const resultsFile = args.results ?? path.join(path.dirname(args.manifest), 'results.json');
const log = args.quiet ? () => {} : (line) => process.stderr.write(line + '\n');

try {
  const manifest = JSON.parse(fs.readFileSync(args.manifest, 'utf8'));
  if (manifest.schema !== 1) {
    throw new Error(`Unsupported manifest schema ${manifest.schema}; update the plugin.`);
  }

  const loaded = await loadPlaywright();
  if (!loaded) {
    writeResults(resultsFile, {
      schema: 1,
      runs: [],
      error: 'The `playwright` package is not installed in this project. Run: npm install --save-dev playwright && npx playwright install --with-deps chromium',
    });
    process.exit(1);
  }

  const results = await runManifest(manifest, { playwright: loaded.playwright, headed: args.headed, log });
  writeResults(resultsFile, results);
} catch (error) {
  let message = String(error?.message ?? error).split('\n')[0];
  if (/Executable doesn't exist|browserType\.launch/.test(message)) {
    message = `Chromium for Playwright is not installed. Run: npx playwright install --with-deps chromium (${message})`;
  }
  writeResults(resultsFile, { schema: 1, runs: [], error: message });
  process.exit(1);
}
