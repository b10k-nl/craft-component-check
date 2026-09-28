#!/usr/bin/env node
// Component Check watch mode. Started by `php craft component-check/watch`.
//
//   node runner/watch.mjs --manifest <file> --results <file> \
//     --path <dir> [--path <dir> …] [--ignore <name> …] [--keep-snapshot] [--interval 700]
//
// Snapshots the blocks once at start (unless --keep-snapshot), keeps one
// browser open, and re-runs the comparison whenever a watched file changes.
// Keys: r = run again, s = take a new snapshot (accept the current state),
// q = quit.

import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { loadPlaywright } from './lib/playwright.mjs';
import { runManifest } from './lib/runner.mjs';
import { loadIndex } from './lib/snapshot.mjs';
import { changedFiles, formatRun, scanFiles, summarize } from './lib/watch-lib.mjs';

function parseArgs(argv) {
  const args = { manifest: null, results: null, paths: [], ignore: [], keepSnapshot: false, interval: 700, headed: false };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--manifest') args.manifest = argv[++i];
    else if (a === '--results') args.results = argv[++i];
    else if (a === '--path') args.paths.push(argv[++i]);
    else if (a === '--ignore') args.ignore.push(argv[++i]);
    else if (a === '--keep-snapshot') args.keepSnapshot = true;
    else if (a === '--interval') args.interval = Math.max(200, Number(argv[++i]) || 700);
    else if (a === '--headed') args.headed = true;
  }
  return args;
}

const color = (code) => (s) => (process.stdout.isTTY ? `\x1b[${code}m${s}\x1b[0m` : s);
const red = color(31);
const green = color(32);
const yellow = color(33);
const grey = color(90);
const out = (line = '') => {
  const painted = line.includes('✕') ? red(line) : line.includes('✓ all') ? green(line) : line.includes('warning') ? yellow(line) : line;
  process.stdout.write(painted + '\n');
};
const time = () => new Date().toTimeString().slice(0, 8);

const args = parseArgs(process.argv.slice(2));
if (!args.manifest || args.paths.length === 0) {
  process.stderr.write('Usage: watch.mjs --manifest <file> --results <file> --path <dir> [...]\n');
  process.exit(2);
}

const manifest = JSON.parse(fs.readFileSync(args.manifest, 'utf8'));
const resultsFile = args.results ?? path.join(path.dirname(args.manifest), 'results.json');
const snapshotDir = manifest.snapshot?.dir;
if (!snapshotDir) {
  process.stderr.write('The manifest has no snapshot directory.\n');
  process.exit(2);
}

const loaded = await loadPlaywright();
if (!loaded) {
  process.stderr.write('The `playwright` package is not installed in this project. Run: npm install --save-dev playwright\n');
  process.exit(2);
}

let browser;
try {
  browser = await loaded.playwright.chromium.launch({ headless: !args.headed });
} catch (e) {
  process.stderr.write(`Could not start Chromium: ${String(e?.message ?? e).split('\n')[0]}\nRun: npx playwright install chromium\n`);
  process.exit(2);
}

const cleanArtifacts = () => {
  for (const sub of ['screenshots', 'traces']) {
    fs.rmSync(path.join(manifest.outputDir, sub), { recursive: true, force: true });
  }
};

async function record(reason) {
  const results = await runManifest({ ...manifest, snapshot: { action: 'record', dir: snapshotDir } }, {
    playwright: loaded.playwright, browser,
  });
  const n = results.snapshot?.recorded ?? 0;
  out(grey(`[${time()}] ${reason}: ${n} block snapshot(s) in ${snapshotDir}`));
  if (n === 0) out(red('  ✕ No block could be located — are the markers in the block loop?'));
  return n;
}

let previous = null;
async function compare(trigger) {
  cleanArtifacts();
  const started = Date.now();
  const results = await runManifest({ ...manifest, snapshot: { action: 'compare', dir: snapshotDir } }, {
    playwright: loaded.playwright, browser,
  });
  fs.mkdirSync(path.dirname(resultsFile), { recursive: true });
  fs.writeFileSync(resultsFile, JSON.stringify(results, null, 2));

  out();
  out(grey(`[${time()}] ${trigger} — ${((Date.now() - started) / 1000).toFixed(1)}s`));
  const state = summarize(manifest, results);
  for (const line of formatRun(manifest, state, previous)) out(line);
  previous = state;
}

// Serialise runs: a change during a run queues exactly one more.
let running = false;
let pending = null;
async function schedule(kind, label) {
  if (running) {
    pending = pending?.kind === 'record' ? pending : { kind, label };
    return;
  }
  running = true;
  try {
    if (kind === 'record') {
      await record(label);
      previous = null;
    } else {
      await compare(label);
    }
  } catch (e) {
    out(red(`  ✕ ${String(e?.message ?? e).split('\n')[0]}`));
  } finally {
    running = false;
    if (pending) {
      const next = pending;
      pending = null;
      schedule(next.kind, next.label);
    }
  }
}

// Start.
const components = Object.keys(manifest.components ?? {});
out(`Component Check — watching ${components.join(', ') || 'all components'} on ${manifest.pages.length} page(s) × ${Object.keys(manifest.viewports).length} viewport(s)`);
if (args.keepSnapshot && loadIndex(snapshotDir)) {
  out(grey(`Using the existing snapshot in ${snapshotDir}`));
} else {
  running = true;
  await record('Snapshot taken');
  running = false;
}
out(grey(`Watching ${args.paths.join(', ')} — save a file to compare.${process.stdin.isTTY ? ' Keys: r run · s new snapshot · q quit' : ' Ctrl+C to stop.'}`));

// Poll for changes.
let files = scanFiles(args.paths, args.ignore);
let debounce = null;
let collected = new Set();
const poll = setInterval(() => {
  const next = scanFiles(args.paths, args.ignore);
  const changed = changedFiles(files, next);
  files = next;
  if (changed.length === 0) return;
  for (const f of changed) collected.add(f);
  clearTimeout(debounce);
  debounce = setTimeout(() => {
    const names = [...collected].map((f) => {
      const root = args.paths.find((p) => f.startsWith(p));
      return root ? path.relative(path.dirname(root), f) : f;
    });
    collected = new Set();
    const label = names.length === 1 ? `${names[0]} changed` : `${names.length} files changed (${names.slice(0, 3).join(', ')}${names.length > 3 ? ', …' : ''})`;
    schedule('compare', label);
  }, 300);
}, args.interval);

async function quit() {
  clearInterval(poll);
  clearTimeout(debounce);
  out(grey('Stopped.'));
  await browser.close().catch(() => {});
  process.exit(0);
}

process.on('SIGINT', quit);
process.on('SIGTERM', quit);

if (process.stdin.isTTY) {
  readline.emitKeypressEvents(process.stdin);
  process.stdin.setRawMode(true);
  process.stdin.on('keypress', (str, key) => {
    if (key?.ctrl && key.name === 'c') return quit();
    if (str === 'q') return quit();
    if (str === 'r') return schedule('compare', 'Run requested');
    if (str === 's') return schedule('record', 'New snapshot');
  });
}
