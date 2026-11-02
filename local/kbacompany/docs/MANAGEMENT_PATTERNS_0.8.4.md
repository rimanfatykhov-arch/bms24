# BMS 0.8.4 — Recurring Problems & Management Patterns

## Purpose
Turn the existing append-only management decision history into a descriptive recurrence view without introducing a second problem, task or knowledge engine.

## Canonical sources
- Indicator Registry
- Management Exceptions / state events
- Canonical BMS Tasks
- Management Decision History

## Pattern definition
A recurring pattern is two or more management decisions recorded against the same canonical indicator/context binding.

This is only a management signal. It does not prove:
- the same root cause;
- causality between an action and a later fact;
- effectiveness or ineffectiveness of a prior decision.

## Signals
The view highlights:
- recurring cases;
- decisions with no linked action;
- recurrence after an earlier completed action.

## Architecture
Read-model only. No schema, ACL, services or privacy changes.
