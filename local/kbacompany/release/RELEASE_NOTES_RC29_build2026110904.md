# BMS2026110904 — document and employee acceptance repair

Based on PR57 exact1bfa75bf439e8cf45725c2c3bb69ca01b543fb01. Corporate0901 unchanged.
Correct document fixture to use canonical BMS membership, complete SIPOC links,
current document contract/error, and retain all lifecycle/history/form assertions.
The semantic document endpoint fixture explicitly enrolls its reviewer as a BMS
member; site-administrator privilege alone remains insufficient for reviewer eligibility.
Add five rejection cases proving inactive/foreign/missing membership and suspended/
deleted users cannot be assigned review rights or mutate document data.
Use an email-like phone fixture within Moodle varchar(20), keeping replacement and
invalid-input assertions and adding explicit schema/semantic preconditions.
No domain authorization, business behavior, DDL, existing migrations or data changed.
Code-only0904 savepoint uniquely identifies the changed test-bearing release artifact.
Repeat full MariaDB/Percona matrix plus upgrade from exactPR57. Draft only;
remaining legacy failures and operational acceptance are not waived. No deployment.
