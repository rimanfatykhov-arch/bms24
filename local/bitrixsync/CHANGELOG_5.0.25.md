# KBA Connect v5.0.25

## Bitrix24 Funnel & Stage Selector

Added:
- real Bitrix24 funnel discovery;
- real deal-stage discovery;
- grouped stage selector by funnel;
- cached funnel snapshot;
- refresh button;
- selected stage summary;
- CLI funnel diagnostic.

Changed:
- removed manual STAGE_ID text entry from normal admin workflow;
- Bitrix24 page links to the stage selector;
- Automation page uses real Bitrix24 checkboxes;
- Essential settings no longer asks administrators to type stage IDs;
- Setup Center now sends the administrator to stage selection.

Compatibility:
- existing `v5_allowed_stages` storage and deal stage filter remain unchanged.
