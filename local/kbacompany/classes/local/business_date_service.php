<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Locale-independent, Gregorian business dates for machine inputs and DB filters.
 *
 * Moodle userdate() is for human-readable labels, not HTML date controls or
 * lexically ordered ISO keys. Convert UTC instants to the active user's Moodle
 * timezone before producing invariant ASCII ISO-8601 values.
 */
final class business_date_service {
    private static function local_instant(?int $timestamp = null): \DateTimeImmutable {
        $instant = new \DateTimeImmutable('@' . ($timestamp ?? time()));
        return $instant->setTimezone(\core_date::get_user_timezone_object());
    }

    /** Calendar day YYYY-MM-DD for supplied Unix timestamp in current user timezone. */
    public static function day(?int $timestamp = null): string {
        return self::local_instant($timestamp)->format('Y-m-d');
    }

    /** Yesterday's civil calendar day, not Unix time minus 86400 seconds (DST-safe). */
    public static function yesterday(?int $timestamp = null): string {
        return self::local_instant($timestamp)->setTime(12, 0)->modify('-1 day')->format('Y-m-d');
    }

    /** Local datetime in the format accepted by HTML input[type=datetime-local]. */
    public static function datetime_local(?int $timestamp = null, bool $houronly = false): string {
        return self::local_instant($timestamp)->format($houronly ? 'Y-m-d\TH:00' : 'Y-m-d\TH:i');
    }
}
