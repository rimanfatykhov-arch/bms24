# KBA Corporate Dashboard 0.27.5

This release makes the BMS implementation journey unambiguous by separating its commercial stage from its managed delivery plan.

- “Package and request” opens package selection when there is no request.
- An existing request opens its administrator card for processing, while the client sees its status in the implementation workspace.
- “Plan and tasks” opens only after the program is paid and active, paused or completed.
- A direct plan URL entered too early returns to package selection or request processing.
- The request card no longer repeats the plan button.
- Audit/program cards open package selection, request processing or implementation tasks according to the actual state.

Build: `2026090283`.

After installation, complete the Moodle upgrade and purge all caches. Test one company without a request, one with a pending request and one with a paid active program. Verify that step 2 always opens the commercial stage and step 3 opens only the eligible plan.
