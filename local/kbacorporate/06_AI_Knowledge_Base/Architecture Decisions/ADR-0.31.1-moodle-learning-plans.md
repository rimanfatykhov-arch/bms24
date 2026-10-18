# ADR — v0.31.1 Moodle Learning Plans as standard plan layer

## Decision
Use Moodle core Learning Plan Templates and Learning Plans as the authoritative technical layer for plan/template competency membership. Retain `local_kbacorporate` only for corporate metadata Moodle core does not model.

## Why independent individual plans instead of template-bound plans
A KBA development plan is generated from GAP and can combine requirements from more than one business role. A Moodle plan bound to a single template cannot safely express that case and cannot be edited competency-by-competency. Therefore role profiles synchronise to Moodle templates, while individual GAP plans are independent standard Moodle plans.

## Migration
Add non-destructive link IDs and migrate lazily under an authorised manager session. Do not create core plans/templates during XMLDB upgrade because Moodle competency APIs enforce current-user capabilities and emit events.
