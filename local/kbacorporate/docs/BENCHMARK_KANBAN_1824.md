# Kanban 1824 — Performance acceptance

Run on a Moodle clone with realistic masked data (100, 1000 and 5000+ cards). The benchmark CLI checks the read-only query layer but does not measure the HTML rendering. Use a warm cache for median/p95 and collect separate cold-cache results. Compare SQL count and PHP memory, not only wall-clock duration. Profile slow statements with `EXPLAIN` on the actual MySQL server. Verify access controls with two disjoint companies and curator accounts. Never expose SQL debugging in production.

```sh
php local/kbacorporate/cli/benchmark_kanban.php --userid=123 --companyid=456 --runs=10
```
