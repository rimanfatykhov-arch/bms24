# Corporate 0.40.6 — bridge 1.24 sections read API

New `bridge::get_implementation_program_sections(companyid, actorid, programid)` returns an authorized paid program's sanitized section summary from its confirmed baseline audit. No raw audit criteria, comments, users, evidence files or status updates. Unconfirmed baseline returns `available=false`, not zero completion. Requires Corporate management permission; BMS caller must also authenticate the BMS company UUID/project relationship. No database schema change. PHPUnit with Moodle bootstrap and actual data remains required.
