import { createRequire } from 'node:module';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

/**
 * Loads `playwright` from the Craft project, not from this package.
 *
 * The runner ships inside the Composer package (vendor/b10k/...), and the
 * project installs `playwright` with npm. Resolving from the working directory
 * (the project root — the PHP command sets it) finds the project's copy even
 * when the plugin is symlinked from somewhere else during development. Falls
 * back to normal resolution from this file.
 */
export async function loadPlaywright(cwd = process.cwd()) {
  const attempts = [
    () => createRequire(path.join(cwd, 'package.json')).resolve('playwright'),
    () => createRequire(import.meta.url).resolve('playwright'),
  ];

  for (const attempt of attempts) {
    let resolved;
    try {
      resolved = attempt();
    } catch {
      continue;
    }
    const mod = await import(pathToFileURL(resolved).href);
    const playwright = mod.default ?? mod;
    let version = null;
    try {
      const req = createRequire(resolved);
      version = req(path.join(path.dirname(resolved), 'package.json')).version ?? null;
    } catch {
      // version is informational only
    }
    return { playwright, version, resolved };
  }

  return null;
}
