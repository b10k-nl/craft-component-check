#!/usr/bin/env node
// Used by `php craft component-regression/doctor`. Prints one JSON line.
import fs from 'node:fs';
import { loadPlaywright } from './lib/playwright.mjs';

const info = { node: process.version, playwright: null, chromium: false, error: null };

try {
  const loaded = await loadPlaywright();
  if (loaded) {
    info.playwright = loaded.version ?? 'unknown';
    const executable = loaded.playwright.chromium.executablePath();
    info.chromium = Boolean(executable) && fs.existsSync(executable);
  }
} catch (e) {
  info.error = String(e?.message ?? e);
}

process.stdout.write(JSON.stringify(info) + '\n');
