"""ADR-0009 R0 static contract: a clear daily reports entry without schema, version or rights changes."""
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / 'local/kbacompany'


def text(name):
    return (PLUGIN / name).read_text(encoding='utf-8')


class DailyReportsR0Entry(unittest.TestCase):
    def test_sidebar_route_directly_follows_task_center(self):
        source = text('classes/local/bms_ui_service.php')
        self.assertIn("'order' => ['workspace', 'calendar', 'tasks', 'dailyreports', 'recurring', 'battleplan', "
                      "'communications'],", source)
        quick = text('classes/local/bms_workspace_ux_service.php')
        self.assertLess(quick.index("'tasks' => ['taskcenter_title'"), quick.index("'dailyreports' => ['dailyreports_nav'"))
        self.assertLess(quick.index("'dailyreports' => ['dailyreports_nav'"), quick.index("'recurring' => ['recurringtask_title'"))
        recurring = (PLUGIN / 'tests/bms_recurring_navigation_test.php').read_text(encoding='utf-8')
        self.assertIn("$this->assertSame('dailyreports', $keys[$position - 1]);", recurring)
        self.assertIn("$this->assertSame('battleplan', $keys[$position + 1]);", recurring)
        route = re.search(r"'dailyreports' => \[(.*?)\],\n", source, re.S).group(1)
        self.assertIn("'group' => 'workspace', 'sidebar' => true", route)
        self.assertIn("get_string('dailyreports_nav', 'local_kbacompany')", route)
        self.assertIn("$url('daily_report.php')", route)
        self.assertIn("'dailyreports' => 'report'", source)
        self.assertEqual(1, source.count("'mobile_label' =>"), 'only the daily reports route has a short name')

    def test_phone_menu_shows_short_label_and_keeps_full_name_for_screen_readers(self):
        source = text('classes/local/bms_ui_service.php')
        self.assertIn("'mobile_label' => get_string('dailyreports_nav_short', 'local_kbacompany'),", source)
        self.assertIn("'mobilelabel' => $route['mobile_label'] ?? '',", source)
        template = text('templates/bms24_nav_groups.mustache')
        self.assertIn('<span class="bms24-navlabel bms24-navlabel-full">{{label}}</span>', template)
        # The short label is not DOM text (it would leak into the desktop accessible name); CSS draws it on phones only.
        self.assertIn('<span class="bms24-navlabel bms24-navlabel-short" aria-hidden="true" data-label="{{mobilelabel}}"></span>',
                      template)
        self.assertIn('{{^mobilelabel}}<span class="bms24-navlabel">{{label}}</span>{{/mobilelabel}}', template)
        css = text('bms_ui/owned_shell.css')
        self.assertIn('.bms24-app .bms24-navlabel-short {display:none;}', css)
        mobile = re.search(r'@media\(max-width:860px\) \{\.bms24-app \.bms24-navlabel-short \{display:inline;\}(.*?)\}$', css, re.M).group(1)
        self.assertIn('.bms24-app .bms24-navlabel-short::before {content:attr(data-label);}', mobile)
        self.assertNotIn('content:attr(data-label)', css.split('@media(max-width:860px)')[0])
        self.assertIn('.bms24-navlabel-full {position:absolute;width:1px;height:1px;', mobile)
        self.assertIn('clip:rect(0,0,0,0)', mobile)
        self.assertNotIn('display:none', mobile)

    def test_page_has_own_active_route_and_my_report_tab(self):
        page = text('daily_report.php')
        self.assertIn("bms_page_shell_service::begin($uuid, (int)$USER->id, 'dailyreports',", page)
        self.assertNotIn("(int)$USER->id, 'tasks',", page)
        self.assertIn("get_string('dailyreports_tab_my', 'local_kbacompany'), 'active' => true", page)
        self.assertIn("require_capability('local/kbacompany:viewowncompany', $context);", page)
        self.assertIn("daily_report_service::context($uuid, (int)$USER->id, $day,", page)
        self.assertNotIn('optional_param(\'userid\'', page)

    def test_task_center_button_uses_report_first_name(self):
        tasks = text('tasks.php')
        self.assertIn("$dailyreportlabel = get_string('dailyreports_button', 'local_kbacompany');", tasks)
        self.assertIn("html_writer::span(s($dailyreportlabel), 'd-none d-sm-inline')", tasks)
        self.assertIn("html_writer::span(s(get_string('dailyreports_button_short', 'local_kbacompany')), 'd-sm-none', "
                      "['aria-hidden'=>'true'])", tasks)
        self.assertIn("'aria-label'=>$dailyreportlabel", tasks)
        self.assertNotIn("get_string('dailyreport_title', 'local_kbacompany')", tasks)

    def test_language_names_match_owner_decision(self):
        ru = text('lang/ru/local_kbacompany.php')
        en = text('lang/en/local_kbacompany.php')
        for key, ruvalue, envalue in [('dailyreports_nav', 'Отчёты за день', 'Daily reports'),
                                      ('dailyreports_tab_my', 'Мой отчёт', 'My report'),
                                      ('dailyreports_nav_short', 'Отчёты', 'Reports'),
                                      ('dailyreports_button_short', 'Отчёт', 'Report'),
                                      ('dailyreports_button', 'Отчёт за день', 'Daily report')]:
            self.assertIn(f"$string['{key}'] = '{ruvalue}';", ru)
            self.assertIn(f"$string['{key}'] = '{envalue}';", en)

    def test_standard_benchmark_runs_r0_moodle_tests(self):
        driver = (ROOT / 'tools/tests/moodle_acceptance.php').read_text(encoding='utf-8')
        standard = re.search(r"// Fixed benchmark allowlist[^\n]*\n\s*\$focused = \[(.*?)\];", driver, re.S).group(1)
        for test in ['bms_daily_reports_navigation_test', 'bms_recurring_navigation_test',
                     'pr48_tenant_isolation_test', 'membership_revocation_test']:
            self.assertIn(f"'{test}'", standard)
            self.assertTrue((PLUGIN / 'tests' / f'{test}.php').is_file(), test)

    def test_no_schema_version_or_capability_change_against_base(self):
        base = '881aac2'
        if subprocess.run(['git', 'cat-file', '-e', base], cwd=ROOT, capture_output=True).returncode:
            self.skipTest('base commit not available in this checkout')
        changed = subprocess.run(['git', 'diff', '--name-only', base, '--', 'local/kbacompany/db',
                                  'local/kbacompany/version.php'], cwd=ROOT, capture_output=True, text=True,
                                 check=True).stdout.split()
        self.assertEqual([], changed)


if __name__ == '__main__':
    unittest.main()
