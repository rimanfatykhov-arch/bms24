# BMS24 UI harness

Fast pre-push check of Communication Center markup and CSS (owner decision #160, task B). It renders the **real**
`local/kbacompany/templates/bms24_*` templates with the **real** plugin stylesheets on synthetic data, then measures
what the browser draws at 320/375/768/1440. One run takes about a minute instead of the 40–60 minutes of a full BROWSER
CI pass.

## Usage

```sh
python -m pip install playwright                                      # once per Python environment
python tools/ui_harness/build.py --measure --out ../outputs/ui-harness
```

- `--repo PATH` checks another checkout, such as a PR worktree. `--shell owned|legacy|both` selects the shell;
  both run by default. Use an empty or previously generated output folder.
- The first build downloads and verifies [Bootstrap 4.6.2](https://getbootstrap.com/docs/4.6/getting-started/introduction/).
  Repeat builds use the copy saved in the output directory. Where the CDN is unavailable, pass the official CSS file
  with `--bootstrap PATH`. `--offline` renders pages without Bootstrap for template checks only.
- Playwright uses bundled Chromium or an installed Edge or Chrome. Pass `--channel msedge` or `--channel chrome` to
  select one. If none is installed, run `python -m playwright install chromium`.
- Open `index.html` for previews. The run writes `measurements.json` and screenshots in `shots/` at all four widths.
  Exit code `0` means all measured cases passed, `1` means a build error or failed rule, and `2` means a configuration
  or selection error. The report lists the measurements and violations for each case.
- To recheck an already built preview at selected widths, use
  `python tools/ui_harness/measure.py --out ../outputs/ui-harness --only messages --widths 320,375`.

## What is real and what is not

Taken from the source, so the harness follows the code:
- the templates, rendered strictly: an unknown partial or an unclosed section is an error;
- the stylesheets and their order: theme `styles.css`, then the `$PAGE->requires->css()` calls of
  `communication_center.php`, then (owned shell) those of `bms24_shell_service.php`;
- the owned body class, the page wrapper classes, the workspace root id;
- the plugin language strings for `{{#str}}`.

Not reproduced:
- Moodle and the Boost theme (only Bootstrap 4.6);
- the BMS app-shell menu;
- server data, permissions and company isolation;
- the JS controller. The one exception is its initial state: the in-conversation search starts collapsed.

Scenarios are in `fixtures.py`: three sections, the list without an open conversation, long titles, and empty states.
All data is invented.

## Rules

`measure.py` applies the rules of the BROWSER check `communication_ux2_primary_action_geometry`:
- «Новый разговор» is a full, readable button inside the card header, keeps its POST/sesskey form and does not cover
  the section tabs;
- no visible control label is clipped;
- no horizontal overflow;
- the list search is wide.

`probe.js` also reports the elements wider than the viewport and tap targets smaller than 24 px.

## Limits

A harness PASS is a fast signal before pushing. It is **not** CI, not Moodle, and not acceptance. Required CI, FULL
and release gates still decide. Write NOT RUN for anything the harness does not cover.

Self-test (no browser): `python -m unittest discover -s tools/tests -p test_ui_harness.py`. It runs in
`BMS24 Dev AI checks`.
