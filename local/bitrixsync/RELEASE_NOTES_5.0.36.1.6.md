# Release Notes — v5.0.36.1.6

Confirmed production state:
- 17 products mapped to iblock 25;
- product section IDs = 0;
- category mapping rows exist but Bitrix section IDs = 0;
- the corresponding Bitrix sections are visible in the CRM catalog.

This release repairs local category mappings against existing sections first.
It intentionally avoids creating new sections during repair to prevent duplicates.
After repair, run the normal catalog synchronization once to bind products to the recovered sections.
