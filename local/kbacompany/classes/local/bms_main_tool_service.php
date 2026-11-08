<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-company start tool and navigation shortcuts.
 * The existing BMS sidebar is the permission-filtered authority for destinations.
 * Preferences cannot grant access and never contain arbitrary URLs.
 */
final class bms_main_tool_service {
    public const CONTRACT_VERSION = '1.0.0';
    public const MAX_FAVORITES = 5;
    private const MAIN_KEYS = ['workspace', 'battleplan', 'tasks', 'management',
        'communications', 'architecturecenter', 'people'];

    private static function preference_key(string $companyuuid, string $suffix): string {
        return 'local_kbacompany_' . $suffix . '_' . substr(hash('sha256', $companyuuid), 0, 24);
    }

    /** Only sidebar-visible destinations can be selected. */
    public static function visible_routes(array $groups): array {
        $routes = [];
        foreach ($groups as $group) {
            // Do not re-index synthesized 'my main tool' or favorites groups.
            if (in_array((string)($group['key'] ?? ''), ['personal_main', 'personal_favorites'], true)) {
                continue;
            }
            foreach ((array)($group['items'] ?? []) as $item) {
                if (!empty($item['disabled']) || empty($item['key']) || empty($item['url'])) {
                    continue;
                }
                $routes[(string)$item['key']] = $item;
            }
        }
        return $routes;
    }

    public static function main_options(array $groups): array {
        $routes = self::visible_routes($groups);
        $available = [];
        foreach (self::MAIN_KEYS as $key) {
            if (isset($routes[$key])) {
                $available[$key] = $routes[$key];
            }
        }
        return $available;
    }

    public static function resolve_main(string $stored, array $groups): string {
        $options = self::main_options($groups);
        return isset($options[$stored]) ? $stored : 'workspace';
    }

    /** Pure input sanitizer, used by both POST and preference re-read. */
    public static function filter_favorites(array $requested, array $groups): array {
        $routes = self::visible_routes($groups);
        $safe = [];
        foreach ($requested as $item) {
            if (!is_string($item) || !isset($routes[$item]) || in_array($item, $safe, true)) {
                continue;
            }
            $safe[] = $item;
            if (count($safe) >= self::MAX_FAVORITES) {
                break;
            }
        }
        return $safe;
    }

    public static function current(string $companyuuid, int $userid, array $groups): array {
        $main = (string)get_user_preferences(self::preference_key($companyuuid, 'main'), 'workspace', $userid);
        $raw = (string)get_user_preferences(self::preference_key($companyuuid, 'favorites'), '[]', $userid);
        $decoded = json_decode($raw, true);
        $favorites = self::filter_favorites(is_array($decoded) ? $decoded : [], $groups);
        $dismissed = (string)get_user_preferences(self::preference_key($companyuuid, 'intro'), '', $userid) === '1';
        return ['main' => self::resolve_main($main, $groups), 'favorites' => $favorites,
            'intro_dismissed' => $dismissed];
    }

    public static function save(string $companyuuid, int $userid, array $groups,
            string $requestedmain, array $favorites): void {
        // Fail closed instead of substituting another requested route.
        if (!isset(self::main_options($groups)[$requestedmain])) {
            throw new \moodle_exception('bmsmain_invalid', 'local_kbacompany');
        }
        $validfavorites = self::filter_favorites($favorites, $groups);
        set_user_preference(self::preference_key($companyuuid, 'main'), $requestedmain, $userid);
        set_user_preference(self::preference_key($companyuuid, 'favorites'), json_encode($validfavorites), $userid);
        set_user_preference(self::preference_key($companyuuid, 'intro'), '1', $userid);
    }

    public static function dismiss(string $companyuuid, int $userid): void {
        set_user_preference(self::preference_key($companyuuid, 'intro'), '1', $userid);
    }

    public static function main_url(string $companyuuid, array $groups, int $userid): string {
        $settings = self::current($companyuuid, $userid, $groups);
        $routes = self::main_options($groups);
        // The workspace is always accessible to an authorized company member.
        if (isset($routes[$settings['main']])) {
            return (string)$routes[$settings['main']]['url'];
        }
        return (new \moodle_url('/local/kbacompany/bms_workspace.php', ['uuid' => $companyuuid]))->out(false);
    }

    public static function personalise_groups(string $companyuuid, int $userid, array $groups): array {
        $routes = self::visible_routes($groups);
        $settings = self::current($companyuuid, $userid, $groups);
        $personal = [];
        $main = $settings['main'];
        if (isset($routes[$main])) {
            $item = $routes[$main];
            $item['active'] = false; // The canonical group alone exposes aria-current="page".
            $personal[] = ['key' => 'personal_main', 'label' => get_string('bmsmain_sidebar_main', 'local_kbacompany'),
                'items' => [$item], 'expanded' => true, 'collapsed' => false, 'active' => false];
        }
        $favoriteitems = [];
        foreach ($settings['favorites'] as $key) {
            if ($key === $main || !isset($routes[$key])) {
                continue;
            }
            $item = $routes[$key];
            $item['active'] = false;
            $favoriteitems[] = $item;
        }
        if ($favoriteitems) {
            $personal[] = ['key' => 'personal_favorites', 'label' => get_string('bmsmain_sidebar_favorites', 'local_kbacompany'),
                'items' => $favoriteitems, 'expanded' => true, 'collapsed' => false, 'active' => false];
        }
        return array_merge($personal, $groups);
    }
}
