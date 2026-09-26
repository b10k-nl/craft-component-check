# Component Regression — instructions for coding agents

This file is for a coding agent working in a Craft CMS project that has the
Component Regression plugin installed. Use it whenever you change a Twig
template, CSS or JavaScript that affects page-builder (Matrix) blocks.

## The loop

1. **Find out what you are touching.** Component handles are Matrix entry type
   handles.

   ```bash
   php craft component-regression/discover --json
   ```

   `components` lists every handle with its usage; `pages` lists the pages that
   will be tested and which blocks are on each. `unused` lists block types with
   no content on any live page — you cannot test those; say so.

2. **Test the components you changed** (comma-separated):

   ```bash
   php craft component-regression/test hero,cards --json
   ```

   Exit code `0` = passed, `1` = regressions, `2` = could not run.

3. **On exit code 2**, read `error` and run
   `php craft component-regression/doctor --json`. Report setup problems to the
   human instead of working around them. Never change the plugin's `mode` to
   make a run succeed.

4. **On exit code 1**, for each entry in `failures`:
   - read `reasons` — they say what failed (overflow in px, missing block ID,
     broken image URL, JS error text, HTTP status);
   - look at `screenshot` (the failing component, cropped);
   - if needed, open `trace` with `npx playwright show-trace <file>`.

   Fix the cause in the template or CSS, then rerun step 2 with the same
   components.

5. **Before you finish**, run the whole project once without arguments:

   ```bash
   php craft component-regression/test --json
   ```

   A shared partial can break a component you did not touch.

## Rules

- Do not edit content in the control panel to make a test pass. The tests run
  against real content on purpose.
- Do not add `ignoreErrors` or `blockRequests` entries without telling the
  human why.
- If a component is not rendered (`component-present` failed), check the block
  loop has `{{ craft.regression.start(block) }}` / `{{ craft.regression.end() }}`
  before assuming the component is broken.
- `skipped` cases with "Markers off" mean the environment runs page-level checks
  only; that is not a failure.
