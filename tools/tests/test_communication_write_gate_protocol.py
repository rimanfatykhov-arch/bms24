"""RC33 PR-1: static guard for the shared communication write gate lock protocol.

Single-process PHPUnit cannot show that every access-reducing writer takes the gate, or in which order.
This guard pins the protocol in source: each listed writer acquires the gate, the gate is taken after
member/object locks and before commmsg locks and before the caller's own transaction, and no writer
takes the raw 'communication:' lock behind the gate's back. It is not a concurrency proof.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LOCAL = ROOT / 'local/kbacompany/classes/local'
GATE = 'communication_write_gate::acquire('


def body(source, name):
    match = re.search(r'\n    (?:public |private |protected )?static function ' + re.escape(name) + r'\(', source)
    if not match:
        raise AssertionError('missing function ' + name)
    rest = source[match.end():]
    end = re.search(r'\n    (?:public |private |protected )?static function |\n    /\*\*', rest)
    return rest[:end.start()] if end else rest


def read(name):
    return (LOCAL / name).read_text(encoding='utf-8')


class CommunicationWriteGateProtocolTest(unittest.TestCase):
    def test_gate_key_and_order_are_documented(self):
        gate = read('communication_write_gate.php')
        self.assertIn("return 'communication:' . $companyuuid;", gate)
        order = [gate.index(s) for s in ['1. object locks', '2. member:', '3. communication:', '4. commmsg:']]
        self.assertEqual(order, sorted(order))

    def test_communication_writers_take_the_gate(self):
        source = read('communication_service.php')
        for name in ['create_thread', 'add_participant', 'append_message', 'send_message_with_attachments',
                     'leave_thread', 'remove_participant', 'change_message']:
            self.assertIn(GATE, body(source, name), name)

    def test_checks_and_writes_run_under_the_gate(self):
        source = read('communication_service.php')
        append = body(source, 'append_message')
        self.assertLess(append.index(GATE), append.index('append_message_locked('))
        locked = body(source, 'append_message_locked')
        self.assertLess(locked.index('company_context_service::context'), locked.index('start_delegated_transaction'))
        self.assertIn('recipient_eligible', locked)
        for name in ['add_participant', 'remove_participant', 'change_message']:
            fn = body(source, name)
            self.assertLess(fn.index(GATE), fn.index('company_context_service::context'), name)
        files = body(source, 'send_message_with_attachments')
        self.assertLess(files.index(GATE), files.index('start_delegated_transaction'))

    def test_change_message_takes_gate_before_message_lock(self):
        source = read('communication_service.php')
        self.assertIn(GATE, body(source, 'change_message'))
        self.assertNotIn("'commmsg:'", body(source, 'change_message'))
        self.assertIn("'commmsg:'", body(source, 'change_message_locked'))

    def test_no_raw_communication_lock_outside_the_gate(self):
        for path in (ROOT / 'local/kbacompany').rglob('*.php'):
            if path.name == 'communication_write_gate.php':
                continue
            text = path.read_text(encoding='utf-8', errors='replace')
            self.assertNotRegex(text, r"get_lock\(\s*'communication:'", str(path))

    def test_revocation_takes_gate_after_member_lock_and_before_commit(self):
        change = body(read('membership_access_service.php'), 'change')
        member = change.index("'member:'")
        gate = change.index(GATE)
        tx = change.index('start_delegated_transaction')
        self.assertLess(member, gate)
        self.assertLess(gate, tx)
        self.assertLess(change.index('$gate->release()'), change.index('$lock->release()'))
        closure = body(read('employee_service.php'), 'close_other_company_memberships_for_contact')
        self.assertLess(closure.index(GATE), closure.index("update_record('local_kbacompany_member'"))

    def test_object_posts_hold_gate_through_their_own_transaction(self):
        for name, fn, lock in [('task_service.php', 'post_discussion_message', "'taskdiscussion:'"),
                               ('battle_plan_discussion_service.php', 'post', "'bplandiscussion:'")]:
            text = body(read(name), fn)
            self.assertLess(text.index(lock), text.index(GATE), name)
            self.assertLess(text.index(GATE), text.index('start_delegated_transaction'), name)
            self.assertLess(text.index('$gate->release()'), text.index('$lock->release()'), name)


    # N28 (COORD-D05, owner option A in #205): object ACL writers commit under the same gate, and the write right
    # to a task or Battle Plan discussion is re-checked under it for every message.
    ACL_WRITERS = [
        ('task_service.php', 'classify_duty', "'task:'"),
        ('task_service.php', 'save', "'task:'"),
        ('task_service.php', 'set_observer', "'task:'"),
        ('task_service.php', 'transition', "'task:'"),
        ('task_service.php', 'delete_task', "'task:'"),
        ('battle_plan_service.php', 'set_observer', "'battleplan:'"),
        ('battle_plan_service.php', 'remove_task', "'battleplan:'"),
        ('battle_plan_service.php', 'save_settings', "'battleplan:'"),
        ('battle_plan_service.php', 'transition', "'battleplan:'"),
        ('battle_plan_service.php', 'review', "'battleplan:'"),
        ('position_coverage_service.php', 'transition', "'position_coverage:'"),
        ('meeting_service.php', 'create', "'meeting:'"),
        ('meeting_service.php', 'add_participant', 'acquire_booking_lock('),
        ('access_role_service.php', 'set_grant', None),
        ('access_role_service.php', 'assign', None),
    ]

    def test_n28_object_acl_writers_hold_gate_through_their_commit(self):
        for name, fn, lock in self.ACL_WRITERS:
            text = body(read(name), fn)
            where = name + '::' + fn
            self.assertEqual(1, text.count(GATE), where)
            gate = text.index(GATE)
            if lock is not None:
                self.assertLess(text.index(lock), gate, where + ': object lock before gate')
            if 'start_delegated_transaction' in text:
                self.assertLess(gate, text.index('start_delegated_transaction'), where + ': gate before transaction')
            release = text.index('$gate->release()')
            self.assertLess(gate, release, where)
            for own in ['$lock->release()', '$resourcelock->release()']:
                if lock is not None and own in text[release:]:
                    self.assertLess(release, text.rindex(own), where + ': gate released before own lock')
            self.assertIn('finally', text[gate:release], where + ': gate released in finally')

    def test_n28_access_role_writers_roll_back_and_release_on_failure(self):
        for fn in ['set_grant', 'assign']:
            text = body(read('access_role_service.php'), fn)
            gate = text.index(GATE)
            self.assertLess(gate, text.index('start_delegated_transaction'), fn)
            self.assertLess(text.index('allow_commit'), text.index('$tx->rollback($e)'), fn)
            self.assertLess(text.index('$tx->rollback($e)'), text.index('$gate->release()'), fn)

    def test_n28_meeting_participant_is_rechecked_under_the_gate(self):
        text = body(read('meeting_service.php'), 'add_participant')
        gate = text.index(GATE)
        for check in ['self::require_participant(', "self::fail('permission')", 'self::require_employee(']:
            self.assertGreater(text.rindex(check), gate, check)

    def test_n28_object_write_right_is_rechecked_under_the_gate(self):
        locked = body(read('communication_service.php'), 'append_message_locked')
        tx = locked.index('start_delegated_transaction')
        for check in ['task_service::can_post_discussion_for(', 'battle_plan_service::can_post_discussion_for(']:
            self.assertIn(check, locked)
            self.assertLess(locked.index(check), tx, check)
        self.assertIn('public static function can_post_discussion_for(', read('task_service.php'))
        self.assertIn('public static function can_post_discussion_for(', read('battle_plan_service.php'))
        append = body(read('communication_service.php'), 'append_message')
        self.assertLess(append.index(GATE), append.index('append_message_locked('))

    def test_n28_acl_writer_locks_are_documented_before_member_locks(self):
        gate = read('communication_write_gate.php')
        doc = gate[gate.index('1. object locks'):gate.index('2. member:')]
        for key in ['taskcenter:', 'task:', 'battleplan:', 'position_coverage:', 'meetingresource:', 'meeting:',
                    'never held together']:
            self.assertIn(key, doc, key)

    def test_n28_gated_regions_take_no_further_named_lock(self):
        for name, fn, lock in self.ACL_WRITERS:
            text = body(read(name), fn)
            region = text[text.index(GATE):text.index('$gate->release()')]
            self.assertNotIn('get_lock(', region, name + '::' + fn)
            self.assertNotIn('acquire_booking_lock(', region, name + '::' + fn)

if __name__ == '__main__':
    unittest.main()
