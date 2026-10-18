<?php
namespace local_kbaai\provider;
defined('MOODLE_INTERNAL') || die();
class provider_factory {
    public static function create($providerid) {
        if ($providerid === 'mock') { return new mock_provider(); }
        if ($providerid === 'yandex') { return new yandex_provider_v080(); }
        throw new provider_exception('unknown_provider', 'Unknown AI provider: ' . $providerid, false);
    }
}
