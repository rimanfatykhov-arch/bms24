<?php
namespace local_kbaai\repository;
defined('MOODLE_INTERNAL') || die();
class instance_repository {
    public function get($kbaelementid) {
        global $DB;
        $record = $DB->get_record('local_kbaai_instance', array('kbaelementid' => (int)$kbaelementid));
        if ($record) { return $this->apply_global_ai_settings($record); }
        $now = time();
        $record = (object)array(
            'kbaelementid' => (int)$kbaelementid, 'enabled' => 0,
            'mode' => (string)(get_config('local_kbaai', 'defaultmode') ?: 'assistant'),
            'provider' => (string)(get_config('local_kbaai', 'defaultprovider') ?: 'mock'),
            'model' => (string)(get_config('local_kbaai', 'defaultmodel') ?: ''),
            'confidencethreshold' => (int)(get_config('local_kbaai', 'defaultconfidence') ?: 85),
            'autopublish' => (int)(get_config('local_kbaai', 'defaultautopublish') ?: 0), 'teacherinstructions' => '',
            'referenceanswer' => '', 'rubricjson' => '', 'modifiedby' => 0, 'timecreated' => $now, 'timemodified' => $now,
        );
        $record->id = $DB->insert_record('local_kbaai_instance', $record);
        return $this->apply_global_ai_settings($record);
    }
    public function save($kbaelementid, array $data, $userid) {
        global $DB;
        $record = $this->get($kbaelementid); $record->enabled = empty($data['enabled']) ? 0 : 1;
        $record->mode = in_array($data['mode'], array('tutor','assistant','examiner'), true) ? $data['mode'] : 'assistant';
        $record->provider = in_array($data['provider'], array('mock','yandex'), true) ? $data['provider'] : 'mock';
        $record->model = clean_param($data['model'], PARAM_TEXT); $record->confidencethreshold = max(0, min(100, (int)$data['confidencethreshold']));
        $record->autopublish = empty($data['autopublish']) ? 0 : 1; $record->teacherinstructions = (string)$data['teacherinstructions'];
        $record->referenceanswer = (string)$data['referenceanswer']; $record->rubricjson = (string)$data['rubricjson'];
        $record->modifiedby = (int)$userid; $record->timemodified = time(); $DB->update_record('local_kbaai_instance', $record); return $record;
    }

    /** Teacher-editable pedagogical configuration only. */
    public function save_teacher_config($kbaelementid, array $data, $userid) {
        global $DB;
        $record = $DB->get_record('local_kbaai_instance', array('kbaelementid' => (int)$kbaelementid));
        if (!$record) {
            $record = $this->get($kbaelementid);
            $record = $DB->get_record('local_kbaai_instance', array('id' => $record->id), '*', MUST_EXIST);
        }
        $record->enabled = empty($data['enabled']) ? 0 : 1;
        $record->teacherinstructions = (string)$data['teacherinstructions'];
        $record->referenceanswer = (string)$data['referenceanswer'];
        if (array_key_exists('rubricjson', $data)) { $record->rubricjson = (string)$data['rubricjson']; }
        $record->modifiedby = (int)$userid;
        $record->timemodified = time();
        $DB->update_record('local_kbaai_instance', $record);
        return $this->apply_global_ai_settings($record);
    }

    /** Apply administrator-owned AI runtime settings without exposing them to teachers. */
    private function apply_global_ai_settings($record) {
        $mode = (string)get_config('local_kbaai', 'defaultmode');
        $provider = (string)get_config('local_kbaai', 'defaultprovider');
        $model = (string)get_config('local_kbaai', 'defaultmodel');
        $confidence = get_config('local_kbaai', 'defaultconfidence');
        $autopublish = get_config('local_kbaai', 'defaultautopublish');
        $record->mode = in_array($mode, array('tutor','assistant','examiner'), true) ? $mode : 'assistant';
        $record->provider = in_array($provider, array('mock','yandex'), true) ? $provider : 'mock';
        $record->model = $model !== false ? clean_param($model, PARAM_TEXT) : '';
        $record->confidencethreshold = $confidence === false ? 85 : max(0, min(100, (int)$confidence));
        $record->autopublish = $autopublish === false ? 0 : (empty($autopublish) ? 0 : 1);
        return $record;
    }

    public function list_enabled() { global $DB; return $DB->get_records('local_kbaai_instance', array('enabled' => 1)); }
}
