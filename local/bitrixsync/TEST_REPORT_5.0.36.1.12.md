# TEST REPORT — v5.0.36.1.12

Static:
- all PHP files syntax checked;
- full Moodle ZIP integrity/root checked;
- version/install/upgrade checked.

Required production test:
- new contact, never-before-created Moodle user;
- valid email;
- auto_create_users = ON;
- access_delivery_enable = ON;
- accesschannel = moodle;
- timelineenabled = ON.

Expected:
- `access.status = sent`;
- `email_status = sent`;
- `timeline_status = written`;
- Contact and Deal Timeline receive a comment without password;
- recipient receives temporary password email;
- first Moodle login requires password change.
