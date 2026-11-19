import copy
import unittest
from contract import communication_ux_checks, evaluate


class UXContractTest(unittest.TestCase):
    def observation(self):
        return {'status': 'PASS', 'companyuuid': 'A', 'cases': [dict(shell=s, width=w, http=200, sections=3,
            default_section='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=messages',
            default_filter='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=messages&filter=all',
            no_meetings=True, no_bulk=True, overflow=False,
            colors=[{'foreground':'rgb(255, 255, 255)', 'background':'rgb(11, 43, 65)'},
                    {'foreground':'rgb(37, 72, 94)', 'background':'rgb(255, 255, 255)'},
                    {'foreground':'rgb(37, 72, 94)', 'background':'rgb(255, 255, 255)'}],
            thread_available=True, panel_open=True, panel_closed=True, focus_restored=True,
            thread_overflow=False, notifications_http=200, notification_screen=True,
            no_conversation_area=True, notifications_overflow=False, browser_events=[], server_errors=[])
            for s in ['owned','legacy'] for w in [320,375,390,768,1440]]}

    def test_complete_evidence_passes_and_broken_evidence_fails(self):
        good=self.observation()
        self.assertTrue(all(x['status']=='PASS' for x in evaluate(communication_ux_checks(good,0)).values()))
        mutations=[lambda o:o.update(cases=[]), lambda o:o['cases'].pop(),
            lambda o:o['cases'][0].update(http=500), lambda o:o['cases'][0].update(http=302),
            lambda o:o['cases'][0].update(overflow=True), lambda o:o['cases'][0].update(focus_restored=False),
            lambda o:o['cases'][0].update(no_conversation_area=False),
            lambda o:o['cases'][0].update(default_filter='http://127.0.0.1:8080/?filter=unread'),
            lambda o:o['cases'][0].update(default_section='http://127.0.0.1:8080/wrong-route.php?uuid=A&tab=messages'),
            lambda o:o['cases'][0].update(default_filter='http://127.0.0.1:8080/wrong-route.php?uuid=A&tab=messages&filter=all'),
            lambda o:o['cases'][0].update(default_section='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=B&tab=messages'),
            lambda o:o['cases'][0].update(default_filter='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=messages&filter=all&filter=all'),
            lambda o:o['cases'][0].update(default_section='http://127.0.0.1:8080/local/kbacompany/communication_center.php?uuid=A&tab=messages&extra=1'),
            lambda o:o['cases'][0].update(colors=[]),
            lambda o:o['cases'][0]['colors'][0].update(foreground='rgb(255, 255, 255)',background='rgb(228, 242, 251)'),
            lambda o:o['cases'][0].update(server_errors=[500]),
            lambda o:o['cases'][0].update(browser_events=[{'source':'pageerror','name':'Error','message':'broken'}])]
        for mutate in mutations:
            o=copy.deepcopy(good);mutate(o)
            self.assertTrue(any(x['status']=='FAIL' for x in evaluate(communication_ux_checks(o,0)).values()))
        self.assertTrue(any(x['status']=='FAIL' for x in evaluate(communication_ux_checks(good,1)).values()))
        self.assertTrue(any(x['status']=='FAIL' for x in evaluate(communication_ux_checks(good,None)).values()))


if __name__=='__main__':unittest.main()
