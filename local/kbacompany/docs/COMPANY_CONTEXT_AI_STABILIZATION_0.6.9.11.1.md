# BMS 0.6.9.11.1 — Company Context & AI Stabilization

Build `2026092206`.

This patch stabilizes the 0.6.9.11 Company Philosophy / Market checkpoint without changing canonical ownership or schema.

## UX / AI hardening

- BMS AI bridge now whitelists supported fields per domain in addition to domain and operation.
- AI AJAX errors no longer expose raw exception messages to the browser; detailed messages remain developer diagnostics only.
- Philosophy and Market AI panels explicitly state the proposal-only rule: applying a proposal only fills the form; canonical BMS data is written only after the user saves through the domain service.
- `local_kbaai` remains optional. Company Philosophy and Market CRUD continue to work when the AI companion is absent.

## First read-only data adapter

Provider: `rosstat_open_data`.

- Uses the official Rosstat Open Data catalogue `https://rosstat.gov.ru/opendata` as a read-only discovery source.
- No undocumented provider API endpoint or query parameter is assumed.
- The adapter fetches the catalogue and filters candidate dataset links locally.
- Returned provider data is tagged as a `DATA` evidence candidate and never becomes canonical Market data automatically.
- The Market DetailView lets an authorized user search the catalogue and copy a selected official source into the normal Market Evidence form.
- Copying a source does not save evidence; the user must review the evidence fields and submit the canonical form.
- Provider links are restricted to official Rosstat hosts.
- The external query is executed only after the BMS Company context boundary has been resolved server-side.

## Architecture preserved

`AI proposes → user applies → domain service validates → BMS writes` remains unchanged.

Theme ownership, Company UUID isolation, exact revision pinning, Stable Code semantics, finance/ERP boundaries and provider-neutral gateway ownership remain unchanged.
