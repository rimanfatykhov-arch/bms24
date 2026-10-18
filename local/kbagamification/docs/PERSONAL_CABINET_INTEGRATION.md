# Personal Cabinet integration

KBA Gamification 1.6.3 exposes:

- `\local_kbagamification\api::get_personal_cabinet_widget_data($userid)`
- `\local_kbagamification\api::render_personal_cabinet_widget($userid)`

KBA Theme 2.2.5 calls the renderer defensively. If the plugin is missing or disabled, the Personal Cabinet continues to work normally. No direct database access from the theme is used.
