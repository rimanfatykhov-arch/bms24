#!/usr/bin/env python3
"""Static regression contract for Communication Center 3.1 auto-refresh."""
from pathlib import Path
import sys
root = Path(__file__).resolve().parents[1]
page = (root / 'communication_center.php').read_text()
probe = (root / 'communication_live_poll.php').read_text()
service = (root / 'classes/local/communication_live_service.php').read_text()
js = (root / 'amd/src/communication_live_ui.js').read_text()
build = (root / 'amd/build/communication_live_ui.min.js').read_text()
version = (root / 'version.php').read_text()
checks = {
    'version not below 1824': any(int(m) >= 2026101824 for m in __import__('re').findall(r'\$plugin->version\s*=\s*(\d+)\s*;', version)),
    'poll endpoint logs in': 'require_login();' in probe,
    'poll endpoint capability': "require_capability('local/kbacompany:viewowncompany'" in probe,
    'poll endpoint no-cache': 'Cache-Control: private, no-store' in probe,
    'poll endpoint only read': all(k not in probe for k in ('insert_record(', 'update_record(', 'delete_records(', 'require_sesskey()')),
    'company scoped snapshot': 'company_context_service::context($companyuuid, $actor);' in service,
    'participant scoped thread list': 'p.userid=:actor AND p.active=1' in service,
    'selected participant verified': "record_exists('local_kbacompany_commpart'" in service,
    'object ACL revalidated': service.count('communication_object_service::describe(') >= 2,
    'bounded thread polling': 'MAX_THREADS = 60' in service and 'self::MAX_THREADS + 1' in service,
    'bounded notifications': 'MAX_NOTIFICATIONS = 20' in service,
    'anonymous opaque revision': "return hash('sha256', implode('|', $parts));" in service,
    'page emits initial token': "'data-live-revision'" in page,
    'browser requests only on change': 'if (state.revision !== currentRevision)' in js,
    'browser avoids hidden tab': 'document.hidden' in js,
    'browser avoids active draft': 'hasDraftOrEditing()' in js,
    'browser backs off errors': 'Math.pow(2, liveFailures)' in js,
    'browser uses same-origin': "credentials: 'same-origin'" in js,
    'browser does not replace composer': 'historyNode.innerHTML = nextMessages.innerHTML;' in js and 'form.innerHTML' not in js,
    'browser preserves thread scroll': 'sidebarNode.scrollTop = listScroll;' in js,
    'browser preserves history scroll': 'historyNode.scrollTop = previousScroll;' in js,
    'native POST retained': "form.addEventListener('submit'" in js,
    'build matches source': js == build,
}
for label, ok in checks.items():
    print(('PASS' if ok else 'FAIL') + ' ' + label)
print('Communication 1824 contract: %d/%d' % (sum(checks.values()), len(checks)))
if not all(checks.values()):
    sys.exit(1)
