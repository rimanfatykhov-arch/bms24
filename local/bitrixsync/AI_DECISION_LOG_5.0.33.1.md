# AI Decision Log — KBA Connect v5.0.33.1

Decision: use an idempotent repair migration at a new Moodle plugin build instead of creating tables manually in phpMyAdmin.

Reason: Moodle had already recorded build 2026083300, so the original migration would not run again. A new build guarantees the repair path executes through the supported Moodle upgrade mechanism.
