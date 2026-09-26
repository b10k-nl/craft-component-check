# Component Regression

**You changed the Hero. Which pages just broke on mobile?** Craft already knows
where every Hero is used. Component Regression asks Craft, picks the pages worth
testing, and runs desktop and mobile browser checks on them with Playwright —
without a single test file.

```
$ php craft component-regression/test hero

Hero (hero)
  Home                             desktop ✓   mobile ✓
    /
  About                            desktop ✓   mobile ✕
    /about
      ✕ mobile: Block #1823 is 318px wider than the viewport
        screenshot: storage/component-regression/latest/screenshots/hero/p2-mobile-1823.png
        trace:      storage/component-regression/latest/traces/p2-mobile.zip

FAILED — 3 passed, 1 failed, 0 skipped
```

It is not a replacement for Playwright, Pest or Craft Pest. Playwright does the
browser work; Craft knows the content model; this plugin connects the two.

> **Status:** `0.1.0-dev` — first working draft. Free (MIT).

---

## How it works

```
Matrix fields ─► every block on a live page ─► representative pages ─► manifest.json
                                                                           │
          report / JSON / exit code ◄── results.json ◄── Playwright (desktop, mobile)
```

1. **Discovery.** Every Matrix field, every block (nested entry), walked up to
   the page that owns it. Only live pages count: enabled, for that site, not a
   draft or revision.
2. **Sampling.** Usages are grouped into *variants* — same section, page type,
   site and the same set of filled-in fields (plus the value of dropdowns,
   radio buttons and lightswitches). Pages are chosen so every variant is
   covered with as few pages as possible. 3,000 identical Heroes → one page.
3. **Browser checks**, on every viewport:
   - the page responds (no 4xx/5xx), no uncaught JavaScript errors, no failed
     scripts or stylesheets, no sideways scrolling;
   - with markers (below): every block Craft says is on the page is rendered,
     has a size, fits the viewport, and its images load.
4. **Artifacts** for every failure: a screenshot of the failing component (not
   the whole page), and a Playwright trace.

## Requirements

- Craft CMS 5, PHP 8.2+
- Node 18+ **where `php craft` runs**, with `playwright` installed in the
  project (see Setup). Only needed to run tests — not on production.

## Setup

```bash
composer require b10k/craft-component-regression
php craft plugin/install component-regression

npm install --save-dev playwright
npx playwright install --with-deps chromium

php craft component-regression/doctor
```

`doctor` checks the mode, Node, Playwright, Chromium, the output directory,
whether the site answers at the discovered URLs, and whether your templates
have markers — and says how to fix whatever is missing.

**DDEV:** run everything with `ddev exec` / `ddev craft …`; Node is already in
the web container (`nodejs_version` in `.ddev/config.yaml`). Chromium needs its
system libraries, which `--with-deps` installs; add
`webimage_extra_packages` or a post-start hook if you want that to survive
`ddev restart`.

## Markers: from page checks to component checks

Without markers you get page-level checks. With them, the browser knows exactly
which DOM belongs to which block. Add them once, in the loop that renders your
blocks — no component needs to change:

```twig
{% for block in entry.contentBlocks.all() %}
    {{ craft.regression.start(block) }}
    {% include '_blocks/' ~ block.type.handle %}
    {{ craft.regression.end() }}
{% endfor %}
```

Or, on a component's root element:

```twig
<section class="hero" {{ craft.regression.attributes(block) }}>
```

**These render nothing** unless the request comes from a test run: the mode is
`full` *and* the request carries a signed, short-lived token that only the
`test` command can create. Visitors, editors, crawlers and production get
exactly the markup they got before. A marked-up response is sent with
`Cache-Control: no-store` so it never lands in a shared cache.

Markers nest (a card inside a cards grid); `end()` closes the most recent
`start()`.

## Commands

| Command | What it does | Needs mode |
|---|---|---|
| `component-regression/discover [components]` | Where each component is used and which pages would be tested. `--all` lists every page, `--json` prints the manifest. | `readonly` |
| `component-regression/test [components]` | Runs the browser checks. `--viewport=mobile`, `--json`, `--headed`. | `readonly` (page checks) / `full` (component checks) |
| `component-regression/doctor` | Checks the setup. `--json`. | any |

`components` is a comma-separated list of entry type handles: `hero,cards`.

**Exit codes:** `0` passed · `1` regressions found · `2` could not run (mode
off, setup problem, unknown component). Stable, so CI and coding agents can
branch on them.

### JSON

```bash
php craft component-regression/test hero --json
```

```json
{
  "status": "failed",
  "passed": 3,
  "failed": 1,
  "skipped": 0,
  "error": null,
  "components": {
    "hero": { "desktop": "passed", "mobile": "failed" }
  },
  "failures": [
    {
      "component": "hero",
      "url": "https://site.test/about",
      "title": "About",
      "viewport": "mobile",
      "reasons": ["Block #1823 is 318px wider than the viewport"],
      "screenshot": "/…/storage/component-regression/latest/screenshots/hero/p2-mobile-1823.png",
      "trace": "/…/storage/component-regression/latest/traces/p2-mobile.zip"
    }
  ],
  "outputDir": "/…/storage/component-regression/latest",
  "manifest": "/…/latest/manifest.json",
  "results": "/…/latest/results.json"
}
```

Open a trace with `npx playwright show-trace <file>`.

## Environments and modes

The plugin installs on **every** environment, production included — project
config stays identical, and the Twig helpers never break a template. What it
does depends on the mode:

| Mode | Discovery | Page checks | Markers in HTML |
|---|---|---|---|
| `off` | – | – | – |
| `readonly` | ✓ | ✓ | – |
| `full` | ✓ | ✓ | ✓ (signed requests only) |

With no setting, the mode follows `allowAdminChanges`: **`full` where admin
changes are allowed** (development), **`off` where they are not** (production,
and usually staging). A plugin cannot reliably tell staging from production, so
an explicit setting always wins:

```bash
# .env on staging / in CI
COMPONENT_REGRESSION_MODE=full
```

## Configuration

Copy `vendor/b10k/craft-component-regression/src/config.php` to
`config/component-regression.php`. Highlights:

| Setting | Default | |
|---|---|---|
| `mode` | `''` (auto) | `off`, `readonly`, `full` |
| `baseUrl` | `''` | Replace scheme/host of discovered URLs (CI, Docker) |
| `fields` | `[]` (all) | Matrix field handles to scan |
| `samplesPerVariant` | `1` | Pages per variant |
| `maxPagesPerComponent` | `10` | Cap per component |
| `viewports` | desktop 1440×900, mobile 390×844 | Add `isMobile` to override the touch/mobile heuristic |
| `ignoreErrors` | `[]` | Substrings of JS errors to ignore |
| `blockRequests` | GTM, GA, Facebook, Hotjar | Blocked in the browser |
| `outputPath` | `@storage/component-regression` | Artifacts go to `…/latest/` |
| `concurrency` | `4` | Pages in parallel |

## CI

```yaml
- run: npm ci && npx playwright install --with-deps chromium
- run: php craft component-regression/test --json > regression.json
  env:
    COMPONENT_REGRESSION_MODE: full
- uses: actions/upload-artifact@v4
  if: failure()
  with:
    name: component-regression
    path: storage/component-regression/latest
```

The results are only as representative as the database the job runs against:
use a recent content snapshot.

## Coding agents

The loop this plugin is built for: change a component, run
`test <component> --json`, read the failure, look at the screenshot and the
trace, fix, run again. See [AGENTS.md](AGENTS.md) for the recipe to hand your
agent.

## Limitations (v0.1)

- **Matrix only.** Neo, Super Table and custom page builders are not discovered
  yet.
- **Chromium only.**
- **No visual diffing.** Checks catch broken layouts, not a changed shade of
  blue. Baseline screenshots are next on the list.
- **Static caches.** Blitz and similar serve HTML before Craft runs, so marker
  requests may get the cached copy without markers. Exclude the test run (or
  disable the static cache) in environments where you test.
- **`{% cache %}` tags** can serve markup cached without markers.
- **Your data decides.** A component with no content on a live page cannot be
  tested; `discover` lists those.

## Development

```bash
composer install && composer check     # PHPUnit + PHPStan
npm install --no-save playwright && npx playwright install chromium
npm test                                # runner unit tests + real-browser tests
```

The core — mode rules, tokens, sampling, the manifest, the report — is
Craft-free and unit-tested. The runner's checks are pure functions, tested
without a browser; a second suite drives real Chromium against a fixture site.

## License

MIT
