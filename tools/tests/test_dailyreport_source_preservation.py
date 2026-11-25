"""Exact source preservation remains fail-closed when day-summary strings are appended.

Chain (each block is appended, never inserted): published #110 prefix -> #106/#107 day-summary block (#113) ->
Communication Center cleanup block (#112) -> daily reports R0 block (#116). Every earlier block must stay byte-identical (exact SHA256 of the file up to
the next block marker) and later keys must never override earlier ones.
"""
import hashlib
import importlib.util
import json
import re
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('preservation',ROOT/'tools/check_pr53_pr56_integration.py')
gate=importlib.util.module_from_spec(spec);spec.loader.exec_module(gate)
PUBLISHED={'en':'cf9761113aa0dd206c0c52ed9b3cdb13aebe2be2b51bf91cd5c55cac0fd18ff4',
           'ru':'77808151a0f34400a2ae8868ddae57b703e8dfda4a15b0f8ddd2710eadb76d06'}
DAY_MARKER=b'\n// Issue #106: canonical read-only personal day summary.\n'
COMM_MARKER=b'\n// Communication Center cleanup (2026-11-15): one task discussion, task events, replies, group members.\n'
COMM_PREFIXES=(b'communication_',b'communicationcenter_',b'taskcenter_discussion_reader',b'taskdiscussion_event_')
R0_MARKER=b'\n// Issue #106 R0: daily reports entry point (navigation and names only, no schema).\n'
R1_MARKER=b'\n// ADR-0009 R1: saved daily reports (draft, submission, versions, history).\n'
R2_MARKER=b'\n// ADR-0009 R2: manager review, rating, return, rework and notifications.\n'
R0_KEYS=[b'dailyreports_nav',b'dailyreports_tabs',b'dailyreports_tab_my',b'dailyreports_button',
         b'dailyreports_nav_short',b'dailyreports_button_short']
E2_MARKER=b'\n// Communication Center E2 (2026-11-16): Battle Plan discussion (Q9).\n'
UX_MARKER=b'\n// Communication Center UX-1 (2026-11-18): sections and accessible presentation.\n'
UX_KEYS=[b'communicationux_conversations', b'communicationux_notification_count', b'communicationux_filters', b'communicationux_details', b'communicationux_empty_search', b'communicationux_empty_all', b'communicationux_empty_filtered', b'communicationux_reset', b'communicationux_read', b'communicationux_unread', b'communicationux_thread_count']
C145_MARKER=b'\n// ADR-0009 / #145: strings for the templated communication centre.\n'
C145_KEYS=[b'communicationux2_badge_total',b'communicationux2_badge_unread',b'communicationux2_audience_object',b'communicationux2_compose_placeholder',b'communicationux2_direct',b'communicationux2_due',b'communicationux2_edit',b'communicationux2_empty_search_text',b'communicationux2_empty_search_title',b'communicationux2_files',b'communicationux2_group_members',b'communicationux2_group_people',b'communicationux2_kv_due',b'communicationux2_kv_status',b'communicationux2_kv_type',b'communicationux2_mark_all',b'communicationux2_mark_read',b'communicationux2_new_messages',b'communicationux2_newconversation',b'communicationux2_notification_settings',b'communicationux2_only_unread',b'communicationux2_open_calendar',b'communicationux2_open_conversation',b'communicationux2_reset_search',b'communicationux2_select_text',b'communicationux2_select_title',b'communicationux2_show_all',b'communicationux2_you',b'communicationux2_chip_mentions',b'communicationux2_details',b'communicationux2_empty_discussions_title',b'communicationux2_empty_discussions_text',b'communicationux2_empty_mentions_title',b'communicationux2_empty_mentions_text',b'communicationux2_empty_messages_title',b'communicationux2_empty_messages_text',b'communicationux2_empty_unread_title',b'communicationux2_empty_unread_text',b'communicationux2_goto_discussions',b'communicationux2_goto_messages',b'communicationux2_group_earlier',b'communicationux2_group_today',b'communicationux2_no_unread_notifications',b'communicationux2_search_objects',b'communicationux2_search_people',b'communicationux2_type_dailyreport',b'communicationux2_type_dayplan',b'communicationux2_type_meeting',b'communicationux2_type_mention',b'communicationux2_type_message',b'communicationux2_type_other',b'communicationux2_type_task']
A10_MARKER=b'\n// ADR-0010: reviewer by organisation chart, self review at the top, manager visibility and report copies.\n'
DF_MARKER=b'\n// Day feed -> report draft (2026-11-23): own submitted task results into the Done field of the own draft.\n'
DF_KEYS=[b'dailyreports_feed_tab',b'dailyreports_feed_title',b'dailyreports_feed_intro',b'dailyreports_feed_day',
         b'dailyreports_feed_show',b'dailyreports_feed_today',b'dailyreports_feed_submitted',b'dailyreports_feed_now',
         b'dailyreports_feed_add',b'dailyreports_feed_open_task',b'dailyreports_feed_open_report',
         b'dailyreports_feed_empty_title',b'dailyreports_feed_empty_text',b'dailyreports_feed_sent',b'dailyreports_feed_pages',
         b'dailyreports_feed_prev',b'dailyreports_feed_next',b'dailyreports_resultadded',b'dailyreports_resultexists']
_DF=json.loads((ROOT/'docs/reconcile/day-feed-report-draft-source-preservation.json').read_text(encoding='utf-8'))
DF_BEFORE={lang:next(r['before_sha256_lf'] for r in _DF['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
           for lang in ('en','ru')}
_A10=json.loads((ROOT/'docs/reconcile/daily-reports-adr0010-source-preservation.json').read_text(encoding='utf-8'))
A10_BEFORE={lang:next(r['before_sha256_lf'] for r in _A10['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in ('en','ru')}
BELL_MARKER=b'\n// Top bar bell and opt-in notification sound (owned shell).\n'
BELL_KEYS=[b'notificationbell_title',b'notificationbell_label',b'notificationbell_all',b'notificationbell_loading',
           b'notificationbell_empty',b'notificationbell_error',b'notificationbell_unavailable',b'notificationbell_retry',
           b'notificationbell_readonly_hint',b'notificationbell_sound',b'notificationbell_sound_off',b'notificationbell_sound_on',
           b'notificationbell_sound_blocked',b'notificationbell_sound_enable',b'notificationbell_sound_test',
           b'notificationbell_sound_disable',b'privacy:metadata:preference:notificationsound',
           b'privacy:metadata:preference:notificationsoundsignalled']
DF_AFTER={lang:next(r['after_sha256_lf'] for r in _DF['files']
                    if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
          for lang in ('en','ru')}
E2_KEYS=[b'bpu_tab_discussion',b'bpu_discussion_empty',b'bpu_discussion_readonly',b'bpu_discussion_reader',
         b'communication_object_battleplan',b'communicationcenter_openplan']
NAV_MARKER=b'\n// Adaptive BMS24 navigation (2026-11-18): narrow-window menu button and drawer close label.\n'
NAV_KEYS=[b'bms24_menu_button',b'bms24_menu_close']
AE_MARKER=b'\n// Day feed "Added earlier" (2026-11-24): mark of a result already added to the report of the day.\n'
SUM_MARKER=b'\n// ADR-0011 block 1: monthly summary of daily reports for managers and administrators.\n'
SCH_MARKER=b'\n// ADR-0011 block 2: working days, weekly summary and reminders (owner decisions 2026-11-25).\n'
_SCH=json.loads((ROOT/'docs/reconcile/daily-reports-schedule-source-preservation.json').read_text(encoding='utf-8'))
SCH_BEFORE={lang:next(r['before_sha256_lf'] for r in _SCH['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in ('en','ru')}
_SUM=json.loads((ROOT/'docs/reconcile/daily-reports-summary-source-preservation.json').read_text(encoding='utf-8'))
SUM_BEFORE={lang:next(r['before_sha256_lf'] for r in _SUM['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in ('en','ru')}
AE_KEYS=[b'dailyreports_feed_addedearlier',b'dailyreports_feed_addedearlier_hint']
_AE=json.loads((ROOT/'docs/reconcile/day-feed-added-earlier-source-preservation.json').read_text(encoding='utf-8'))
AE_BEFORE={lang:next(r['before_sha256_lf'] for r in _AE['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
           for lang in ('en','ru')}
R4_MARKER=b'\n// Daily reports R4 (2026-11-24): team for the day, owner decisions T1-T5.\n'
R4_KEYS=[b'dailyreports_queue_team',b'dailyreports_status_notsent',b'dailyreports_team_day',b'dailyreports_team_scope',
         b'dailyreports_team_scope_team',b'dailyreports_team_scope_company',b'dailyreports_team_unit',
         b'dailyreports_team_unit_all',b'dailyreports_team_unit_none',b'dailyreports_team_status_all',b'dailyreports_team_show',
         b'dailyreports_team_filters',b'dailyreports_team_counts',b'dailyreports_team_reviewer',b'dailyreports_team_open',
         b'dailyreports_team_open_for',b'dailyreports_team_empty',b'dailyreports_team_note']
_R2=json.loads((ROOT/'docs/reconcile/daily-reports-r2-source-preservation.json').read_text(encoding='utf-8'))
R2_AFTER={lang:next(r['after_sha256_lf'] for r in _R2['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
          for lang in ('en','ru')}
_NAV=json.loads((ROOT/'docs/reconcile/adaptive-navigation-source-preservation.json').read_text(encoding='utf-8'))
NAV_BEFORE={lang:next(r['before_sha256_lf'] for r in _NAV['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in ('en','ru')}
_R4=json.loads((ROOT/'docs/reconcile/daily-reports-r4-source-preservation.json').read_text(encoding='utf-8'))
R4_BEFORE={lang:next(r['before_sha256_lf'] for r in _R4['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
           for lang in ('en','ru')}
_COMM=json.loads((ROOT/'docs/reconcile/communication-center-cleanup-source-preservation.json').read_text(encoding='utf-8'))
COMM_AFTER={lang:next(r['after_sha256_lf'] for r in _COMM['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
            for lang in PUBLISHED}
_DAY=json.loads((ROOT/'docs/reconcile/daily-summary-after110-source-preservation.json').read_text(encoding='utf-8'))
DAY_AFTER={lang:next(r['after_sha256_lf'] for r in _DAY['files'] if r['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
           for lang in PUBLISHED}

def keys(blob): return re.findall(rb"^\$string\['([^']+)'\]",blob,re.M)

class Preservation(unittest.TestCase):
    def test_published_prefix_unchanged_and_new_keys_do_not_override_old(self):
        for lang,expected in PUBLISHED.items():
            data=(ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php').read_bytes().replace(b'\r\n',b'\n')
            prefix,tail=data.split(DAY_MARKER)
            self.assertEqual(expected,hashlib.sha256(prefix).hexdigest())
            day,comm=tail.split(COMM_MARKER) if COMM_MARKER in tail else (tail,None)
            daykeys=keys(day)
            self.assertEqual(18,len(daykeys));self.assertEqual(len(daykeys),len(set(daykeys)))
            self.assertTrue(all(k.startswith(b'dailyreport_') for k in daykeys))
            self.assertFalse(set(daykeys)&set(re.findall(rb"\$string\['([^']+)'\]",prefix)))
            r0=None
            if comm is not None and R0_MARKER in comm:
                comm,r0=comm.split(R0_MARKER)
            if comm is not None:
                # The #113 file (published prefix + day-summary block) is byte-identical up to the next marker.
                self.assertEqual(DAY_AFTER[lang],hashlib.sha256(prefix+DAY_MARKER+day).hexdigest())
                commkeys=keys(comm)
                self.assertEqual(23,len(commkeys));self.assertEqual(len(commkeys),len(set(commkeys)))
                self.assertTrue(all(k.startswith(COMM_PREFIXES) for k in commkeys))
                self.assertFalse(set(commkeys)&set(keys(prefix+day)))
            r1=None
            if r0 is not None and R1_MARKER in r0:
                r0,r1=r0.split(R1_MARKER)
            if r0 is not None:
                # The #114 file (… + cleanup block) is byte-identical up to the R0 marker.
                self.assertEqual(COMM_AFTER[lang],hashlib.sha256(prefix+DAY_MARKER+day+COMM_MARKER+comm).hexdigest())
                self.assertEqual(R0_KEYS,keys(r0))
                self.assertFalse(set(R0_KEYS)&set(keys(prefix+day+comm)))
            r2=None
            if r1 is not None and R2_MARKER in r1:
                r1,r2=r1.split(R2_MARKER)
            if r1 is not None:
                r1keys=keys(r1)
                self.assertEqual(54,len(r1keys));self.assertEqual(len(r1keys),len(set(r1keys)))
                self.assertTrue(all(k.startswith((b'dailyreports_',b'privacy:metadata:dailyreports')) for k in r1keys))
                self.assertFalse(set(r1keys)&set(keys(prefix+day+comm+r0)))
            e2=None
            if r2 is not None and E2_MARKER in r2:
                r2,e2=r2.split(E2_MARKER)
            if e2 is not None and UX_MARKER in e2:
                e2,ux=e2.split(UX_MARKER)
                if C145_MARKER in ux:
                    ux,c145=ux.split(C145_MARKER)
                    if NAV_MARKER in c145:
                        c145,nav=c145.split(NAV_MARKER)
                        if AE_MARKER in nav:
                            # Navigation and everything before it are byte-identical up to the "Added earlier" marker.
                            nav,ae=nav.split(AE_MARKER)
                            if R4_MARKER in ae:
                                ae,r4=ae.split(R4_MARKER)
                                # The file released with "Added earlier" (#165) is byte-identical up to the R4 marker;
                                # that file ended with a newline, which is the first byte of the marker.
                                self.assertEqual(R4_BEFORE[lang],hashlib.sha256(data.split(R4_MARKER)[0]+b'\n').hexdigest())
                                self.assertEqual(R4_KEYS,keys(r4.split(SUM_MARKER)[0]))
                                self.assertFalse(set(R4_KEYS)&set(keys(data.split(R4_MARKER)[0])))
                                if SUM_MARKER in r4:
                                    # Everything up to the ADR-0011 summary marker is the published R4 (#168) file; keys are new.
                                    r4,summ=r4.split(SUM_MARKER)
                                    if SCH_MARKER in summ:
                                        # Everything up to the block 2 marker is the block 1 file; block 2 keys are new.
                                        summ,sch=summ.split(SCH_MARKER)
                                        self.assertEqual(SCH_BEFORE[lang],hashlib.sha256(data.split(SCH_MARKER)[0]).hexdigest())
                                        schkeys=keys(sch)
                                        self.assertEqual(24,len(schkeys));self.assertEqual(len(schkeys),len(set(schkeys)))
                                        self.assertTrue(all(k.startswith((b'dailyreports_',b'messageprovider:dailyreport_',
                                                                          b'communication_notification_dailyreport_')) for k in schkeys))
                                        self.assertFalse(set(schkeys)&set(keys(data.split(SCH_MARKER)[0])))
                                    self.assertEqual(SUM_BEFORE[lang],hashlib.sha256(data.split(SUM_MARKER)[0]).hexdigest())
                                    sumkeys=keys(summ)
                                    self.assertEqual(18,len(sumkeys));self.assertEqual(len(sumkeys),len(set(sumkeys)))
                                    self.assertTrue(all(k.startswith(b'dailyreports_') for k in sumkeys))
                                    self.assertFalse(set(sumkeys)&set(keys(data.split(SUM_MARKER)[0])))
                            self.assertEqual(AE_BEFORE[lang],hashlib.sha256(data.split(AE_MARKER)[0]).hexdigest())
                            self.assertEqual(AE_KEYS,keys(ae))
                            self.assertFalse(set(AE_KEYS)&set(keys(data.split(AE_MARKER)[0])))
                        # The integrated Day Feed and bell are byte-identical up to the adaptive navigation marker.
                        self.assertEqual(NAV_BEFORE[lang],hashlib.sha256(data.split(NAV_MARKER)[0]).hexdigest())
                        self.assertEqual(NAV_KEYS,keys(nav))
                        self.assertFalse(set(NAV_KEYS)&set(keys(data.split(NAV_MARKER)[0])))
                    if BELL_MARKER in c145:
                        c145,bell=c145.split(BELL_MARKER)
                        prefix_before_bell=data.split(BELL_MARKER)[0]
                        self.assertEqual(DF_AFTER[lang],hashlib.sha256(prefix_before_bell).hexdigest())
                        self.assertEqual(BELL_KEYS,keys(bell))
                        self.assertFalse(set(BELL_KEYS)&set(keys(prefix_before_bell)))
                    if A10_MARKER in c145:
                        # The #145 file is byte-identical up to the ADR-0010 marker; ADR-0010 keys are new.
                        c145,a10=c145.split(A10_MARKER)
                        self.assertEqual(A10_BEFORE[lang],hashlib.sha256(data.split(A10_MARKER)[0]).hexdigest())
                        if DF_MARKER in a10:
                            # The ADR-0010 file is byte-identical up to the day feed marker; day feed keys are new.
                            a10,df=a10.split(DF_MARKER)
                            self.assertEqual(DF_BEFORE[lang],hashlib.sha256(data.split(DF_MARKER)[0]).hexdigest())
                            self.assertEqual(DF_KEYS,keys(df))
                            self.assertFalse(set(DF_KEYS)&set(keys(data.split(DF_MARKER)[0])))
                        a10keys=keys(a10)
                        self.assertEqual(41,len(a10keys));self.assertEqual(len(a10keys),len(set(a10keys)))
                        self.assertTrue(all(k.startswith((b'dailyreports_',b'messageprovider:dailyreport_',
                                                          b'communication_notification_dailyreport_')) for k in a10keys))
                        self.assertFalse(set(a10keys)&set(keys(data.split(A10_MARKER)[0])))
                    self.assertEqual(C145_KEYS,keys(c145))
                    self.assertFalse(set(C145_KEYS)&set(keys(data.split(C145_MARKER)[0])))
                self.assertEqual(UX_KEYS, keys(ux))
                self.assertFalse(set(keys(ux)) & set(keys(data.split(UX_MARKER)[0])))
                original=json.loads((ROOT/'docs/reconcile/communication-center-e2-source-preservation.json').read_text())
                expected=next(x['after_sha256_lf'] for x in original['files'] if x['path']==f'local/kbacompany/lang/{lang}/local_kbacompany.php')
                self.assertEqual(expected,hashlib.sha256(data.split(UX_MARKER)[0]).hexdigest())
            if e2 is not None:
                # The R2 file (… + R2 block, main after #119) is byte-identical up to the E2 marker.
                self.assertEqual(R2_AFTER[lang],hashlib.sha256(prefix+DAY_MARKER+day+COMM_MARKER+comm+R0_MARKER+r0
                                                               +R1_MARKER+r1+R2_MARKER+r2).hexdigest())
                self.assertEqual(E2_KEYS,keys(e2))
                self.assertFalse(set(E2_KEYS)&set(keys(prefix+day+comm+r0+r1+r2)))
            if r2 is not None:
                r2keys=keys(r2)
                self.assertEqual(46,len(r2keys));self.assertEqual(len(r2keys),len(set(r2keys)))
                self.assertTrue(all(k.startswith((b'dailyreports_',b'messageprovider:dailyreport_',
                                                  b'communication_notification_dailyreport_')) for k in r2keys))
                self.assertFalse(set(r2keys)&set(keys(prefix+day+comm+r0+r1)))

    def test_exact_candidate_passes(self): self.assertEqual([],gate.check(ROOT))

    def test_mutation_of_either_language_is_rejected(self):
        original=gate.digest
        for lang in PUBLISHED:
            path=ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php'
            for mutation in [lambda b:b+b'\n// unexpected',lambda b:b.replace(b'dailyreport_title',b'overridden_key',1),
                             lambda b:b.replace(b'communication_reply',b'overridden_key',1),
                             lambda b:b.replace(b'dailyreports_button',b'dailyreports_other',1),
                             lambda b:b.replace(b'communicationcenter_openplan',b'overridden_key',1),
                             lambda b:b.replace(b'bms24_menu_close',b'overridden_key',1)]:
                changed=hashlib.sha256(mutation(path.read_bytes().replace(b'\r\n',b'\n'))).hexdigest()
                with patch.object(gate,'digest',side_effect=lambda p: changed if p==path else original(p)):
                    self.assertIn('parent source lost: '+path.relative_to(ROOT).as_posix(),gate.check(ROOT))

    def test_block_order_and_override_mutations_fail_the_structure_check(self):
        """A later block inserted before the day summary, or a later key overriding an earlier one, is detected."""
        for lang in PUBLISHED:
            data=(ROOT/f'local/kbacompany/lang/{lang}/local_kbacompany.php').read_bytes().replace(b'\r\n',b'\n')
            prefix,tail=data.split(DAY_MARKER)
            day,comm=tail.split(COMM_MARKER)
            comm=comm.split(R0_MARKER)[0]
            # Cleanup block moved in front of the day summary: the bytes before the cleanup marker are no longer #113.
            moved=prefix+COMM_MARKER+comm+DAY_MARKER+day
            self.assertNotEqual(DAY_AFTER[lang],hashlib.sha256(moved.split(COMM_MARKER)[0]).hexdigest())
            # A cleanup key that redefines a day-summary key is caught by the override check.
            overriding=comm+b"$string['dailyreport_title'] = 'x';\n"
            self.assertTrue(set(keys(overriding))&set(keys(prefix+day)))

if __name__=='__main__':unittest.main()
