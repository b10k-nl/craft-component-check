# Changelog

All notable changes to Component Regression are documented here. This project
adheres to [Semantic Versioning](https://semver.org).

## Unreleased

First working draft.

### Added

- `component-regression/discover`: every Matrix block on every live page,
  grouped by component, with the representative pages that would be tested and
  the block types that have no content anywhere.
- Sampling by variant (section, page type, site, filled-in fields, option
  values): as few pages as possible, every variant covered.
- `component-regression/test`: Playwright runs on desktop (1440×900) and mobile
  (390×844). Page checks: HTTP status, uncaught JS errors, failed scripts and
  stylesheets, sideways scrolling. Component checks (with markers): rendered,
  has a size, fits the viewport, images load.
- Screenshots of the failing component and a Playwright trace for every failure.
- `--json` output and stable exit codes (0 passed, 1 regressions, 2 could not
  run) for CI and coding agents.
- `component-regression/doctor`: checks mode, Node, Playwright, Chromium,
  output directory, site reachability and marker usage.
- Twig markers — `craft.regression.start(block)` / `end()` and
  `craft.regression.attributes(block)` — rendered only for signed test requests.
- Modes `off` / `readonly` / `full`, following `allowAdminChanges` unless set
  explicitly (`COMPONENT_REGRESSION_MODE`).
