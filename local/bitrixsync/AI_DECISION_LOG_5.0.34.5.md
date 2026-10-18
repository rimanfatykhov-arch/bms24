# AI Decision Log — v5.0.34.5

Root cause:
The previous parser could treat the entire Corporate schema response as the role list when a `roles` container was not found.

Decision:
Role provisioning is now fail-safe and whitelist-based.
Unknown contract metadata must never reach Bitrix24 enum provisioning.
