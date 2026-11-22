"""Fail-closed acceptance of exact 1100 -> 1601 schema/data evidence; pure functions."""
NEW = {'local_kbacompany_dreport', 'local_kbacompany_dreportver', 'local_kbacompany_dreportlink',
       'local_kbacompany_dreportrev', 'local_kbacompany_dreportlog'}


def require(ok, reason):
    if not ok:
        raise RuntimeError(reason)


def upgraded(before, after):
    require(before.get('version') == 2026111100, 'baseline version must be actual published 2026111100')
    require(after.get('version') == 2026111601, 'candidate version must be 2026111601')
    old = set(before.get('tables', [])); current = set(after.get('tables', []))
    require(old and not old & NEW and current == old | NEW, 'exactly five additive tables required')
    require(before.get('schemas') and before.get('rows'), 'baseline schema/data evidence missing')
    for table in old:
        require(before['schemas'][table] == after.get('schemas', {}).get(table), 'existing schema changed: ' + table)
        require(before['rows'][table] == after.get('rows', {}).get(table), 'existing data changed: ' + table)
    for table in NEW:
        require(after.get('schemas', {}).get(table, {}).get('columns'), 'new schema missing: ' + table)
        require(after.get('rows', {}).get(table, {}).get('count') == 0, 'unexpected backfill: ' + table)


def fresh_equal(fresh, upgrade):
    require(fresh.get('version') == upgrade.get('version') == 2026111601, 'fresh/upgrade version mismatch')
    require(fresh.get('schemas') and fresh['schemas'] == upgrade.get('schemas'), 'fresh/upgrade full domain schema differs')


def unchanged(first, second):
    for key in ['version', 'tables', 'schemas', 'rows']:
        require(first.get(key) and first[key] == second.get(key), 'repeat/switch changed ' + key)


def switch_preserves(before, disabled, enabled):
    require(before.get('enabled') is True and disabled.get('enabled') is False and enabled.get('enabled') is True,
            'feature switch was not actually exercised')
    require(sum(before.get('rows', {}).get(t, {}).get('count', 0) for t in NEW) > 0, 'no submitted data to preserve')
    unchanged(before, disabled); unchanged(before, enabled)
