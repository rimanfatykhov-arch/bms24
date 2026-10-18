# Go-Live Criteria

All must be true:

- Production schema ready.
- Production preflight ready.
- Latest controlled REAL Deal 3761 run:
  - completed;
  - 3 / 3 resolved;
  - failed = 0;
  - enrolled = 0 on repeat;
  - already = 3;
  - all required Corporate rows allocated/already_allocated.
- CRM fields ready.
- CRM controlled test:
  - Company updated;
  - Contact updated;
  - Deal updated;
  - timeline created/updated;
  - CRM errors empty.
- Queue enabled.
- Engine mode = v5.
- Allowed stage configured.
- Bitrix24 event points to the generated v5 endpoint.
- Only then inbound is enabled.
