<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/**
 * BMOS-A1.1 PrincipalContext (A0_02 §2): who acts, in which company, at which authorisation revision.
 *
 * Built only by principal_context_service from fresh registry rows. It carries no Moodle userid, session or token;
 * the sponsor id is attribution only and is never used as the acting user. principal_permission::can rebuilds the
 * context from the database on every call, so a stale or hand-made instance never grants anything.
 */
final class principal_context {
    /** @var string */
    private $companyuuid;
    /** @var string */
    private $principaluuid;
    /** @var string */
    private $principaltype;
    /** @var int */
    private $sponsoruserid;
    /** @var int */
    private $authzrevision;
    /** @var int */
    private $builtat;

    /** @internal Use principal_context_service::context(). */
    public function __construct(string $companyuuid, string $principaluuid, string $principaltype, int $sponsoruserid,
            int $authzrevision, int $builtat) {
        $this->companyuuid = $companyuuid;
        $this->principaluuid = $principaluuid;
        $this->principaltype = $principaltype;
        $this->sponsoruserid = $sponsoruserid;
        $this->authzrevision = $authzrevision;
        $this->builtat = $builtat;
    }

    public function companyuuid(): string {
        return $this->companyuuid;
    }

    public function principaluuid(): string {
        return $this->principaluuid;
    }

    public function principaltype(): string {
        return $this->principaltype;
    }

    /** Attribution only (A0_02 §7); never an acting user. */
    public function sponsoruserid(): int {
        return $this->sponsoruserid;
    }

    public function authzrevision(): int {
        return $this->authzrevision;
    }

    public function builtat(): int {
        return $this->builtat;
    }
}
