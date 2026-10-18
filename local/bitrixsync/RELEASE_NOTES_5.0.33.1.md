# Release Notes — KBA Connect v5.0.33.1

Repair release for installations where v5.0.33 RC1 code was deployed but the pairing database tables were not created. The upgrade is idempotent and safe to run when some or all pairing tables already exist.
