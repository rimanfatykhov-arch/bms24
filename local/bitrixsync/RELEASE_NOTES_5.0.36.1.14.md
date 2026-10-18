# Release Notes — v5.0.36.1.14

Confirmed case:
Deal 4023
- Contact 12533 -> Moodle User 36 (mapped)
- Contact 12681 -> edu@kzn.mba (would_create)

The automatic queue previously invoked single-contact `deal_flow_service`.
Now ordinary Deal processing invokes `multi_contact_deal_service`, which processes
every attached Contact.

Targeted/manual queue items that explicitly specify a Moodle userid remain unchanged.
