# Battle Plan Approval — 0.8.6.2

Submitted Battle Plans can now be reviewed using existing canonical tasks and indicators. The reviewer sees task products, plan/fact time and weekly indicator facts/quotas, then either approves the plan or returns the whole plan/specific tasks for correction. Review events are append-only in `local_kbacompany_bplanrev`; approval still activates the existing Battle Plan and correction returns it to draft. No second task, KPI or Battle Plan engine is introduced.
