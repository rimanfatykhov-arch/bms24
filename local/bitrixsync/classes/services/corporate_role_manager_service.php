<?php
namespace local_bitrixsync\services;
defined('MOODLE_INTERNAL') || die();
class corporate_role_manager_service {
    public function get_roles(): array {
        return (new \local_bitrixsync\repositories\role_registry_repository())->get_active();
    }
}
