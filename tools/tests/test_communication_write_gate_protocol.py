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


if __name__ == '__main__':
    unittest.main()
