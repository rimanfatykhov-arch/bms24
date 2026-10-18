# Duplicate corporate membership

Legacy symptom: the same Moodle user appears twice in one company as Employee and Corporate Manager.

v0.14.2.1 migrates the two rows into one membership with both role flags and adds a database-level unique index on `(companyid, userid)`.
