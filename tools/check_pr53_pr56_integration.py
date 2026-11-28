#!/usr/bin/env python3
"""Check selective source preservation; not runtime acceptance or deployment approval."""
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
def digest(path):
    return hashlib.sha256(path.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
def check(root=ROOT):
    plan=json.loads((root/'docs/reconcile/pr53-pr56-conflict-map.json').read_text(encoding='utf-8'))
    errors=[]
    if plan['counts'] != {'pr53':59,'pr56':54,'overlap':14,'only_pr53':45,'only_pr56':40}:
        errors.append('conflict map coverage changed')
    # PR57 follow-up: only impossible varchar(20) fixture repaired; assertions retained/extended.
    reviewed_followup = {'local/kbacompany/tests/company_employee_sync_service_test.php': '77fb41119da22be984915d7839dfc9e8352a5a8a7d6da8ca8558e906c062e4bd'}
    # Reviewed stale company/permission literals only; all source/policy assertions retained.
    reviewed_followup['local/kbacompany/tests/company_core_test.php'] = '224a11b3e199a196a6334d23706ab16f8d8d3f5c3e388582a9cb9d3ccaf0116b'
    # ADR-0006 source changes are bound by exact hashes; original parent manifests remain intact.
    reviewed_followup.update({'docs/reconcile/corporate-api126-source.json': 'a1d20c4e5ed07b6018ca05c25198fea697de7062ee7f823c95d5820e363e11de', 'local/kbacompany/classes/local/employee_service.php': '7b81291e7d1fd4987e905e0c67edb1db24780484c128c3039a59b60a2521f237', 'local/kbacompany/lang/en/local_kbacompany.php': '82590f9febf9255ff009f4cbbba64240442d4fe78935caf8d253e57825824a9d', 'local/kbacompany/lang/ru/local_kbacompany.php': 'aa3c2f2597160717bc48070b0803ef4f865a3e413e886bd4918f4135cc869da9', 'local/kbacompany/tests/company_core_test.php': '224a11b3e199a196a6334d23706ab16f8d8d3f5c3e388582a9cb9d3ccaf0116b', 'local/kbacorporate/classes/local/development_plans.php': 'ffe609b47fe2c7e590c4b64599816e2612a5ee46ef3316dfa25376cca31a2415', 'local/kbacorporate/classes/local/erp_exchange.php': '1ed5b8dd2c1c6a11d86882dabee7bc1b9c22cccc1e37c787bf4849469c718c5d', 'local/kbacorporate/db/upgrade.php': '0e3972020a43537c1524b4c8ade694e3b5df5a9c3563c85fdebf02d70a868374', 'local/kbacorporate/tests/erp_exchange_test.php': '2e6833c34401bbb21b10bf998bad968b043b0cb426f4da71f639f7e6c7ee9583', 'local/kbacorporate/version.php': '165bbcf6953d38a0ba716f10969fdcb0d0f57a3e2f6ef095994df71b4e69f342', 'tools/check_corporate_api126.py': '8cd273417f3a75dd1955b8ec5f30285ef527d45767afdd92c3fc3d151e26bf92'})
    # RC30 S14: recommendation regressions added; existing assertions retained unchanged.
    reviewed_followup['local/kbacorporate/tests/course_recommendations_test.php'] = 'dfab254c565758b7a7dabd984ec3c8b196648223697494f673c3313f89a90ec1'
    # RC31 Pilot 02 (#79, PR #81): reviewed attachment fix in the Communication Center and task discussion.
    reviewed_followup.update({'local/kbacompany/classes/local/communication_service.php': '015aeedf79583a2f484b2695a644c85043cec3f7e180074eb6fa9326a0d8f826', 'local/kbacompany/lang/en/local_kbacompany.php': '8ab7b992158461cf8f495e3e053544f306d0e1a9901630ec9cc9ba78ac50d377', 'local/kbacompany/lang/ru/local_kbacompany.php': '740d13d4f93f39c538c218bf4877995f294697ed4c570f767a9ed8cbda9a095d', 'local/kbacompany/styles.css': '90343cc003d3921c72d45f8414cfc79651007d2ff380d9afa771a67e964f532e', 'local/kbacompany/tests/communication_service_test.php': '992e1265a9f4832d7a6a6e223b06b57a0a0da0d4d353a8f4bd71411168d9eef8'})
    # PR102: exact multi-attachment/UI follow-up reviewed against PR100.
    # See docs/reconcile/PR102_SOURCE_PRESERVATION.md; no blanket path exemption.
    reviewed_followup.update({
        'local/kbacompany/classes/local/communication_service.php': '8310d2087d70b6ef141af73cec3879ee021f5245e3d68b12cd147cc76b8a0a46',
        'local/kbacompany/lang/en/local_kbacompany.php': '0494fad97271909666a779281d1eb25944b8cc9bf55e1df5ff73f356922c8dc1',
        'local/kbacompany/lang/ru/local_kbacompany.php': '6dd439078e4a5afa7d409d162a09bf008795d7e5336ab72a80cc59b7de3bc274',
        'local/kbacompany/styles.css': '1eeea0a3ff0c5ab3911aa298b5e540b52d2ced6fe5728e08a13c61cbe75cdcfd',
        'local/kbacompany/tests/communication_service_test.php': 'd8d27f74f3caa377a01ac0dc6072e7cadb2368532c0bf077014fa35dcaef95bd',
    })
    # Task opened event (after #103): exact RU/EN string update, see docs/reconcile/TASK_OPEN_SOURCE_PRESERVATION.md.
    reviewed_followup.update({
        'local/kbacompany/lang/en/local_kbacompany.php': 'cf9761113aa0dd206c0c52ed9b3cdb13aebe2be2b51bf91cd5c55cac0fd18ff4',
        'local/kbacompany/lang/ru/local_kbacompany.php': '77808151a0f34400a2ae8868ddae57b703e8dfda4a15b0f8ddd2710eadb76d06',
    })
    # Daily summary after published #110: exact append-only RU/EN extension.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '35f7107fb19132298ef3a922bcf6b26bed0d794b685ea0c0f19e38d45217f8d0'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '5088d5ad0e296bb509a1156e561502cb63208eecca97d7ae03ba8870046c0546'
    # Communication Center cleanup (after #103): exact reviewed updates, see docs/reconcile/COMMUNICATION_CENTER_CLEANUP_SOURCE_PRESERVATION.md.
    reviewed_followup.update({
        'local/kbacompany/classes/local/communication_service.php': 'ecc24db60b9dacfd66be77ec7664895ef34e67b476f34387d656cfe07004e41d',
        'local/kbacompany/lang/en/local_kbacompany.php': 'b7bb8afbfa9ee3a0277be52c937868b29f013560f1e38428e7cc7f831fc5ee2a',
        'local/kbacompany/lang/ru/local_kbacompany.php': 'd89845b085cef3177a7b639ebd2e194be4662f40adb417706026f9b6d17c76db',
        'local/kbacompany/styles.css': '0cbc4209807f463b30934f1f8642adfc3d50ecd52b86ba5fac0e018a48228b30',
    })
    # Daily reports R0 (ADR-0009, after #114): exact append-only RU/EN navigation names; no schema, older keys unchanged.
    # See docs/reconcile/DAILY_REPORTS_R0_SOURCE_PRESERVATION.md.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = 'dcbc042a4602de76173add94c8b936668a568f3681f0ca41291ead1f52afffde'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '53feac38fc2986c052d52868e5dcac147f973daf2a292af818fe7752d76468c6'
    # Daily reports R1 (ADR-0009): saved report strings appended after the R0 block; see DAILY_REPORTS_R1_SCHEMA.md.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = 'efe44c09bfa2559779ad69aca6645e9a1c3a56477a858041fb5826184f452a02'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'f661b9d42a53e6b78020d352fbed6f0ea4ccad46d11596782e466895b1840208'
    # Daily reports R2 (ADR-0009): review strings appended after R1; two notification kinds added to the canonical
    # Communication Center allowlist only. See docs/reconcile/DAILY_REPORTS_R2_REVIEW.md.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = 'bc4d02332d0e930526ca7d16cd8de6a394886ab172f8a0ec6afb036be393fd69'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'bb64599ec5f4ed72c875d2a9f37bcf077344173e2c968530d8eff151ac4c185c'
    reviewed_followup['local/kbacompany/classes/local/communication_service.php'] = '877ef95b6afc40797a7bbb19522d49d1c93bf0bac04cf5a1bc2d65df44eec3e6'
    # Communication Center E2 (after daily reports R2): exact reviewed updates, see docs/reconcile/COMMUNICATION_CENTER_E2_SOURCE_PRESERVATION.md.
    reviewed_followup.update({
        'local/kbacompany/classes/local/communication_service.php': '7433c0f6d21133e38de79f05da131daafda7bc245769225d973b8bdf69bf177a',
        'local/kbacompany/lang/en/local_kbacompany.php': '313dacec7726f2052466bb56c956411b31b5b9d28e6267aed422130d72df5e41',
        'local/kbacompany/lang/ru/local_kbacompany.php': '347f6b5b8f66b2c5b089a423ef1633730349f70c04bd41093df8c8aeeb883708',
    })
# ADR-0005 fix (#124 P1): local Corporate install.xml DEFAULT 0 and upgrade step 2026111004, both identical to
    # the Corporate2613 package; inventory updated. See docs/reconcile/CORPORATE_FRESH_ADR0005.md.
    reviewed_followup['docs/reconcile/corporate-api126-source.json'] = '95f5c6fd853b1167a6be761617bca56423ee93d65d6ac31600b534c0a3240415'
    reviewed_followup['local/kbacorporate/db/upgrade.php'] = 'ef39434356106043292de20bf355165961ca07462e9ead6a79f19bbc4b6d5019'
    reviewed_metadata={'AGENTS.md','docs/00_MASTER_CONTEXT.md'}
    for name, expected in plan['unchanged_schema_hashes'].items():
        # ADR-0009 R1 appends five additive daily report tables, ADR-0010 two more (dreportcc, dreportset);
        # every earlier table definition is unchanged.
        # R5a appends three more (rptcalendar, dreportoff, dreportabs); see daily-reports-r5a-source-preservation.json.
        target = {'local/kbacompany/db/install.xml': '7ab700a0459af874deaa2bef392636bd07b5a788db59d3c50773e7a54ef9dedf'}.get(name, expected)
        if digest(root/name)!=target:
            errors.append('shared schema/helper changed: '+name)
    # UX-1: exact append-only language/scoped CSS follow-up over E2.
    reviewed_followup.update({'local/kbacompany/lang/en/local_kbacompany.php': 'eac98f182ea03d8332608ede1ad216c2fbde9398634653cb3a2e65eb0bfe890b', 'local/kbacompany/lang/ru/local_kbacompany.php': '6e5cb24b46f5c918bd4d48b50f6ad6f2c6a231a423490374a32481479da268bf', 'local/kbacompany/styles.css': 'cd2d2163a340a79e0b4766e90d63aad3b39a31d334fa4430c6eee686200e4e7f'})

    # #145 (2026-11-21): Communication Center rendered through Mustache templates. Presentation only:
    # RU/EN strings appended at the end of each language file, one scoped CSS alignment rule appended.
    # No schema, no ACL, no runtime behaviour change. See
    # docs/reconcile/COMMUNICATION_CENTER_145_SOURCE_PRESERVATION.md.
    reviewed_followup.update({
        'local/kbacompany/lang/en/local_kbacompany.php': 'ffa8c3cc82b19e1e15fdc12f5ec901a6621a7a92abed5f2097ec6e97f71bf348',
        'local/kbacompany/lang/ru/local_kbacompany.php': '0ff5746d50f5cfc84b13d582f210e6262549973ebd7d08f4667262edaf2cb46b',
        'local/kbacompany/styles.css': '95563c9160349cb2f40047f092e78e77c446ee6113d48d13456250dfef271417',
    })
    # ADR-0010 (owner decision 2026-11-21): reviewer by org chart, self review at the top, manager visibility,
    # report copies. RU/EN block appended after #145; one notification kind added. See
    # docs/reconcile/DAILY_REPORTS_ADR0010.md.
    reviewed_followup.update({
        'local/kbacompany/lang/en/local_kbacompany.php': '8ca0f2edf94a7675402634d986f75fa6968ac557e9c68e43d7864ba3e94b9bae',
        'local/kbacompany/lang/ru/local_kbacompany.php': '0698c4976b6364b07fb124700624ff30023df5356f7260dab0260f60641320ac',
        'local/kbacompany/classes/local/communication_service.php': '6a5de10a764edc99864aee634ec4b9ecbe432c7a76e4dfcbc5d60b81b6874b40',
    })
    # Day feed -> report draft (owner task 2026-11-23, first scenario: task results only). RU/EN block appended after
    # ADR-0010; no schema, version, capability or notification change. See docs/reconcile/DAY_FEED_REPORT_DRAFT.md.
    reviewed_followup.update({
        'local/kbacompany/lang/en/local_kbacompany.php': '07f9ed6af27a2030cff81ddd90c70995d667598ff4625c24c6ebd0dcad6a782f',
        'local/kbacompany/lang/ru/local_kbacompany.php': 'd456cdb1ccfcb2910d3fef22a72b12304121bd2246ea871e5508e8890daf82bb',
    })
    # #156: append bell strings and the notification feed to the published #158 tree.
    reviewed_followup.update({
        'local/kbacompany/classes/local/communication_service.php': 'f3c7ab27e4da9001673e6c60a72b18b8e5af604068b15b349c5c01a45de3ad05',
        'local/kbacompany/lang/en/local_kbacompany.php': '2d2686ca949a0e25b586d30bf639ccbadd4979c0d341d2ba34258f5136a9b2fb',
        'local/kbacompany/lang/ru/local_kbacompany.php': 'ecb4d6bbaf07e37e750532287a75827e3f705667cb3dfd22a74f6d915808c3d8',
    })
    # #128: append the adaptive navigation labels after the integrated bell.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '796443e2f78e013e5a45d4782a2cba51c89193d87f17d2104fb684228560a14b'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'c6f620c5aa0410fdaf067a6750d8e4cc4e847c2cdfd145b023a6fff975ad5dde'
    # Day feed "Added earlier" (owner task 2026-11-24): two RU/EN keys after the navigation block; display only.
    # See docs/reconcile/DAY_FEED_ADDED_EARLIER.md.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '9495d7e1467dbe67c7cb18be14cc4b19a3d33a40e632d528f77fb08788dbc803'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'dc4772de7d84d31a80927bb13c8f97966f18ca9db9f4e8d759d3204365255226'
    # Daily reports R4 (owner decisions T1-T5, 24.11.2026): append the team-for-the-day block after "Added earlier" (#165).
    # See docs/reconcile/daily-reports-r4-source-preservation.json.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = 'ff960b76019f947c522bcc2765e1247baa0cfbbd4db5e7de20c0531bd535f07d'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '6075cfd77ddcbf01f33d00a5501dda3c162d2ebe6ef49d52113b7c6ab6f805f5'
    # ADR-0011 block 1: monthly summary of daily reports (read-only, no schema). RU/EN block appended after the
    # R4 "Team for the day" block (#168). See docs/reconcile/daily-reports-summary-source-preservation.json.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '9a3fa3efb85ef6f9aeaf18202ba4377bf418787c4631b636dbf766f7a45c2f88'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '81334038357d7bc9f79a2dfefca9c8022c088045919931fc54e78a939b82a858'
    # Daily reports R3 (owner decision 24.11.2026): append the report discussion block after the ADR-0011 summary (#173) and register the
    # 'dailyreport' discussion kind. See docs/reconcile/daily-reports-r3-source-preservation.json.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '2a8007f42e8de2339927da3be2dcdc5aecf91534a634b5bd2bd21dadf17402eb'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'e2e51490b8c1f5988948b057ba93701ee4229bbc4ed9e0175da02f0a655fcfc9'
    # Information Centre by divisions (#190): append the infocenter2_* block after the daily reports R3 block.
    # See docs/reconcile/information-centre-divisions-source-preservation.json.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '1d3d80ad9c985ff85091e126a0e14292ce3e6ab4531268f6f30228c47269509b'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'f5ad4bdcc8edaebe4b8b444231432c117fedbb3530fc9b04a3c3316d85015c87'
    # DOC-W2 Office workspace (owner task 2026-11-24): officews_* RU/EN block after the Information Centre by divisions block (#190); read-only, default off.
    # See docs/office/DOC_W2_OFFICE_WORKSPACE.md.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '32402ab956d141c1cbb1b45c495cf8ddc2c6c95d09518995dba2c1473dfbc5cf'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = 'd5d534758b1c6c6cef4069d1dd6cbe233b9a6973a49f48c0ebc3f781ec51177f'
    reviewed_followup['local/kbacompany/classes/local/communication_service.php'] = '870b471dba39c867b5b13f8b04f55fb82f67ce484ea44cf929b5b9c4f93dae92'
    # Daily reports R5a (owner decisions K1-K6, 24.11.2026): append the reporting calendar block after the DOC-W2 Office block (main).
    # See docs/reconcile/daily-reports-r5a-source-preservation.json.
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = '62af4834f4441502e5ebc4b535b508d3369f46b35f61aca9a2f6edf8e77e76e6'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '02c53ab3bafd6aa9fab2c2f8fd4475456a32cb2d96f17b7d9c7fc01179bc730f'
    # Terminology BMS-STD-TER-001 (#187, owner approval 2026-11-25): exact in-place RU/EN value replacements, same keys.
    # See docs/reconcile/terminology-cascade-std-source-preservation.json (line-by-line before/after).
    reviewed_followup['local/kbacompany/lang/en/local_kbacompany.php'] = 'e0d49c73c41b137f6d6927eda8afc128c30a0b30ac5917c24a443f201ca9bf47'
    reviewed_followup['local/kbacompany/lang/ru/local_kbacompany.php'] = '07df4610a4549fe8a08b92107cdefb480796e15fe40ee371a4e36c2286cfa0e4'
    for row in plan['rows']:
        path=root/row['path']
        if not path.is_file() or path.is_symlink():
            errors.append('missing/unsafe source path: '+row['path']); continue
        origin={'select-pr53':'pr53_sha256_lf','preserve-pr56':'pr56_sha256_lf'}.get(row['resolution'])
        if origin and row['path'] not in reviewed_metadata and digest(path)!=reviewed_followup.get(row['path'], row[origin]):
            errors.append('parent source lost: '+row['path'])
    notes=next(r for r in plan['rows'] if r['path']=='local/kbacompany/release/RELEASE_NOTES_RC29_build2026110902.md')
    if digest(root/notes['path'])!=notes['pr56_sha256_lf'] or digest(root/'docs/reconcile/PR53_RELEASE_NOTES_0902_HISTORICAL.md')!=notes['pr53_sha256_lf']:
        errors.append('one historical0902 release note was lost')
    upgrade=(root/'local/kbacompany/db/upgrade.php').read_text(encoding='utf-8')
    versions=[int(v) for v in re.findall(r'upgrade_plugin_savepoint\(true,\s*(\d+)',upgrade)]
    if len(versions)!=487 or versions!=sorted(set(versions)) or versions[-5:]!=[2026111100,2026111600,2026111601,2026112301,2026112400]:
        errors.append('merged upgrade chronology/identity changed')
    driver=(root/'tools/tests/moodle_acceptance.php').read_text(encoding='utf-8')
    for ref in [plan['pr53_sha'],plan['pr56_sha']]:
        if ref not in driver:
            errors.append('missing exact0902 upgrade origin')
    for suite in ['competency_review_queue_test','employee_development_courses_test',
                  'task_rc30_acceptance1864_test','task_rc30_cli_response1866_test',
                  'task_rc30_report_comparison1867_test','rc30_integrated_access_test']:
        if suite not in driver:
            errors.append('missing focused suite: '+suite)
    return errors
if __name__=='__main__':
    errors=check()
    print(json.dumps({'status':'FAIL' if errors else 'PASS','errors':errors,
        'scope':'parent source preservation and acceptance coverage, not release approval'}))
    raise SystemExit(bool(errors))
