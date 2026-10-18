# AI Decision Log — v0.29.6.2

## Decision
Keep one shared navigation renderer (`local_kbacorporate_implementation_navigation`) rather than custom menus per page.

## Reason
The previous release changed documentation/version metadata but did not materially change the shared menu. A single shared renderer prevents divergence between `implementation.php`, audits, tools, framework, task and analytics pages.

## Rejected alternative
A menu only inside `implementation.php` was rejected because users would lose the same orientation after opening another BMS page.

## Scope protection
Only navigation markup, labels, active-state routing and CSS were changed. Audit, mapping, task and analytics business logic remain untouched.
