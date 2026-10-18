# KBA Admin — unified Moodle course element settings

Version: 1.0.0  
Target: Moodle 4.0+ (`requires = 2022041900`)

## What it does

Adds **Site administration → Plugins → Activity modules → Course elements — KBA** and renders these native Moodle settings pages inside one KBA-styled page:

- `modsettinglabel` — Text and media / Label
- `modsettingfolder` — Folder
- `modsettingassign` — Assignment

The plugin does **not** copy or replace Moodle settings. It obtains the native `admin_settingpage` objects, renders them with `output_html()`, and saves posted values with `admin_write_settings()` — the same core mechanism used by `/admin/settings.php`.

## Install

1. Upload the `kbaadmin` folder to:
   `local/kbaadmin`
2. Open Moodle as an administrator.
3. Go to **Site administration → Notifications** and complete the plugin installation.
4. Purge caches:
   **Site administration → Development → Purge caches**.
5. Open:
   `/local/kbaadmin/modules.php`

## Files

- `version.php` — plugin metadata
- `settings.php` — adds KBA page under Activity modules
- `modules.php` — unified page and saving logic
- `styles.css` — KBA visual layer
- `js/modules.js` — cards, accordion and search
- `lang/ru/local_kbaadmin.php` — Russian strings
- `lang/en/local_kbaadmin.php` — English strings

## Adding another module later

Add another entry to `$registry` in `modules.php` with its native admin settings section, for example `modsettingpage`, `modsettingurl`, `modsettingquiz`, etc. The page will only render entries that resolve to an accessible `admin_settingpage`.

## Safety

No Moodle core files are modified. Removing `local/kbaadmin` restores the original Moodle administration UI with no changes to the underlying module settings.
