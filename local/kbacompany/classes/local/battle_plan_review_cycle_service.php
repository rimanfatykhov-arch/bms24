<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Durable, append-only review-cycle events for a Battle Plan.
 * New events only: legacy returned cycles cannot be reconstructed reliably.
 * The existing plan transaction/lock must protect each call to append_event().
 */
class battle_plan_review_cycle_service {
    public const TYPES = ['submitted', 'approved', 'returned', 'selfapproved'];
    private const FIELD = 'review_cycle_events';

    /** Preserve all existing statepayload namespaces when recording an event. */
    public static function append_event(string $raw, string $type, int $actor, int $at): string {
        if (!in_array($type, self::TYPES, true) || $actor < 1 || $at < 1) {
            throw new \InvalidArgumentException('Invalid Battle Plan review event');
        }
        $payload = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($payload)) { throw new \UnexpectedValueException('Invalid Battle Plan state payload'); }
        $events = $payload[self::FIELD] ?? [];
        if (!is_array($events)) { throw new \UnexpectedValueException('Invalid Battle Plan review history'); }
        if (count($events) >= 500) { throw new \LengthException('Battle Plan review history limit reached'); }
        $cycle = 0;
        foreach ($events as $event) {
            if (is_array($event) && in_array((string)($event['type'] ?? ''), ['submitted', 'selfapproved'], true)) {
                $cycle++;
            }
        }
        if ($type === 'submitted' || $type === 'selfapproved') { $cycle++; }
        $events[] = ['type'=>$type, 'actorid'=>$actor, 'at'=>$at, 'cycle'=>$cycle];
        $payload[self::FIELD] = $events;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 500000) { throw new \LengthException('Battle Plan state payload too large'); }
        return $encoded;
    }

    /**
     * Pure, non-invasive projection. A decision is timed only when the current
     * cycle has a matching submission with a non-future timestamp.
     */
    public static function project(string $raw, array $names = []): array {
        $payload = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($payload)) { return ['rows'=>[], 'cycles'=>0, 'timed'=>0, 'average_seconds'=>null]; }
        $events = $payload[self::FIELD] ?? [];
        if (!is_array($events)) { $events = []; }
        $rows = [];
        $cycle = 0;
        $started = null;
        $timed = 0;
        $elapsedtotal = 0;
        foreach ($events as $event) {
            if (!is_array($event)) { continue; }
            $type = (string)($event['type'] ?? '');
            if (!in_array($type, self::TYPES, true)) { continue; }
            $at = (int)($event['at'] ?? 0);
            $actorid = (int)($event['actorid'] ?? 0);
            if ($at < 1 || $actorid < 1) { continue; }
            $duration = null;
            if ($type === 'submitted') {
                $cycle++;
                $started = $at;
            } else if ($type === 'selfapproved') {
                $cycle++;
                $started = null; // Explicit exclusion from reviewer turnaround mean.
            } else {
                if ($started !== null && $at >= $started) {
                    $duration = $at - $started;
                    $elapsedtotal += $duration;
                    $timed++;
                }
                $started = null;
            }
            $rows[] = ['type'=>$type, 'actor_id'=>$actorid, 'actor_name'=>(string)($names[$actorid] ?? ''),
                'time_created'=>$at, 'cycle'=>$cycle, 'duration_seconds'=>$duration];
        }
        return ['rows'=>$rows, 'cycles'=>$cycle, 'timed'=>$timed,
            'average_seconds'=>$timed ? (int)round($elapsedtotal / $timed) : null];
    }
}
