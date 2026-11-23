"""Self-test of the BMS24 UI harness (#160, task B). No browser needed: renderer, build and rule checks.

The browser part (measure.py + probe.js) needs Playwright and is run by developers; here its rule function is tested
against measured shapes, including the 34x34 "New conversation" defect the harness was built to catch (#145/#152).
"""
import copy
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / 'tools' / 'ui_harness'))
import build  # noqa: E402
import measure  # noqa: E402
from fixtures import SCENARIOS  # noqa: E402
from mustache import Renderer, TemplateError, load_strings, parse  # noqa: E402


class RendererTest(unittest.TestCase):
    def renderer(self, files, strings=None):
        tmp = Path(tempfile.mkdtemp())
        for name, text in files.items():
            (tmp / (name + '.mustache')).write_text(text, encoding='utf-8')
        return Renderer(tmp, strings or {})

    def test_variables_sections_partials(self):
        r = self.renderer({'main': '{{a}}|{{{a}}}|{{#items}}<{{n}}>{{/items}}|{{^none}}empty{{/none}}|'
                                   '{{#obj}}{{x.y}}{{/obj}}|{{> local_kbacompany/part}}{{! comment }}',
                           'part': 'P{{a}}'})
        out = r.render('main', {'a': '<b>', 'items': [{'n': 1}, {'n': 2}], 'none': [], 'obj': {'x': {'y': 'deep'}}})
        self.assertEqual(out, '&lt;b&gt;|<b>|<1><2>|empty|deep|P&lt;b&gt;')

    def test_strict_errors(self):
        with self.assertRaises(TemplateError):
            parse('{{#a}}x', 't')
        with self.assertRaises(TemplateError):
            parse('{{#a}}x{{/b}}', 't')
        with self.assertRaises(TemplateError):
            self.renderer({'main': '{{> local_kbacompany/nope}}'}).render('main', {})

    def test_helpers(self):
        r = self.renderer({'main': '{{#str}}k, local_kbacompany{{/str}}|{{#str}}close, core{{/str}}|{{#js}}x(){{/js}}|'
                                   '{{#quote}}a"b{{/quote}}'}, {'k': 'Значение'})
        self.assertEqual(r.render('main', {}), 'Значение|close||"a\\"b"')
        self.assertEqual(r.missing_strings, {'core/close'})

    def test_plugin_lang_file_is_readable(self):
        strings = load_strings(REPO / 'local' / 'kbacompany' / 'lang' / 'ru' / 'local_kbacompany.php')
        self.assertGreater(len(strings), 1000)
        self.assertTrue(all(isinstance(v, str) for v in strings.values()))


class BuildTest(unittest.TestCase):
    def test_every_scenario_renders_from_the_real_templates(self):
        out = Path(tempfile.mkdtemp()) / 'harness'
        m = build.build(REPO, out, ('owned', 'legacy'), offline=True)
        self.assertEqual(len(m['pages']), 2 * len(SCENARIOS))
        self.assertIn('styles.css', m['shells']['legacy'])
        self.assertTrue(set(m['shells']['legacy']) < set(m['shells']['owned']), 'owned shell adds its own CSS')
        for p in m['pages']:
            text = (out / p['page']).read_text(encoding='utf-8')
            self.assertNotIn('{{', text, p['page'])
            self.assertIn('summary class="bms24-comm-btn bms24-comm-btn--primary bms-comm2-new-trigger"', text)
            self.assertIn('bms-comm-ux-sections', text)
            self.assertIn('name="sesskey"', text)
            if p['section'] == 'notifications':
                self.assertIn('bms-comm-ux-notifications', text)
                self.assertNotIn('readallnotifications', text)
            else:
                self.assertIn('id="bms_comm2_search"', text)
        for rel in m['css_sha256']:
            self.assertTrue((out / 'assets' / rel).is_file(), rel)
        self.assertTrue({'bms24_card', 'bms24_comm_header', 'bms24_comm_new', 'bms24_comm_list',
                         'bms24_comm_notifications'} <= set(m['templates_used']))

    def test_source_facts_follow_the_php(self):
        facts = build.source_facts(REPO / 'local' / 'kbacompany')
        self.assertEqual(facts['owned_body_class'], 'bms24-owned-page')
        self.assertIn('bms-comm2-shell', facts['page_wrapper_class'])


def good_probe():
    return {'root': True, 'root_id': True, 'width': 1440, 'overflow': False, 'css': True, 'clipped': [], 'wide': [],
            'small_targets': [], 'search': {'width': 311, 'side_width': 340, 'input_width': 264},
            'action': {'count': 1, 'label': '+ Новый разговор', 'width': 160, 'height': 38, 'scroll_width': 158,
                       'client_width': 158, 'in_header': True, 'overlaps_tabs': False, 'in_form': True}}


class RulesTest(unittest.TestCase):
    def test_good_page_passes(self):
        self.assertEqual(measure.violations(good_probe()), [])
        m = good_probe(); m['search'] = None
        self.assertEqual(measure.violations(m), [], 'notifications / open conversation have no list search')

    def test_defects_are_reported(self):
        cases = {
            # The production defect (#145): 34x34 trigger, label wider than the box, label clipped.
            'defect34': lambda m: (m['action'].update(width=34, height=34, scroll_width=55, client_width=34),
                                   m.update(clipped=['+ Новый разговор'])),
            'over_tabs': lambda m: m['action'].update(overlaps_tabs=True),
            'outside_header': lambda m: m['action'].update(in_header=False),
            'no_form': lambda m: m['action'].update(in_form=False),
            'two_buttons': lambda m: m['action'].update(count=2),
            'missing': lambda m: m.update(action=None),
            'clipped_tab': lambda m: m.update(clipped=['Разгов…']),
            'overflow': lambda m: m.update(overflow=True, wide=['div.bms24-comm-item']),
            'narrow_search_input': lambda m: m.update(search={'width': 421, 'side_width': 422, 'input_width': 155}),
            'narrow_search_box': lambda m: m.update(search={'width': 150, 'side_width': 340, 'input_width': 120}),
            'no_root': lambda m: m.update(root=False),
        }
        for name, mutate in cases.items():
            m = copy.deepcopy(good_probe()); mutate(m)
            self.assertTrue(measure.violations(m), name)


if __name__ == '__main__':
    unittest.main()
