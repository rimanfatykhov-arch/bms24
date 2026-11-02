# local_kbacompany 1.1.0-rc4.1 — Task time hotfix

Build: 2026101219

- Added task start time and end time fields alongside task dates.
- Added DB upgrade fields `starttime` and `duetime` to canonical BMS tasks.
- Added validation for HH:MM and same-day start/end ordering.
- Task detail now shows start/end time.
- Fixed the task form submit button translation so it displays “Сохранить” / “Save changes” instead of `[[savechanges]]`.
