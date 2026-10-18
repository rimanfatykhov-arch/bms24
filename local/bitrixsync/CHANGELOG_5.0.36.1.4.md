# KBA Connect v5.0.36.1.4 — Catalog Diagnostics DB Fix

- Fixed the diagnostic page's `Ошибка чтения из базы данных`.
- Removed the fragile multi-table SQL JOIN from Catalog Binding Diagnostics.
- Diagnostics now inspect Moodle courses and KBA mapping tables independently.
- Mapping fields are probed with Moodle XMLDB before they are read.
- Adds database schema visibility to the debug JSON.
- Catalog synchronization logic itself is unchanged.
