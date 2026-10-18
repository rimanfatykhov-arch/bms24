# SECURITY AND PRIVACY — Implementation evidence v0.33.1

## Access control

Evidence uses the implementation-task access boundary:

- assigned employee;
- assigned programme curator;
- authorised company manager with `manageimplementationaudits`;
- site administrator.

File downloads repeat the same task-access check in `local_kbacorporate_pluginfile`.

## Write protection

- Moodle forms include a session key;
- accept/reject POST requires `require_sesskey()`;
- URL task and evidence task are explicitly matched;
- the submitting user cannot review the same version;
- rejected evidence requires a reviewer comment;
- once versioning starts, generic task-status edits are locked.

## Tenant isolation

Every evidence record inherits its company through its task. No write accepts an arbitrary company ID. Access is resolved from the stored task before data or files are returned.

## Privacy

The privacy provider declares submission/review fields, includes evidence actors in context/user discovery, exports relevant versions and history, and anonymises actor references on user deletion. Company deletion removes log rows, evidence rows and files in foreign-key-safe order.

## Content safety

URLs are cleaned with `PARAM_URL`; comments are plain text; filenames are escaped; stored files are served as downloads after access control.
