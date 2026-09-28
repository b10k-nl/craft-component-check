# Changelog

All notable changes to Component Check are documented here. This project
adheres to [Semantic Versioning](https://semver.org).

## Unreleased

First working draft.

### Added

- `component-check/discover`: every Matrix block on every live page,
  grouped by component, with the representative pages that would be tested and
  the block types that have no content anywhere.
- Sampling by variant (section, page type, site, filled-in fields, option
  values): as few pages as possible, every variant covered.
- `component-check/test`: Playwright runs on desktop (1440×900) and mobile
  (390×844). Page checks: HTTP status, uncaught JS errors, failed scripts and
  stylesheets, sideways scrolling. Component checks (with markers): rendered,
  has a size, fits the viewport, images load.
- Screenshots of the failing component and a Playwright trace for every failure.
- `--json` output and stable exit codes (0 passed, 1 regressions, 2 could not
  run) for CI and coding agents.
- `component-check/doctor`: checks mode, Node, Playwright, Chromium,
  output directory, site reachability and marker usage.
- Twig markers — `craft.componentCheck.start(block)` / `end()` and
  `craft.componentCheck.attributes(block)` — rendered only for signed test requests.
- `component-check/snapshot` and before/after comparison in `test`: per block
  and viewport, geometry and computed style are recorded and compared, and
  changes are reported in words (“h1: width 334→1200px, now cut off by
  235px”), with before/after screenshots. Blocks whose content was edited
  after the snapshot are not compared. Snapshots are local
  (`storage/component-check/snapshot`).
- Layout heuristics — sideways scrolling, overflow, text cut off by
  `overflow: hidden` — are warnings, not failures: they cannot know what the
  design intends. A change against a snapshot fails instead.
- Modes `off` / `readonly` / `full`, following `allowAdminChanges` unless set
  explicitly (`COMPONENT_CHECK_MODE`).
