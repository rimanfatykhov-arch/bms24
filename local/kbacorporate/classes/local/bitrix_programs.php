<?php
namespace local_kbacorporate\local;
defined('MOODLE_INTERNAL') || die();

final class bitrix_programs {
    private static function flatten(array $values, string $prefix = ''): array {
        $result = [];
        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $result += self::flatten($value, $name);
            } else {
                $result[$name] = is_bool($value) ? ($value ? '1' : '0') : $value;
            }
        }
        return $result;
    }

    private static function call(string $method, array $params = []): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $webhook = trim((string)get_config('local_kbacorporate', 'bitrixprogramwebhook'));
        if (!$webhook || strpos($webhook, 'https://') !== 0) {
            throw new \moodle_exception('bitrixprogramnotconfigured', 'local_kbacorporate');
        }
        $curl = new \curl();
        $raw = $curl->post(rtrim($webhook, '/') . '/' . $method . '.json', self::flatten($params));
        $response = json_decode($raw, true);
        if (!is_array($response) || array_key_exists('error', $response)) {
            $message = is_array($response) && !empty($response['error_description'])
                ? $response['error_description'] : 'Invalid response';
            throw new \moodle_exception('bitrixprogramrequestfailed', 'local_kbacorporate', '', $message);
        }
        return $response;
    }

    public static function get_categories(): array {
        $response = self::call('crm.category.list', ['entityTypeId' => 2]);
        $items = $response['result']['categories'] ?? $response['result'] ?? [];
        $options = [0 => get_string('bitrixdefaultpipeline', 'local_kbacorporate')];
        foreach ($items as $item) {
            if (isset($item['id'])) { $options[(int)$item['id']] = (string)($item['name'] ?? $item['id']); }
        }
        return $options;
    }

    public static function get_stages(int $categoryid): array {
        $entityid = $categoryid ? 'DEAL_STAGE_' . $categoryid : 'DEAL_STAGE';
        $response = self::call('crm.status.list', ['filter' => ['ENTITY_ID' => $entityid],
            'order' => ['SORT' => 'ASC']]);
        $options = [];
        foreach (($response['result'] ?? []) as $item) {
            if (!empty($item['STATUS_ID'])) { $options[(string)$item['STATUS_ID']] = (string)($item['NAME'] ?? $item['STATUS_ID']); }
        }
        return $options;
    }

    public static function get_responsible_users(): array {
        $response = self::call('user.get', ['FILTER' => ['ACTIVE' => true]]);
        $options = [];
        foreach (($response['result'] ?? []) as $item) {
            if (empty($item['ID'])) { continue; }
            $name = trim(($item['LAST_NAME'] ?? '') . ' ' . ($item['NAME'] ?? ''));
            $options[(int)$item['ID']] = $name ?: (string)($item['EMAIL'] ?? $item['ID']);
        }
        natcasesort($options);
        return $options;
    }

    public static function get_current_user(): array {
        $response = self::call('user.current');
        $item = $response['result'] ?? [];
        if (empty($item['ID'])) { return []; }
        $name = trim(($item['LAST_NAME'] ?? '') . ' ' . ($item['NAME'] ?? ''));
        return [(int)$item['ID'] => $name ?: (string)($item['EMAIL'] ?? $item['ID'])];
    }

    public static function create_deal(\stdClass $program): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $webhook = trim((string)get_config('local_kbacorporate', 'bitrixprogramwebhook'));
        if (!$webhook || strpos($webhook, 'https://') !== 0) {
            throw new \moodle_exception('bitrixprogramnotconfigured', 'local_kbacorporate');
        }
        if ((string)get_config('local_kbacorporate', 'bitrixprogramcategoryid') === '' ||
                trim((string)get_config('local_kbacorporate', 'bitrixprogramstageid')) === '') {
            throw new \moodle_exception('bitrixprogramnotconfigured', 'local_kbacorporate');
        }
        if (!empty($program->bitrixdealid)) { return (int)$program->bitrixdealid; }
        $company = $DB->get_record('local_kbacorp_company', ['id' => $program->companyid], '*', MUST_EXIST);
        $package = implementation_packages::get_by_code($program->package);
        $fields = [
            'TITLE' => format_string($program->title) . ' — ' . format_string($company->name),
            'CATEGORY_ID' => trim((string)get_config('local_kbacorporate', 'bitrixprogramcategoryid')),
            'STAGE_ID' => trim((string)get_config('local_kbacorporate', 'bitrixprogramstageid')),
            'COMMENTS' => 'Moodle program #' . $program->id . '; package: ' .
                ($package ? $package->name : $program->package) .
                (!empty($program->contactname) ? "\nContact: " . $program->contactname : '') .
                (!empty($program->contactemail) ? "\nEmail: " . $program->contactemail : '') .
                (!empty($program->contactphone) ? "\nPhone: " . $program->contactphone : '') .
                (!empty($program->customercomment) ? "\nComment: " . $program->customercomment : ''),
        ];
        $responsibleid = (int)get_config('local_kbacorporate', 'bitrixprogramresponsibleid');
        if ($responsibleid) { $fields['ASSIGNED_BY_ID'] = $responsibleid; }
        if (!empty($company->bitrixcompanyid)) { $fields['COMPANY_ID'] = $company->bitrixcompanyid; }
        if ($package && $package->price !== null) { $fields['OPPORTUNITY'] = $package->price; $fields['CURRENCY_ID'] = $package->currency; }
        $response = self::call('crm.deal.add', ['fields' => $fields]);
        if (empty($response['result'])) { throw new \moodle_exception('bitrixprogramrequestfailed', 'local_kbacorporate'); }
        $program->bitrixdealid = (string)$response['result']; $program->timemodified = time();
        $DB->update_record('local_kbacorp_impl_program', $program);
        return (int)$response['result'];
    }
}
