<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Resolves a user's target competency profile from active business roles. */
class target_profile {
    public static function for_user(int $userid, int $companyid): array {
        global $DB;
        if (!business_roles::available() || $companyid <= 0) {
            return [];
        }
        $roles = business_roles::get_user_roles($userid, $companyid);
        if (!$roles) {
            return [];
        }
        $roleids = array_values(array_unique(array_map(static function($role) {
            return (int)$role->id;
        }, $roles)));
        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $sql = "SELECT rc.id, rc.roleid, rc.competencyid, rc.targetlevel, rc.priority, rc.required,
                       r.code AS rolecode, r.name AS rolename,
                       c.idnumber, c.shortname, c.description, c.descriptionformat,
                       c.competencyframeworkid AS frameworkid, c.scaleid AS competencyscaleid,
                       f.shortname AS frameworkname, f.scaleid AS frameworkscaleid
                  FROM {local_kbacorp_rolecomp} rc
                  JOIN {local_kbacorp_busrole} r ON r.id = rc.roleid
                  JOIN {competency} c ON c.id = rc.competencyid
             LEFT JOIN {competency_framework} f ON f.id = c.competencyframeworkid
                 WHERE rc.roleid {$insql}
              ORDER BY c.shortname, r.name";
        $result = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (business_roles::is_domain_competency_code((string)$row->idnumber)) {
                continue;
            }
            $cid = (int)$row->competencyid;
            if (!isset($result[$cid])) {
                $result[$cid] = (object)[
                    'competencyid' => $cid,
                    'targetlevel' => (int)$row->targetlevel,
                    'priority' => business_roles::normalise_priority((string)$row->priority),
                    'required' => !empty($row->required),
                    'roles' => [],
                    'idnumber' => $row->idnumber,
                    'shortname' => $row->shortname,
                    'description' => $row->description,
                    'descriptionformat' => $row->descriptionformat,
                    'frameworkid' => $row->frameworkid,
                    'competencyscaleid' => $row->competencyscaleid,
                    'frameworkname' => $row->frameworkname,
                    'frameworkscaleid' => $row->frameworkscaleid,
                ];
            } else {
                $result[$cid]->targetlevel = max((int)$result[$cid]->targetlevel, (int)$row->targetlevel);
                $result[$cid]->required = $result[$cid]->required || !empty($row->required);
                $result[$cid]->priority = self::stronger_priority($result[$cid]->priority, (string)$row->priority);
            }
            $result[$cid]->roles[(int)$row->roleid] = (object)[
                'id' => (int)$row->roleid,
                'code' => $row->rolecode,
                'name' => $row->rolename,
                'targetlevel' => (int)$row->targetlevel,
                'priority' => business_roles::normalise_priority((string)$row->priority),
                'required' => !empty($row->required),
            ];
        }
        foreach ($result as $item) {
            $item->roles = array_values($item->roles);
        }
        return $result;
    }

    private static function stronger_priority(string $a, string $b): string {
        $rank = ['additional' => 1, 'core' => 2, 'critical' => 3];
        $a = business_roles::normalise_priority($a);
        $b = business_roles::normalise_priority($b);
        return $rank[$b] > $rank[$a] ? $b : $a;
    }
}
