# local_kbacompany 0.7.0.2 — Owner AI Assistance & BMS24 Brand Fix

- Corrects direct App Shell brand labels from `BMS` to `BMS24` on Business Architecture and Workspace surfaces.
- Adds proposal-only BMS Assistant actions to editable Owner guided-workflow fields.
- Owner AI is restricted to the owner/editor path and sends only the current methodological section context, never the full private Owner Frame.
- `businessevidence` uses evidence_check; other Owner section fields use draft.
- AI never writes canonical Owner data directly; applying a proposal only fills the current form field and normal Owner service validation/save remains mandatory.
- Schema-neutral.
