# KBA Connect v5.0.31.2 RC1

## Contact Hydration & Auto User Creation

- Deal contact relations are read through `crm.deal.contact.items.get`.
- Every linked Contact ID is hydrated via the existing `crm.contact.get` transport before Moodle resolution.
- Production Control Center provides a safe Dry Run and explicit REAL run for one Deal ID.
- Missing Moodle users are matched by email or created automatically when a valid Bitrix24 email exists.
- All linked contacts can be enrolled into all mapped courses in the deal.
- Dashboard now displays KBA Connect version/environment and a Production Actions card.
- DEBUG logging is opt-in, can be scoped to one Deal ID, and writes no extra database table.
- Existing v5.0.31 CRM Field Engine, mappings, queue, Corporate integration and Production settings are preserved.

## Production incident covered

Deal 3761 / Contact 12657 (Крючкова Эльмира Фаритовна): relation was detected, but the contact card was not hydrated, leaving email empty and preventing Moodle user creation.
