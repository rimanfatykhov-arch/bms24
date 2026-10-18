# Troubleshooting — KBA Connect schema endpoint

If the Bitrix24 corporate roles page reports that the endpoint is unavailable:

1. Confirm the KBA Connect base URL in plugin settings.
2. Confirm the optional integration token if required by KBA Connect.
3. Confirm KBA Connect implements:
   - GET `/corporate/schema/status`
   - POST `/corporate/schema/provision`
4. Do not enter Bitrix24 webhook credentials in Corporate Dashboard.
