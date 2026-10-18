# Release Notes — v5.0.36.1.12

Confirmed code-level root cause:
automatic queue processing calls `deal_flow_service`, while access delivery was wired only
into `multi_contact_deal_service`.

Therefore a newly created Moodle account could be enrolled successfully but skip the
entire credentials delivery step.

Production validation after installation:
1. Use a NEW Bitrix Contact/email.
2. Process an allowed Deal stage.
3. Verify Moodle user creation.
4. Verify password email.
5. Verify Contact Timeline comment.
6. Verify Deal Timeline comment.
7. Review `Шаблоны сообщений -> Диагностика выдачи доступа`.
