# Acceptance — 1.1.0-rc19 Communication Center 2.0 — build 2026101340

1. Open **Communication Center** and confirm tabs include All, Inbox, Unread, Messages, Discussions, Mentions, Notifications and Meetings.
2. Confirm the desktop workspace has discussion list → message history → context/actions, and collapses to a single column on a narrow/mobile viewport.
3. Create a direct conversation and a group conversation. The normal form must not expose technical `contextkind/contextuuid/contextrevision` fields.
4. Send a new message from another participant and confirm the source thread appears under **Unread** until it is opened.
5. In a group/object discussion mention another active participant and confirm that participant receives a mention notification and sees the discussion under **Mentions**.
6. Confirm a non-participant cannot be mentioned and cannot open a private direct/group thread by URL.
7. Send a discussion message with one uploaded file and confirm the file is shown under the message and downloads only for an active participant who still has access to the source object.
8. Confirm a company manager who is not a participant cannot download a private direct/group file by direct pluginfile URL.
9. Open a Task, Meeting, Document and Metric card and enter its object discussion. Confirm the UI shows a user-facing BMS24 object type/name, never the technical UUID.
10. Confirm one canonical object has one active communication history. A second user who can view the object can join the same history; a user without object access cannot discover/join it.
11. Revoke access to a private Task/Meeting/Document and confirm the old discussion, its notifications and protected files no longer reveal the object or content.
12. Convert a message into a Task and confirm the Task keeps `sourcekind=communication` and the source message UUID; repeated conversion must not create a second Task.
13. For an object context supported by the canonical Task model, confirm Task creation preserves the source object context instead of inventing a parallel link model.
14. Open a Meeting discussion and use **Propose decision** on a message. Confirm a canonical proposed Meeting Decision is created and appears in the Meeting; repeat the same action and confirm no duplicate decision is created.
15. Confirm **Propose decision** is not available on non-Meeting discussions.
16. Confirm structured mentions, attachments and linked Task actions remain visible in chronological **Communication history**.
17. Confirm old GET links to `communications.php` redirect to Communication Center 2.0 without losing thread/convert context.
18. Exercise privacy export/delete for a user who authored a discussion file and confirm the file is exported/deleted with the communication message.
19. Verify Company isolation with a second Company UUID, including an attempt to use another company’s object UUID.
20. Verify mobile/tablet layout of the thread list, send form, attachment input, mentions selector and message actions.

Live Moodle acceptance remains **NOT RUN** until this package is installed and tested on the target server.
