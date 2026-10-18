# AI DECISION LOG — v5.0.36.1.16

1. KBA Connect remains the only owner of Bitrix24 REST.
2. local_kbacompany v0.5.16 is consumed through its internal PHP integration contract and durable outbox.
3. local_kbacorporate remains a separate bounded context and is called only through its public bridge adapter.
4. BMS Company admission requires an explicit Bitrix Company boolean field `KBA — Клиент BMS`.
5. Course enrolment remains Deal-driven; BMS employee sync never creates a course enrolment.
6. Existing `local_bitrixsync_company` mapping is reused to adopt legacy Corporate companies without changing historical BMS company identity.
7. New bridge feature is disabled by default for production-safe rollout.
