<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\deal_client;

defined('MOODLE_INTERNAL') || die();

class deal_contact_discovery_diagnostic_service {
    private $bitrix;
    private $contacts;

    public function __construct(?deal_client $bitrix=null, ?contact_service $contacts=null) {
        $this->bitrix=$bitrix ?? new deal_client();
        $this->contacts=$contacts ?? new contact_service();
    }

    public function run(int $dealid): array {
        $trace=[
            'time'=>time(),
            'dealid'=>$dealid,
            'deal_loaded'=>false,
            'deal'=>[],
            'contact_ids'=>[],
            'contacts'=>[],
            'summary'=>'unknown',
        ];

        try {
            $deal=$this->load_deal($dealid);
            $trace['deal_loaded']=!empty($deal);
            $trace['deal']=[
                'id'=>(int)($deal['ID'] ?? $deal['id'] ?? 0),
                'title'=>(string)($deal['TITLE'] ?? ''),
                'stage_id'=>(string)($deal['STAGE_ID'] ?? ''),
                'company_id'=>(int)($deal['COMPANY_ID'] ?? 0),
                'contact_id'=>(int)($deal['CONTACT_ID'] ?? 0),
                'contact_ids_raw'=>$deal['CONTACT_IDS'] ?? null,
            ];

            $ids=$this->discover_contact_ids($dealid,$deal);
            $trace['contact_ids']=$ids;

            foreach ($ids as $contactid) {
                $item=[
                    'contactid'=>$contactid,
                    'loaded'=>false,
                    'email'=>'',
                    'name'=>'',
                    'companyid'=>0,
                    'moodle'=>[
                        'status'=>'',
                        'userid'=>0,
                        'wouldcreate'=>false,
                        'created'=>false,
                        'error'=>'',
                    ],
                ];

                try {
                    $contact=$this->bitrix->get_contact($contactid);
                    $item['loaded']=!empty($contact);
                    $item['email']=$this->extract_email((array)$contact);
                    $item['name']=trim(
                        (string)($contact['NAME'] ?? '').' '.
                        (string)($contact['LAST_NAME'] ?? '')
                    );
                    $item['companyid']=(int)($contact['COMPANY_ID'] ?? 0);

                    try {
                        $resolution=$this->contacts->resolve_user(
                            $contactid,
                            (array)$contact,
                            true,
                            null
                        );
                        $item['moodle']=[
                            'status'=>(string)($resolution['status'] ?? ''),
                            'userid'=>(int)($resolution['userid'] ?? 0),
                            'wouldcreate'=>!empty($resolution['wouldcreate']),
                            'created'=>!empty($resolution['created']),
                            'email'=>(string)($resolution['email'] ?? $item['email']),
                            'error'=>'',
                        ];
                    } catch (\Throwable $e) {
                        $item['moodle']['status']='error';
                        $item['moodle']['error']=$e->getMessage();
                    }
                } catch (\Throwable $e) {
                    $item['load_error']=$e->getMessage();
                }

                $trace['contacts'][]=$item;
            }

            $trace['summary']=empty($ids)
                ? 'no_contacts_discovered'
                : 'completed';
        } catch (\Throwable $e) {
            $trace['summary']='error';
            $trace['error_class']=get_class($e);
            $trace['error']=$e->getMessage();
        }

        set_config(
            'deal_contact_discovery_debug_last',
            json_encode($trace, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'local_bitrixsync'
        );

        return $trace;
    }

    private function load_deal(int $dealid): array {
        if (method_exists($this->bitrix,'get_deal')) {
            return (array)$this->bitrix->get_deal($dealid);
        }
        if (method_exists($this->bitrix,'get')) {
            return (array)$this->bitrix->get($dealid);
        }
        throw new \runtime_exception('No readable deal method exposed');
    }

    private function discover_contact_ids(int $dealid, array $deal): array {
        $ids=[];

        foreach (['get_deal_contact_ids','get_contact_ids','get_deal_contacts','get_contacts'] as $method) {
            if (!method_exists($this->bitrix,$method)) {
                continue;
            }
            try {
                $ids=array_merge($ids,$this->normalize_ids($this->bitrix->{$method}($dealid)));
            } catch (\Throwable $ignored) {}
        }

        foreach ([$deal['CONTACT_ID'] ?? null,$deal['CONTACT_IDS'] ?? null,$deal['CONTACTS'] ?? null] as $value) {
            $ids=array_merge($ids,$this->normalize_ids($value));
        }

        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        sort($ids);
        return $ids;
    }

    private function normalize_ids($value): array {
        if ($value===null || $value==='' || $value===false) return [];
        if (is_numeric($value)) return [(int)$value];

        if (is_string($value)) {
            return array_values(array_filter(array_map(
                'intval',
                preg_split('/[^0-9]+/',$value)
            )));
        }

        if (is_array($value)) {
            $ids=[];
            foreach ($value as $item) {
                if (is_numeric($item)) {
                    $ids[]=(int)$item;
                } elseif (is_array($item)) {
                    foreach (['ID','id','CONTACT_ID','contactId'] as $key) {
                        if (!empty($item[$key])) {
                            $ids[]=(int)$item[$key];
                            break;
                        }
                    }
                }
            }
            return $ids;
        }

        return [];
    }

    private function extract_email(array $contact): string {
        if (!empty($contact['EMAIL']) && is_array($contact['EMAIL'])) {
            foreach ($contact['EMAIL'] as $row) {
                if (is_array($row) && !empty($row['VALUE'])) return trim((string)$row['VALUE']);
            }
        }
        return '';
    }
}
