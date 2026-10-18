# AI DECISION LOG — v0.30.2.1

## Decision
Do not change the global Moodle `required` language string. It is used by forms and validation elsewhere.

## Reason
The badge on `bms_tool.php` represents the business attribute “mandatory criterion”, not a missing required form value. Reusing Moodle core `required` produced the misleading label «Необходимо заполнить» even when methodology fields were populated.

## Fix
Introduce plugin-specific language key `bmsmandatorycriterion` and use it only in the BMS Tool criterion list.
