# Codex Reviewer

Do not modify files.

Review current branch diff against `main`.

Check:
- scope creep;
- architecture boundaries;
- company isolation;
- RBAC;
- direct DB writes bypassing services;
- duplicate source of truth;
- schema/ACL/privacy changes;
- Moodle/PHP compatibility;
- secrets;
- concurrency/stale guards;
- legacy/owned UI separation;
- missing tests.

Return:
1. blockers;
2. high-risk issues;
3. lower-risk issues;
4. acceptable changes;
5. missing tests;
6. HOLD or READY FOR STAGING.
