# KBA Connect v5.0.21 — Queue Manager & Retry System

Added:
- queue status monitoring;
- retry policy;
- failed processing preparation;
- queue administration.

States:
PENDING
PROCESSING
COMPLETED
FAILED
RETRY

Retry policy:
1st failure: 30 sec
2nd failure: 5 min
3rd failure: 30 min
then FAILED.
