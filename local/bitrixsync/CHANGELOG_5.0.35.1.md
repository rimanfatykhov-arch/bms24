# KBA Connect v5.0.35.1 — Corporate Company Mapping Fix

- Fixed Corporate role sync using the external Bitrix24 Company ID as a local Corporate Dashboard companyid.
- `sync_employee()` remains the authority that maps external Bitrix company to local Corporate company.
- Role MERGE now requires an explicit local Corporate companyid returned by the bridge.
- If local companyid cannot be resolved, role merge is skipped safely instead of writing to the wrong company.
- Corporate diagnostics now show both Bitrix Company ID and Corporate Company ID.
- Contact profile sync history stores both identifiers and the employee-sync response envelope for diagnosis.
