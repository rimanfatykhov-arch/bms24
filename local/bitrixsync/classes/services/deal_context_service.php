<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\deal_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolve CRM ownership for a deal.
 *
 * Company is the B2B contract entity.
 * Contact is the person.
 * Deal remains the sale/enrolment trigger only.
 */
class deal_context_service {
    /** @var deal_client */
    private $client;

    public function __construct(?deal_client $client = null) {
        $this->client = $client ?? new deal_client();
    }

    public function resolve(int $dealid): array {
        $deal = $this->client->get_deal($dealid);

        $companyid = (int)($deal['COMPANY_ID'] ?? 0);
        $contactid = (int)($deal['CONTACT_ID'] ?? 0);

        if ($contactid <= 0 && !empty($deal['CONTACT_IDS']) && is_array($deal['CONTACT_IDS'])) {
            $contactid = (int)reset($deal['CONTACT_IDS']);
        }

        $company = $companyid > 0 ? $this->client->get_company($companyid) : [];
        $contact = $contactid > 0 ? $this->client->get_contact($contactid) : [];

        $companytitle = trim((string)($company['TITLE'] ?? ''));
        if ($companyid > 0 && $companytitle === '') {
            $companytitle = 'Bitrix24 Company #' . $companyid;
        }

        return [
            'deal' => $deal,
            'bitrixcompanyid' => $companyid,
            'companytitle' => $companytitle,
            'bitrixcontactid' => $contactid,
            'company' => $company,
            'contact' => $contact,
            'iscorporate' => $companyid > 0,
        ];
    }

    /**
     * Resolve all people linked to a deal. Each contact is fully hydrated so
     * contact_service can reliably read EMAIL, PHONE, NAME and Moodle fields.
     */
    public function resolve_contacts(int $dealid): array {
        $base = $this->resolve($dealid);
        $items = $this->client->get_deal_contacts($dealid);
        $contacts = [];

        foreach ($items as $item) {
            $contactid = (int)($item['id'] ?? 0);
            if ($contactid <= 0) {
                continue;
            }

            $contact = is_array($item['contact'] ?? null)
                ? $item['contact']
                : [];

            $contacts[] = [
                'bitrixcontactid' => $contactid,
                'contact' => $contact,
                'isprimary' => !empty($item['isprimary']),
                'error' => $item['error'] ?? null,
            ];
        }

        // Preserve the original single-contact path as a final fallback.
        if (!$contacts && (int)$base['bitrixcontactid'] > 0) {
            $contacts[] = [
                'bitrixcontactid' => (int)$base['bitrixcontactid'],
                'contact' => $base['contact'],
                'isprimary' => true,
                'error' => null,
            ];
        }

        $base['contacts'] = $contacts;
        return $base;
    }

}
