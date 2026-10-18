# DATA MODEL — Implementation evidence v0.33.1

## `local_kbacorp_impl_evid`

One row is one submitted evidence version.

Key fields:

- `taskid` — parent implementation task;
- `versionno` — sequential version number unique inside the task;
- `status` — `submitted`, `accepted` or `rejected`;
- `summary`, `externalurl` — immutable submitted payload;
- `submitterid`, `submittedat` — submission attribution;
- `reviewerid`, `reviewedat`, `reviewcomment` — review decision;
- `supersedesid` — previous version reference;
- `createdby`, `modifiedby`, timestamps — technical attribution.

Unique invariant:

```text
(taskid, versionno)
```

## `local_kbacorp_impl_evlog`

Append-only event log with:

- evidence and task references;
- actor;
- action;
- from/to evidence statuses;
- comment;
- technical JSON details;
- event time.

## File storage

```text
context: system
component: local_kbacorporate
filearea: implementation_evidence_record
itemid: local_kbacorp_impl_evid.id
```

## Lifecycle invariant

```text
latest evidence submitted ↔ task review
latest evidence rejected ↔ task inprogress
latest evidence accepted ↔ task completed
```
