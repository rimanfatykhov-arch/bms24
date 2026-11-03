#!/usr/bin/env bash
# Retired: duplicate HTTP Basic Auth is no longer part of the BMS24 parity41 design.
# Deliberately refuse activation from the older script to avoid conflicting Nginx setup.
echo "RETIRED: use stage1897_https_no_extra_password.sh from fix/1897-remove-basic-auth" >&2
exit 2
