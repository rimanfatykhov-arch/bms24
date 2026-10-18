# KBA Corporate Dashboard 0.28.0.1

This hotfix corrects the database definition introduced in v0.28.0.

- Removed the redundant `competency_ix` index from `local_kbacorp_bms_comp`.
- Moodle already creates the required index for the `competency_fk` foreign key.
- The unique `criterionid, competencyid` constraint remains active.
- No manual database correction is required after the failed v0.28.0 attempt.

Upload this ZIP over the plugin and open Moodle administration again. The pending upgrade will restart and complete with build `2026090287`.
