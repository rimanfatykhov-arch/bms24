# Personal review queue — local candidate

The new `/local/kbacompany/competency_review_queue.php` page projects the native
Moodle competency review queue. It is a personal Moodle queue, not a BMS company
registry. Target-user-context Moodle permissions determine the visible requests.
A reviewer with explicitly broad Moodle rights can see requests across companies.
No BMS memberships, HR permissions, corporate course bindings or tenant data are
created or inferred.

The global navigation and navbar expose the queue when requests are available.
Each row retains a separate native competency review action. A related-work
shortcut appears only for one completed-activity evidence record, which must also
be the latest evidence (ID breaks equal-second timestamp ties), in one context
linked to an individual assignment currently recommending that competency for
review. It opens the native Moodle grader for the exact target user. Permissions
and queue status are checked again on click. No request/grade/submission is changed
by the queue or link resolver.

Fallback to native competency review is intentional for absent or multiple
activity evidence contexts, more than 100 evidence records, removed mappings,
hidden/inaccessible modules, inactive enrolment, draft/newer submissions, reopened
attempts, multiple submission records, repeated completion evidence, separate
group restrictions, current grading filters, blind grading, team submissions or
marking allocation. Evidence stores no originating review-request submission ID,
so this is a shortcut to uniquely linked submitted evidence, not proof of which
specific event caused a request. Imported arbitrary evidence URLs are never used.

The UI says "Open related work" and explains that the work may predate a manual
review request. Native evidence has no submission ID, attempt ID or historical
recommend flag. Same-second edits cannot be distinguished by its timestamp.
Durable exact request-to-attempt binding remains a separate architectural debt;
this release must not claim it is implemented. Conservative fallback prevents
known ambiguous/reopened/changed-later cases, not all possible historical edits.

This queue builds on the locally verified 2026110504 baseline. Candidate 2026110601
adds a version-only upgrade checkpoint; schema and Moodle core are unchanged.
No remote commit, stage41 install, production deployment or release readiness
is implied. Local independent QA and
security results must be attached separately. The lifecycle main-only automation
does not certify this isolated non-main baseline.
