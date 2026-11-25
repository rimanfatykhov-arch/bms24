"""Fail-closed acceptance of the supported daily-report schema transitions."""
NEW = {'local_kbacompany_dreport', 'local_kbacompany_dreportver', 'local_kbacompany_dreportlink',
       'local_kbacompany_dreportrev', 'local_kbacompany_dreportlog'}
ADR0010_TABLES = {'local_kbacompany_dreportcc', 'local_kbacompany_dreportset'}
ADDITIONS = {
    (2026111100, 2026111601): NEW,
    (2026111100, 2026112301): NEW | ADR0010_TABLES,
    (2026111601, 2026112301): ADR0010_TABLES,
    # ADR-0011 block 2: version-only step (scheduled task and message providers), no new table.
    (2026111100, 2026112501): NEW | ADR0010_TABLES,
    (2026111601, 2026112501): ADR0010_TABLES,
}


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def upgraded(before, after):
    transition = (before.get('version'), after.get('version'))
    require(transition in ADDITIONS, 'unsupported daily-report upgrade: %r -> %r' % transition)
    added = ADDITIONS[transition]
    old = set(before.get('tables', [])); current = set(after.get('tables', []))
    if transition[0] == 2026111601:
        require(NEW <= old, 'R1/R2 baseline tables missing')
    require(old and not old & added and current == old | added, 'unexpected upgrade table inventory')
    require(isinstance(before.get('enabled'), bool) and before['enabled'] is after.get('enabled'),
            'feature switch changed during the upgrade')
    require(before.get('schemas') and before.get('rows'), 'baseline schema/data evidence missing')
    for table in old:
        require(before['schemas'][table] == after.get('schemas', {}).get(table), 'existing schema changed: ' + table)
        require(before['rows'][table] == after.get('rows', {}).get(table), 'existing data changed: ' + table)
    for table in added:
        require(after.get('schemas', {}).get(table, {}).get('columns'), 'new schema missing: ' + table)
        require(after.get('rows', {}).get(table, {}).get('count') == 0, 'unexpected backfill: ' + table)


def fresh_equal(fresh, upgrade):
    version = upgrade.get('version')
    require(version in (2026111601, 2026112301, 2026112501) and fresh.get('version') == version, 'fresh/upgrade version mismatch')
    require(fresh.get('schemas') and fresh['schemas'] == upgrade.get('schemas'), 'fresh/upgrade full domain schema differs')


def unchanged(first, second):
    for key in ['version', 'tables', 'schemas', 'rows']:
        require(first.get(key) and first[key] == second.get(key), 'repeat/switch changed ' + key)


def switch_preserves(before, disabled, enabled):
    require(before.get('enabled') is True and disabled.get('enabled') is False and enabled.get('enabled') is True,
            'feature switch was not actually exercised')
    require(sum(before.get('rows', {}).get(t, {}).get('count', 0) for t in NEW) > 0, 'no submitted data to preserve')
    unchanged(before, disabled); unchanged(before, enabled)
