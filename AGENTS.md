# Component Check — instructions for coding agents

This file is for a coding agent working in a Craft CMS project that has the
Component Check plugin installed. Use it whenever you change a Twig
template, CSS or JavaScript that affects page-builder (Matrix) blocks.

## The loop

1. **Find out what you are touching.** Component handles are Matrix entry type
   handles.

   ```bash
   php craft component-check/discover --json
   ```

   `components` lists every handle with its usage; `pages` lists the pages that
   will be tested and which blocks are on each. `unused` lists block types with
   no content on any live page — you cannot test those; say so.

2. **Before you change anything, snapshot the components you will touch**
   (comma-separated):

   ```bash
   php craft component-check/snapshot hero,cards --json
   ```

3. **Make your change, then test the same components:**

   ```bash
   php craft component-check/test hero,cards --json
   ```

   Exit code `0` = passed, `1` = regressions, `2` = could not run.

   With Component Map installed you do not have to name them:
   `php craft component-check/test --changed --json` tests what your
   uncommitted changes affect (`selection.reason` says why).

4. **On exit code 2**, read `error` and run
   `php craft component-check/doctor --json`. Report setup problems to the
   human instead of working around them. Never change the plugin's `mode` to
   make a run succeed.

5. **On exit code 1**, for each entry in `failures`:
   - read `reasons`. “Changed since snapshot: …” lists what your change did to
     the block (sizes, positions, text, colours, cut-off text). Other reasons
     are errors: missing block ID, broken image URL, JS error text, HTTP status;
   - compare `before` and `screenshot` (the block before and after, cropped);
   - if needed, open `trace` with `npx playwright show-trace <file>`.

   If the change is **intended**, say so to the human — do not hide it. If it
   is **not**, fix the template or CSS and rerun step 3. Do not retake the
   snapshot to make a failure go away.

6. **Read `warnings` too.** They are heuristics (sideways scrolling, overflow,
   cut-off text) and do not fail the run, but mention new ones to the human.

7. **Before you finish**, run the whole project once without arguments:

   ```bash
   php craft component-check/test --json
   ```

   A shared partial can break a component you did not touch.

## Rules

- Do not use `component-check/watch`: it is interactive and never exits. Use
  `snapshot` + `test --json`.

- Do not edit content in the control panel to make a test pass. The tests run
  against real content on purpose.
- Do not add `ignoreErrors` or `blockRequests` entries without telling the
  human why.
- If a component is not rendered (`component-present` failed), check the block
  loop has `{{ craft.componentCheck.start(block) }}` / `{{ craft.componentCheck.end() }}`
  before assuming the component is broken.
- `skipped` cases with "Markers off" mean the environment runs page-level checks
  only; that is not a failure.
