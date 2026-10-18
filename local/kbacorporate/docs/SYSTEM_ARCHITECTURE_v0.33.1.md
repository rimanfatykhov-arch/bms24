# SYSTEM ARCHITECTURE — Implementation evidence v0.33.1

## Flow

```text
implementation_task
  → implementation_evidence.php
  → implementation_evidence service
  → local_kbacorp_impl_evid
  → local_kbacorp_impl_evlog
  → implementation_task_lifecycle
  → task notification
```

## Authority

- `local_kbacorp_impl_task` remains the authoritative implementation action and lifecycle.
- `local_kbacorp_impl_evid` is the authoritative evidence payload/version store.
- `local_kbacorp_impl_evlog` is the evidence decision audit trail.
- Moodle file API stores version files under the system context and evidence record item ID.

## Transaction boundary

Submission writes the evidence payload, files, compatibility fields and task transition in one delegated transaction. Review writes the decision and task transition in one delegated transaction. A failed transition rolls back the evidence state change.

## Compatibility

Legacy task evidence remains in `implementation_task_evidence`; it is displayed read-only and is not silently transformed into a new version.
