# local_kbacompany 1.1.0-rc19 — Communication Center 2.0

Build: `2026101239`

## Scope

This release turns the existing participant-scoped BMS24 Communication Core into a more complete daily-work Communication Center without introducing a second chat/task/decision model.

### Mentions and inbox
- **Unread** and **Mentions** are now first-class Communication Center tabs.
- Unread lists participant-scoped threads with messages newer than the user's read state.
- The projection lists only explicit mentions of the current user from threads where the user is still an active participant.
- Search covers the source discussion title and mentioned message body.
- Private-thread boundaries remain participant-scoped; manager role alone never exposes thread content.

### Discussion files
- A message can now carry one protected uploaded file directly from the send form.
- Files are stored through Moodle File API in the `communication_attachment` area and are served only after the same participant access check as the source message.
- Communication privacy export/delete now includes those protected files.
- Existing reference attachments remain supported; no second attachment table was introduced.

### Message → Task / Decision
- Existing Message → canonical Task conversion remains idempotent and traceable.
- A message inside a canonical Meeting discussion can now create a **proposed canonical Meeting Decision**.
- Decision creation is limited to Meeting-scoped discussions and is replay-safe by a source-message trace in the decision rationale.
- Discussion messages themselves never become tasks or decisions.

### Object history and terminology
- Object discussions show human BMS24 object type labels instead of raw type codes where available.
- The user-facing `statistic` communication context is now labelled **Metric / Метрика**.
- Task, Meeting and Metric discussions expose a direct **Open object** action.
- Object-thread message lists are presented as the object's communication history.

## Compatibility
- No DB schema changes.
- No capability changes.
- Privacy provider changed only to cover the new protected communication file area.
- Existing communication tables, thread identities, notifications, task source bindings and meeting decisions remain canonical.
