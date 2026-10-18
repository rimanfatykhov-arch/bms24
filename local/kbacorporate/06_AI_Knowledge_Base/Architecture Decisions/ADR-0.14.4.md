# ADR — v0.14.4 KBA Connect Pairing

Corporate Dashboard does not own Bitrix24 credentials. Pairing is performed through KBA Connect with an administrator-generated one-time code. The code is never persisted. The persistent token is an internal Dashboard ↔ Connect secret only. Existing manual tokens remain supported for backward compatibility.
