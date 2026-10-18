# AI Decision Log — v5.0.34.7

Observed production state:
- Bitrix REST and userfield API are healthy.
- Four correct role display values exist.
- Role status was still 0/4 because recognition relied only on XML_ID.
- Schema verification errors containing the word Bitrix were falsely classified as an API outage.

Decision:
- recognize by stable XML_ID first;
- exact display VALUE fallback second;
- classify known schema errors before network errors;
- expose the actual enum LIST instead of hiding it.
