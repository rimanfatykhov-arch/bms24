#!/usr/bin/env python3
"""Static upgrade compatibility and read-only performance contract; no Moodle DB is used."""
from pathlib import Path
import re
import sys
root = Path(__file__).resolve().parents[1]
read = lambda name: (root / name).read_text(encoding='utf-8')
page = read('communication_center.php')
ui = read('classes/local/communication_center_ui_service.php')
service = read('classes/local/communication_live_service.php')
js = read('amd/src/communication_live_ui.js')
benchmark = read('cli/benchmark_communication_poll_1825.php')
checks = {
    'build1825': '$plugin->version = 2026101825;' in read('version.php'),
    'current page counters from scoped projection': "'data-live-unread' => (int)($data['counts']['unread_threads'] ?? 0)" in page,
    'notification count from scoped projection': "'data-live-notifications' => (int)($data['counts']['unread_notifications'] ?? 0)" in page,
    'accessible unread indicator': "'bms-comm4-unread-indicator'" in ui and "'aria-live' => 'polite'" in ui,
    'client indicator updated on change': 'renderUnread();' in js and 'workspace.dataset.liveUnread = newWorkspace.dataset.liveUnread' in js,
    'initial title is restored': "document.title = count ?" in js and 'originalTitle' in js,
    'active 25s idle 60s': 'activeInterval = 25000, idleInterval = 60000, idleAfter = 300000' in js,
    'poll jitter': 'Math.random() * 0.26' in js,
    'exponential backoff': 'Math.pow(2, liveFailures)' in js,
    'stops hidden/offline scheduling': "!document.hidden && navigator.onLine !== false" in js,
    'client pagehide cleanup': "window.addEventListener('pagehide'" in js,
    'server rechecks participant on out-of-window selected': "record_exists('local_kbacompany_commpart'" in service,
    'server ACL on selected out-of-window': service.count('communication_object_service::describe(') >= 2,
    'selected in-window safely avoids redundant ACL': 'if (!isset($allowed[$selected]))' in service,
    'server bounded 60 threads': 'MAX_THREADS = 60' in service,
    'server bounded 20 notifications': 'MAX_NOTIFICATIONS = 20' in service,
    'read-only benchmark': "communication_live_service::snapshot" in benchmark and not any(
        x in benchmark for x in ['insert_record(', 'update_record(', 'delete_records(']),
    'benchmark runs bounded': "(int)$options['runs'] > 30" in benchmark,
    'read-only poll endpoint': not any(x in read('communication_live_poll.php') for x in ['insert_record(', 'update_record(', 'delete_records(']),
    'build artifact equals source': js == read('amd/build/communication_live_ui.min.js'),
}
for name, ok in checks.items():
    print(('PASS' if ok else 'FAIL') + ' ' + name)
print('COMMUNICATION 1825:', sum(checks.values()), '/', len(checks))
if not all(checks.values()):
    sys.exit(1)
