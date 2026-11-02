# RC acceptance addendum — 1.0.0-rc2

In addition to the rc1 acceptance contract, verify:
1. `ofs.php?mode=current` displays the canonical live structure.
2. `ofs.php?mode=design` displays the selected OrgDesign project through the same renderer.
3. A draft project and current OFS are clearly labelled as different states of one workspace.
4. Legacy `ofs_designer.php` GET links land in Design mode without losing the selected project UUID.
5. Existing legacy text CKP values remain visible after upgrade.
6. Canonical company/node Product bindings save only inside the selected company and valid target.
7. Compare correctly distinguishes added, changed and removed nodes.
8. Check reports structural issues and unformalised products without mutating live data.
9. Existing approved/apply workflow remains available through `designs.php`.
10. Current OFS Product/metrics/internal-customer sections remain functional.
