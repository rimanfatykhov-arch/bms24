<?php
namespace local_kbacompany\local\task_provider;

defined('MOODLE_INTERNAL') || die();

/** Read-only projection contract for task-like work owned by BMS or external subsystems. */
interface task_provider_interface {
    public function key(): string;
    public function label(): string;
    public function available(): bool;
    public function supports_company_sources(): bool;

    /**
     * Return task projections visible to target user. Actor is the authorised caller.
     * Every projection must contain provider/external_id/title/status/stream/source_url.
     */
    public function list_tasks(string $companyuuid, int $actor, int $targetuserid, string $scope = 'my'): array;

    /** Return one projection or null when unavailable/invisible. */
    public function get_task(string $companyuuid, int $actor, string $externalid): ?array;

    /** Discover bindable provider sources, e.g. Moodle courses containing KBA Elements. */
    public function available_sources(string $companyuuid, int $actor): array;
}
