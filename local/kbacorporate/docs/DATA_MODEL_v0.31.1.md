# DATA MODEL — Moodle Learning Plan bridge v0.31.1

## Link fields

### local_kbacorp_busrole.moodletemplateid
Stores the Moodle core `competency_template.id` associated with a KBA business role.

### local_kbacorp_devplan.moodleplanid
Stores the Moodle core `competency_plan.id` associated with a KBA development/GAP plan.

## Authority boundaries

| Domain | Authoritative store |
|---|---|
| Competency definition | Moodle core competency |
| Role competency membership | KBA role profile; synchronised to Moodle template |
| Moodle template competency membership | Moodle core |
| Individual learning-plan competency membership | Moodle core |
| GAP baseline / target level | KBA |
| Priority / mandatory | KBA |
| Recommended course / due date / action status | KBA |
| Course ↔ competency | Moodle core |
| BMS/Odoo metadata | KBA |

Link IDs are local integration references, not portable JSON identifiers. External contracts continue to use stable codes/idnumbers.
