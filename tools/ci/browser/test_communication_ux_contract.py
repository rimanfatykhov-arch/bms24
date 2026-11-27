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


def geometry(width):
    """What the browser measures on a correctly styled Center: full header button, no clipped labels, wide search."""
    action = {'count': 1, 'label': '+ Новый разговор', 'width': 160, 'height': 38, 'scroll_width': 158, 'client_width': 158,
              'in_header': True, 'overlaps_tabs': False, 'in_form': True}
    side = 340 if width >= 900 else width - 34
    search = {'width': side - 29, 'side_width': side, 'input_width': side - 75}
    out = {}
    for section in ['messages', 'discussions', 'notifications']:
        out[section] = {'root': True, 'css': True, 'overflow': False, 'clipped': [], 'action': dict(action),
                        'search': None if section == 'notifications' else dict(search)}
    return out


def frame(thread):
    """What the browser measures on a framed Center at 900px height."""
    return {'framed': True, 'page_excess': 0, 'layout_bottom': 874, 'view': 900,
            'compose_bottom': 862 if thread else None, 'list_overflow': 'auto', 'feed_overflow': 'auto' if thread else None}


def still(frames, bottom):
    """What the browser samples on an open conversation that holds still for `frames` frames."""
    return {'frames': frames, 'messages': 42, 'view': 900, 'layout_span': 0, 'feed_span': 0, 'compose_span': 0,
            'send_span': 0, 'scroll_span': 0, 'width_span': 0, 'changes': 0, 'first': [640, 470, 690, 760, 0, 1425],
            'last_sample': [640, 470, 690, 760, 0, 1425], 'feed_top': 220, 'feed_bottom': 690,
            'last_top': 600 if bottom else 230, 'last_bottom': 680 if bottom else 300, 'at_bottom': bottom,
            'send_bottom': 798, 'feed_pad': 14}


def stability():
    return [{'shell': m, 'width': w, 'kind': k, 'http': 200, 'bottom': still(120, True), 'reading': still(60, False),
             'browser_events': [], 'server_errors': []}
            for m in ['owned', 'legacy'] for w in [1440, 375] for k in ['short', 'long']]


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
            navigation=navigation(), geometry=geometry(w), frame_list=frame(False), frame_thread=frame(True), frame_shift=frame(True))
            for s in ['owned','legacy'] for w in [320,375,390,768,1440]], 'stability': stability()}

    def test_primary_action_geometry_rejects_clipped_or_missing_button(self):
        good = self.observation()
        name = 'communication_ux2_primary_action_geometry'
        self.assertEqual(evaluate(communication_ux_checks(good, 0))[name]['status'], 'PASS')
        def g(o, case=9, section='messages'):
            return o['cases'][case]['geometry'][section]
        mutations = [
            # The defect seen on 79b5974: 34px box, 93px label, drawn over the section tabs.
            lambda o: g(o)['action'].update(width=34, height=34, scroll_width=93, client_width=32, overlaps_tabs=True),
            lambda o: g(o)['action'].update(width=32),
            lambda o: g(o)['action'].update(scroll_width=170, client_width=150),
            lambda o: g(o)['action'].update(label='+'),
            lambda o: g(o)['action'].update(label=''),
            lambda o: g(o)['action'].update(overlaps_tabs=True),
            lambda o: g(o)['action'].update(in_header=False),
            lambda o: g(o)['action'].update(in_form=False),
            lambda o: g(o)['action'].update(count=2),
            lambda o: g(o)['action'].update(width=True),
            lambda o: g(o)['action'].pop('height'),
            lambda o: g(o, 3, 'notifications').update(action=None),
            lambda o: g(o, 0, 'discussions').update(clipped=['Разгов…']),
            lambda o: g(o, 4, 'notifications').update(clipped=['Отметить прочитанным']),
            lambda o: g(o).update(css=False),
            lambda o: g(o).update(root=False),
            lambda o: g(o).update(overflow=True),
            lambda o: g(o).update(overflow=None),
            lambda o: g(o).update(search=None),
            lambda o: g(o).update(search={'width': 120, 'side_width': 340, 'input_width': 90}),
            lambda o: g(o).update(search={'width': 320, 'side_width': 340, 'input_width': 120}),
            lambda o: g(o, 9, 'notifications').update(search={'width': 300, 'side_width': 340, 'input_width': 250}),
            lambda o: o['cases'][2]['geometry'].pop('notifications'),
            lambda o: o['cases'][2].pop('geometry'),
        ]
        for mutate in mutations:
            o = copy.deepcopy(good); mutate(o)
            self.assertEqual(evaluate(communication_ux_checks(o, 0))[name]['status'], 'FAIL', mutate)
        self.assertEqual(evaluate(communication_ux_checks(good, 1))[name]['status'], 'FAIL')
        # Narrow screens may show the open conversation instead of the list: no search there is not a failure.
        o = copy.deepcopy(good); o['cases'][0]['geometry']['messages']['search'] = None
        self.assertEqual(evaluate(communication_ux_checks(o, 0))[name]['status'], 'PASS')

    def test_fixed_frame_rejects_page_scroll_and_missing_measurements(self):
        good = self.observation()
        name = 'communication_ux3_fixed_frame'
        self.assertEqual(evaluate(communication_ux_checks(good, 0))[name]['status'], 'PASS')
        def f(o, case=9, state='frame_thread'):
            return o['cases'][case][state]
        mutations = [
            lambda o: f(o).update(framed=False),
            lambda o: f(o, 5, 'frame_list').update(framed=None),
            lambda o: f(o).update(page_excess=2),
            lambda o: f(o, 0, 'frame_list').update(page_excess=274),
            lambda o: f(o).update(page_excess=None),
            lambda o: f(o).update(page_excess=True),
            lambda o: f(o).update(layout_bottom=950),
            lambda o: f(o).update(layout_bottom=0),
            lambda o: f(o).update(view=480),
            lambda o: f(o).update(compose_bottom=None),
            lambda o: f(o).update(compose_bottom=1032),
            lambda o: f(o).update(list_overflow='visible'),
            lambda o: f(o, 3, 'frame_list').update(list_overflow=None),
            lambda o: f(o).update(feed_overflow='hidden'),
            lambda o: f(o).update(feed_overflow=None),
            lambda o: f(o, 4, 'frame_list').update(feed_overflow='visible'),
            lambda o: o['cases'][2].pop('frame_list'),
            lambda o: o['cases'][7].update(frame_thread=None),
            # Percona owned/320: the frame released while the page above was taller and never came back.
            lambda o: f(o, 0, 'frame_shift').update(framed=False, page_excess=265, layout_bottom=1130, compose_bottom=1130),
            lambda o: f(o, 5, 'frame_shift').update(framed=False),
            lambda o: f(o, 1, 'frame_shift').update(compose_bottom=None),
            lambda o: f(o, 6, 'frame_shift').update(feed_overflow=None),
            lambda o: o['cases'][3].pop('frame_shift'),
        ]
        for mutate in mutations:
            o = copy.deepcopy(good); mutate(o)
            self.assertEqual(evaluate(communication_ux_checks(o, 0))[name]['status'], 'FAIL', mutate)
        self.assertEqual(evaluate(communication_ux_checks(good, 1))[name]['status'], 'FAIL')

    def test_stable_thread_rejects_geometry_that_moves_with_no_input(self):
        good = self.observation()
        name = 'communication_ux4_stable_thread'
        self.assertEqual(evaluate(communication_ux_checks(good, 0))[name]['status'], 'PASS')
        def s(o, row=0, state='bottom'):
            return o['stability'][row][state]
        mutations = [
            # The owner's recording: the history shrinks and grows, the composer and Send move with it.
            lambda o: s(o, 1).update(feed_span=34, compose_span=34, send_span=34, layout_span=0, changes=118),
            lambda o: s(o).update(layout_span=17, changes=60),
            lambda o: s(o, 3).update(width_span=17, changes=59),
            lambda o: s(o, 5).update(send_span=1),
            lambda o: s(o, 6).update(compose_span=2),
            lambda o: s(o, 2, 'reading').update(scroll_span=40),
            lambda o: s(o, 4, 'reading').update(changes=1),
            lambda o: s(o, 7).update(frames=12),
            lambda o: s(o).update(feed_span=None),
            lambda o: s(o).update(changes=True),
            lambda o: s(o, 1).update(last_bottom=712),
            # A message that fits the history must be whole: top under the edge is a cut.
            lambda o: s(o, 1).update(last_top=180, last_bottom=400),
            lambda o: s(o, 1).update(last_bottom=210),
            lambda o: s(o, 1).update(feed_pad=None),
            lambda o: s(o, 1).update(at_bottom=False),
            lambda o: s(o, 1).update(messages=3),
            lambda o: s(o).update(send_bottom=930),
            lambda o: o['stability'][2].update(server_errors=[500]),
            lambda o: o['stability'][2].update(http=500),
            lambda o: o['stability'][3].update(browser_events=None),
            lambda o: o['stability'][4].pop('reading'),
            lambda o: o['stability'].pop(),
            lambda o: o['stability'][7].update(kind='short'),
            lambda o: o.pop('stability'),
        ]
        for mutate in mutations:
            o = copy.deepcopy(good); mutate(o)
            self.assertEqual(evaluate(communication_ux_checks(o, 0))[name]['status'], 'FAIL', mutate)
        self.assertEqual(evaluate(communication_ux_checks(good, 1))[name]['status'], 'FAIL')

    def test_stable_thread_accepts_a_newest_message_taller_than_the_history(self):
        # Percona legacy/1440/long (run 45967889030): a 253 px message in a 265 px history with 14 px bottom padding
        # can only show its end; its bottom is on screen at the newest position.
        o = self.observation()
        o['stability'][5]['bottom'].update(feed_top=374, feed_bottom=639, last_top=372, last_bottom=625, feed_pad=14)
        self.assertEqual(evaluate(communication_ux_checks(o, 0))['communication_ux4_stable_thread']['status'], 'PASS')

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
