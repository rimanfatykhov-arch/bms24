# KBA Corporate Dashboard 0.22.2.2

Build: `2026090252`

Bitrix24 may deny `user.get` when the incoming webhook has CRM access but not Users access. This release isolates reference requests so the pipeline and stages remain usable. It then calls `user.current` and offers the webhook owner as a safe fallback responsible person.

For the complete employee list, edit or recreate the Bitrix24 incoming webhook with both CRM and Users permissions. Install over v0.22.2.1 without uninstalling the plugin.
