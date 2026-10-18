# TEST REPORT — v5.0.35.3

Static checks:
- PHP syntax checked for the complete plugin.
- Moodle ZIP root verified.
- version/install/upgrade verified.
- ZIP integrity verified.

Production acceptance:
1. Open KBA Connect Setup Center.
2. Corporate card must show `Готово` when at least one active pairing exists.
3. Expected details for current production connection: Production / API 1.11 / active connection count.
4. `Открыть Corporate` must lead to corporate_rules.php.
5. Existing Corporate pairing, role sync and course integration logic must remain unchanged.
