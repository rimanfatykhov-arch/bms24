# KBA Connect v5.0.34.7 — Enum Recognition + Error Classification Fix

- Corporate role enum recognition now uses XML_ID first and exact normalized VALUE as fallback.
- Correct Bitrix values are recognized even when XML_ID is absent or not returned as expected.
- Enum ID → rolecode mapping uses the same recognition logic.
- Known schema/enumeration errors are no longer falsely reported as `Bitrix24 API is unavailable`.
- Generic network classification no longer treats the mere word `Bitrix` as proof of an outage.
- Corporate page displays the actual Bitrix LIST with enum ID, VALUE, XML_ID and recognized rolecode.
- Legacy unexpected enum values are displayed but never auto-deleted.
