<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Session-bound UI form receipts, not a durable integration/idempotency engine. */
class document_request_service {
    public static function issue(string $company, int $actor, string $document, int $revision,
            string $operation, string $expected = '', string $process = ''): array {
        global $SESSION;
        $forms = (array)($SESSION->kbadocumentforms ?? []);
        // Bound session metadata. No document contents or external credentials are stored here.
        foreach ($forms as $key=>$form) {
            if ($form['issued'] < time()-7200) { unset($forms[$key]); }
        }
        while (count($forms) >= 30) { array_shift($forms); }
        $key = bin2hex(random_bytes(16));
        $forms[$key] = ['company'=>$company,'actor'=>$actor,'document'=>$document,'revision'=>$revision,
            'operation'=>$operation,'expected'=>$expected,'process'=>$process,'issued'=>time(),'receipt'=>null];
        $SESSION->kbadocumentforms = $forms;
        return ['requestkey'=>$key];
    }

    public static function verify(string $key, string $company, int $actor, string $document,
            int $revision, string $operation, string $process = ''): array {
        global $SESSION;
        $forms = (array)($SESSION->kbadocumentforms ?? []);
        $form = $forms[$key] ?? null;
        if (!$form || $form['company'] !== $company || $form['actor'] !== $actor ||
                $form['document'] !== $document || $form['revision'] !== $revision ||
                $form['operation'] !== $operation || $form['process'] !== $process ||
                $form['issued'] < time()-7200 || ($document !== '' && $form['expected'] === '')) {
            throw new \moodle_exception('dcu_expired_form','local_kbacompany');
        }
        return $form;
    }

    public static function digest(array $values): string {
        ksort($values);
        return hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public static function replay(array $form, string $digest): ?array {
        if ($form['receipt'] === null) { return null; }
        if (!hash_equals($form['receipt']['digest'],$digest)) {
            throw new \moodle_exception('dcu_reused_form','local_kbacompany');
        }
        return $form['receipt']['destination'];
    }

    /** No binary/file path retained in session. Prevent accidentally dropping an upload after validation errors. */
    public static function upload_pending(string $key): void {
        global $SESSION;
        $forms = (array)($SESSION->kbadocumentforms ?? []);
        if (isset($forms[$key])) { $forms[$key]['uploadpending'] = true; }
        $SESSION->kbadocumentforms = $forms;
    }

    public static function complete(string $key, string $digest, array $destination): void {
        global $SESSION;
        $forms = (array)($SESSION->kbadocumentforms ?? []);
        $forms[$key]['receipt'] = ['digest'=>$digest,'destination'=>$destination];
        $SESSION->kbadocumentforms = $forms;
    }
}
