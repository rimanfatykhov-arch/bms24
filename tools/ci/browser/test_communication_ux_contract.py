import copy
import unittest
from contract import UX_NAV_STEPS, communication_ux_checks, evaluate

CENTER = 'http://127.0.0.1:8080/local/kbacompany/communication_center.php?'


def navigation(company='A'):
    """What a real click-through shows on the UX-1 Center (sections, then filters of both list sections)."""
    steps, section = [], 'messages'
    for kind, key in UX_NAV_STEPS:
        if kind == 'section':
            section = key
            url = CENTER + 'uuid=%s&tab=%s' % (company, key)
        else:
            url = CENTER + 'uuid=%s&tab=%s&filter=%s' % (company, section, key)
        lists = section != 'notifications'
        steps.append({'kind': kind, 'key': key, 'http': 200, 'url': url,
                      'active_section': CENTER + 'uuid=%s&tab=%s' % (company, section), 'active_sections': 1,
                      'active_filter': (CENTER + 'uuid=%s&tab=%s&filter=%s' % (company, section, key if kind == 'filter' else 'all'))
                      if lists else None, 'active_filters': 1 if lists else 0,
                      'notifications': 0 if lists else 1, 'layout': 1 if lists else 0, 'filters': 1 if lists else 0,
                      'overflow': False})
    return steps


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
            no_conversation_area=True, notifications_overflow=False, browser_events=[], server_errors=[],
            navigation=navigation())
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

    def test_clicked_navigation_must_land_exactly(self):
        good = self.observation()
        def step(o, i):
            return o['cases'][3]['navigation'][i]
        mutations = [
            lambda o: o['cases'][0].pop('navigation'),
            lambda o: o['cases'][0].update(navigation=[]),
            lambda o: o['cases'][9]['navigation'].pop(),
            lambda o: o['cases'][0]['navigation'].reverse(),
            lambda o: step(o, 0).update(http=500),
            lambda o: step(o, 0).update(http=None),
            lambda o: step(o, 0).update(url=CENTER.replace('communication_center', 'wrong-route') + 'uuid=A&tab=discussions'),
            lambda o: step(o, 0).update(url=CENTER + 'uuid=B&tab=discussions'),
            lambda o: step(o, 0).update(url=CENTER + 'uuid=A&tab=discussions&tab=discussions'),
            lambda o: step(o, 0).update(url=CENTER + 'uuid=A&tab=discussions&extra=1'),
            lambda o: step(o, 0).update(url='https://evil.invalid/local/kbacompany/communication_center.php?uuid=A&tab=discussions'),
            lambda o: step(o, 1).update(url=CENTER + 'uuid=A&tab=messages'),
            lambda o: step(o, 3).update(url=CENTER + 'uuid=A&tab=messages&filter=all'),
            lambda o: step(o, 4).update(active_filter=CENTER + 'uuid=A&tab=messages&filter=all'),
            lambda o: step(o, 4).update(active_filters=2),
            lambda o: step(o, 7).update(active_section=CENTER + 'uuid=A&tab=messages'),
            lambda o: step(o, 7).update(url=CENTER + 'uuid=A&tab=messages&filter=unread'),
            lambda o: step(o, 1).update(notifications=0),
            lambda o: step(o, 1).update(layout=1),
            lambda o: step(o, 2).update(layout=0),
            lambda o: step(o, 2).update(notifications=1),
            lambda o: step(o, 5).update(overflow=True),
            lambda o: step(o, 5).update(overflow=None),
            lambda o: step(o, 6).update(kind='filter'),
        ]
        for mutate in mutations:
            o = copy.deepcopy(good); mutate(o)
            self.assertTrue(any(x['status'] == 'FAIL' for x in evaluate(communication_ux_checks(o, 0)).values()), mutate)
        self.assertTrue(any(x['status']=='FAIL' for x in evaluate(communication_ux_checks(good,None)).values()))


if __name__=='__main__':unittest.main()
