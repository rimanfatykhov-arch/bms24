# KBA Corporate Dashboard 0.16.2

## Moodle 4.0 competency database compatibility fix

- Fixed `mycompetencies.php` database read error on Moodle 4.0.
- Corrected competency framework join from the non-existent `competency.frameworkid` field to Moodle core `competency.competencyframeworkid`.
- No plugin database schema changes.
- Existing navigation and own-competency access behavior from 0.16.1 is preserved.
