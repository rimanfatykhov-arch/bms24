# Security and Company Isolation

## Security boundary

A shared Moodle hosts multiple BMS companies.

Canonical boundary:
`Company UUID / resolved Company context`

## Mandatory scoping

Company scope must apply to:
- DB queries
- search
- autocomplete
- exports
- files
- cache keys
- API snapshots
- scheduled jobs
- notifications
- analytics
- diagnostics
- integration events
- AI context

## Forbidden pattern

```php
$rows = $DB->get_records('table');
$rows = array_filter($rows, fn($x) => $x->companyuuid === $companyuuid);
```

This is not an acceptable isolation boundary.

## Required pattern

Apply company constraint inside repository/SQL before returning records.

## Capability is not enough

A user capability check does not replace company scoping.
Both must be satisfied where applicable.

## External integrations

Never expose:
- secrets
- webhook tokens
- OAuth secrets
- raw credentials

Provider URLs shown in diagnostics must be sanitized.

## IDs

External provider IDs are scoped mappings.
Canonical identity remains BMS UUID.

## Files

Moodle file storage usage must preserve:
- component
- filearea
- item context
- company authorization before retrieval

Never rely only on obscurity of a file URL.
