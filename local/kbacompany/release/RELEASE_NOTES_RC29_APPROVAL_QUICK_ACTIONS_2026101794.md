# BMS24 — RC29 build2026101794

## Reviewer queue: quick preview and return

- Show an inline read-only preview for each submitted plan in the manager approval queue: task count, missing metric facts, unresolved corrections, most recent return-comment (up to 400 characters), and links to the task and indicator tabs.
- Keep the existing review/approve/return flow inside the authorised plan review tab; the manager queue does **not** perform any write operations.
- Carry only typed, whitelisted queue-filter parameters, never raw/remote return URLs.
- Show “Back to approval queue” from the review tab and redirect back to the same queue after successful `reviewplan` POST. Maintain week, unit, reviewer, attention, quick filter, pagination, team signal and employee insight selection. The page is clamped if a processed plan disappears from the queue.
- The POST form uses existing `sesskey`, optimistic editing token and access controls. No schema changes or new capabilities.
- Add a release regression gate `cli/check_approval_review_shortcuts.py`.

## Acceptance checks on Moodle

1. Open manager overview, choose a week and unit, set “Needs review” and navigate to page 2 if available.
2. Expand “Quick preview”: verify counts, escaped feedback and task/metric links.
3. Click “Review”: verify review tab and “Back to approval queue”; return and inspect filters.
4. Submit a test plan for changes: verify saved review, removal from submitted queue, and redirect to the filtered overview.
5. Repeat with approval; verify ACL behaviour for a non-reviewer and unchanged queue write boundaries.
6. Check PHP 8.1.34, theme responsiveness, and all relevant Moodle permissions.

**Build**: 2026101794. **DB migration**: checkpoint only. **Live acceptance**: NOT RUN.
