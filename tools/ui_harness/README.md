# BMS24 UI harness

Fast pre-push check of Communication Center markup and CSS (owner decision #160, task B). It renders the **real**
`local/kbacompany/templates/bms24_*` templates with the **real** plugin stylesheets on synthetic data, then measures
what the browser draws at 320/375/768/1440. One run takes about a minute instead of the 40–60 minutes of a full BROWSER
CI pass.

## Usage

```
python tools/ui_harness/build.py --out ../outputs/ui-harness           # pages + CSS copies + manifest.json
python tools/ui_harness/measure.py --out ../outputs/ui-harness         # rules, measurements.json, shots/*.png
```

- `build.py --repo PATH` renders another checkout, for example a PR worktree. `--shell owned|legacy|both` (default:
  both). `--offline` skips Bootstrap from the CDN. Use it only to check that pages build: without Bootstrap the
  form controls have browser default widths, and the list-search rule reports false violations (28 of 56 on `main`
  `dcc90f4`, 0 of 56 with Bootstrap).
- `measure.py` needs `pip install playwright`. If `python -m playwright install chromium` cannot download (the
  Playwright CDN is not reachable from every location), it falls back to installed Edge/Chrome, or you can force one
  with `--channel msedge|chrome`. Exit code: 0 = all rules hold, 1 = violations (listed per page and width),
  2 = Playwright missing.
- Open `index.html` in the output folder to look at the pages by hand. Screenshots of every page at 768 and 1440 are
  in `shots/`.

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
