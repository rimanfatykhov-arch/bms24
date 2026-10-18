# ADR v0.14.5 — Integration Health Center

Decision: add a read-only health aggregation layer before introducing HR write workflows. Bitrix24 health is checked only through KBA Connect. Optional BBB/Custom Certificate absence is warning; broken core DB/API write safety is critical. Persisted health history must not contain secrets.
