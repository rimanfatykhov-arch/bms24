# BMS24 release gates

| Stage | Automated checks | Required human evidence |
|-------|------------------|-------------------------|
| Draft | PR context and requirements | Product owner priority |
| Build | PHP syntax, Python gate, upgrade, XMLDB, RU/EN parity | none |
| QA | Domain tests, role/company isolation, regression and UI | Reviewer signs off failures |
| Staging | Real Moodle 4.x, target PHP, MySQL, migration and rollback | Authorized tester |
| Production | Signed artifact/checksum, backups, change window | Product owner approval |
| RC30 promotion | Server acceptance report and manual task/plan checks | Explicit decision |

CI passing means **candidate validated**, never **production ready**.
A development agent must not change checks to manufacture passing results.
Sensitive production logs, data and connection strings must not be included
in AI prompts or repository artifacts.

## Build from clone

```sh
python tools/dev_ai_gate.py
python tools/test_dev_ai_tools.py
python tools/build_install.py --output artifacts/INSTALL_local_kbacompany.zip \
  --manifest artifacts/install_manifest.json
sha256sum artifacts/INSTALL_local_kbacompany.zip
```

The ZIP root is `kbacompany/`. It can be installed by copying that directory
to `moodle/local/kbacompany`. No `.git` or repository-specific workflow files
are placed under the installable plugin.
