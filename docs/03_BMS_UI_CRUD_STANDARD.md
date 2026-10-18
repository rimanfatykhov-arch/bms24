# BMS CRUD & Record Interaction Standard

## Goal

BMS screens should feel like one management application, not unrelated Moodle pages.

## Canonical record page

Recommended structure:

1. App Shell
2. Breadcrumb / context
3. Record header
   - human-readable title
   - status
   - owner/responsible where relevant
   - compact key metadata
4. Primary actions
5. Domain tabs
6. Main content
7. History / audit / related objects where relevant

## Human-readable UI

Never expose raw internal field keys as labels when a human label exists.

Bad:
`owneruserid`, `targetuuid`, `duedate_ts`

Good:
`Ответственный`, `Связанный объект`, `Срок`

## Record actions

Separate:
- view
- edit
- archive/delete
- lifecycle transitions
- domain actions

Do not overload one button with hidden state changes.

## References

Clickable people, positions, processes, projects and other canonical records should open their canonical record card where permissions allow.

## Forms

Use:
- clear sections
- required indicators
- contextual help only where useful
- domain validation messages
- preserved values after validation error
- consistent Save / Cancel behavior

## Empty states

Explain:
- what is missing
- why it matters
- what action is available

## Tables/lists

Support appropriate:
- search
- status filters
- responsible/owner filters
- pagination
- stable sorting
- company scope

Do not load all companies and filter client-side.

## Mutations

Every write must go through canonical domain validation.
UI must not write tables directly.

## AI assistance

AI suggestions appear as proposals/drafts.
User confirmation is required before canonical write.

## W2/W3 UI rules (build1642)

Under opt-in `owned` mode use BMS24 scoped Mustache/CSS from the plugin; render models are computed by canonical services. Retain both `legacy` and `owned` UIs while parity is unverified in staging. Do not rename POST/GET parameters, task field `name/id`, revision, sesskey, AJAX hooks or company scopes for purely visual work. Scoping for new styles: `.bms24-app` only; test KBA Theme and Boost.
