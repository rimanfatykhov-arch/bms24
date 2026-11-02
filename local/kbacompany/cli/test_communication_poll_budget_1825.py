#!/usr/bin/env python3
"""Reproducible request-rate MODEL, not a live server load benchmark."""
import random


def simulate(users=60, minutes=10, idle=False, jitter=True):
    rng = random.Random(1825)
    period = 60 if idle else 25
    end = minutes * 60
    events = []
    for _ in range(users):
        t = (rng.uniform(0, period) if jitter else period)
        while t <= end:
            events.append(t)
            t += period * (rng.uniform(.87, 1.13) if jitter else 1)
    buckets = [0] * (end + 1)
    for t in events:
        buckets[int(t)] += 1
    return len(events), max(buckets)


def main():
    active = simulate(idle=False)
    idle = simulate(idle=True)
    synchronized = simulate(jitter=False)
    assert 500 <= active[0] <= 1700, active
    assert idle[0] < active[0], (idle, active)
    assert active[1] < synchronized[1], (active, synchronized)
    print('PASS active poll count for 60 users / 10 minutes:', active[0])
    print('PASS idle poll count for 60 users / 10 minutes:', idle[0])
    print('PASS jitter lowers per-second peak in model:', active[1], '<', synchronized[1])
    print('MODEL ONLY: no Moodle PHP, SQL, network or measured server p95.')


if __name__ == '__main__':
    main()
