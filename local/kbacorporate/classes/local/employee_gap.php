<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Company-scoped employee GAP projection based on assigned business roles.
 *
 * Moodle competency grades remain the authoritative source of current levels.
 * Role profiles supply target levels, priority and mandatory/supporting status.
 * This service is read-only and does not create assessments, plans or courses.
 */
class employee_gap {
    public const STATE_NO_ROLES = 'noroles';
    public const STATE_EMPTY_PROFILE = 'emptyprofile';
    public const STATE_NEEDS_ASSESSMENT = 'needsassessment';
    public const STATE_CRITICAL_GAPS = 'criticalgaps';
    public const STATE_REQUIRED_GAPS = 'requiredgaps';
    public const STATE_SUPPORTING_GAPS = 'supportinggaps';
    public const STATE_READY = 'ready';

    public const ROW_GAP = 'gap';
    public const ROW_UNASSESSED = 'unassessed';
    public const ROW_PENDING = 'pending';
    public const ROW_MET = 'met';

    /**
     * Calculate the employee's current competency GAP against all active assigned business roles.
     *
     * If the same competency is required by several roles, target_profile applies the highest target,
     * strongest priority and mandatory flag. Domain nodes BMS-Dxx are already excluded there.
     */
    public static function calculate(int $companyid, int $userid): \stdClass {
        global $DB;

        if ($companyid <= 0 || $userid <= 0) {
            throw new \invalid_parameter_exception('Необходимо выбрать компанию и сотрудника.');
        }
        if (!$DB->record_exists('local_kbacorp_member', [
                'companyid' => $companyid,
                'userid' => $userid,
                'isemployee' => 1,
            ])) {
            throw new \invalid_parameter_exception('Выбранный пользователь не является сотрудником указанной компании.');
        }

        $roles = business_roles::get_user_roles($userid, $companyid);
        $profile = target_profile::for_user($userid, $companyid);
        $snapshot = competencies::get_user_snapshot($userid, $companyid, false);
        $snapshotmap = [];
        foreach ($snapshot['competencies'] as $item) {
            $snapshotmap[(int)$item->competencyid] = $item;
        }

        $counts = (object)[
            'roles' => count($roles),
            'competencies' => count($profile),
            'mandatory' => 0,
            'supporting' => 0,
            'assessed' => 0,
            'unassessed' => 0,
            'pendingconfirmation' => 0,
            'gaps' => 0,
            'mandatorygaps' => 0,
            'supportinggaps' => 0,
            'criticalgaps' => 0,
            'met' => 0,
            'coursecovered' => 0,
            'gapswithcourse' => 0,
            'gapswithoutcourse' => 0,
            'mandatorygapswithoutcourse' => 0,
            'gappoints' => 0,
            'assessmentcoverage' => 0,
            'targetreadiness' => 0,
        ];

        $rows = [];
        foreach ($profile as $competencyid => $target) {
            $competencyid = (int)$competencyid;
            $snapshotrow = $snapshotmap[$competencyid] ?? null;
            $currentlevel = $snapshotrow ? $snapshotrow->currentlevel : null;
            $provisionallevel = $snapshotrow ? ($snapshotrow->provisionallevel ?? null) : null;
            $targetlevel = (int)$target->targetlevel;
            $priority = business_roles::normalise_priority((string)$target->priority);
            $required = !empty($target->required);
            $courses = $snapshotrow ? (array)($snapshotrow->recommendedcourses ?? []) :
                course_recommendations::for_competency($userid, $companyid, $competencyid);
            $coursecovered = !empty($courses);

            if ($required) {
                $counts->mandatory++;
            } else {
                $counts->supporting++;
            }
            if ($coursecovered) {
                $counts->coursecovered++;
            }

            $gap = null;
            if ($currentlevel === null) {
                $counts->unassessed++;
                if ($provisionallevel !== null) {
                    $counts->pendingconfirmation++;
                    $status = self::ROW_PENDING;
                } else {
                    $status = self::ROW_UNASSESSED;
                }
            } else {
                $currentlevel = (int)$currentlevel;
                $counts->assessed++;
                $gap = max(0, $targetlevel - $currentlevel);
                if ($gap > 0) {
                    $status = self::ROW_GAP;
                    $counts->gaps++;
                    $counts->gappoints += $gap;
                    if ($required) {
                        $counts->mandatorygaps++;
                    } else {
                        $counts->supportinggaps++;
                    }
                    if ($priority === business_roles::PRIORITY_CRITICAL) {
                        $counts->criticalgaps++;
                    }
                    if ($coursecovered) {
                        $counts->gapswithcourse++;
                    } else {
                        $counts->gapswithoutcourse++;
                        if ($required) {
                            $counts->mandatorygapswithoutcourse++;
                        }
                    }
                } else {
                    $status = self::ROW_MET;
                    $counts->met++;
                }
            }

            $rows[] = (object)[
                'competencyid' => $competencyid,
                'shortname' => (string)$target->shortname,
                'idnumber' => (string)$target->idnumber,
                'frameworkname' => (string)($target->frameworkname ?? ''),
                'competencyscaleid' => (int)($target->competencyscaleid ?? 0),
                'frameworkscaleid' => (int)($target->frameworkscaleid ?? 0),
                'targetlevel' => $targetlevel,
                'currentlevel' => $currentlevel,
                'provisionallevel' => $provisionallevel === null ? null : (int)$provisionallevel,
                'gap' => $gap,
                'priority' => $priority,
                'required' => $required,
                'roles' => array_values((array)$target->roles),
                'courses' => array_values($courses),
                'coursecovered' => $coursecovered,
                'status' => $status,
                'latestassessment' => $snapshotrow ? ($snapshotrow->latestassessment ?? null) : null,
                'displaystatus' => $snapshotrow ? ($snapshotrow->displaystatus ?? '') : '',
            ];
        }

        $counts->assessmentcoverage = $counts->competencies > 0
            ? (int)round(($counts->assessed / $counts->competencies) * 100) : 0;
        $counts->targetreadiness = $counts->competencies > 0
            ? (int)round(($counts->met / $counts->competencies) * 100) : 0;

        usort($rows, static function($a, $b) {
            $priorityrank = [
                business_roles::PRIORITY_CRITICAL => 1,
                business_roles::PRIORITY_CORE => 2,
                business_roles::PRIORITY_ADDITIONAL => 3,
            ];
            $statusrank = [
                self::ROW_GAP => 1,
                self::ROW_PENDING => 2,
                self::ROW_UNASSESSED => 3,
                self::ROW_MET => 4,
            ];
            $comparison = ($priorityrank[$a->priority] ?? 9) <=> ($priorityrank[$b->priority] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ((int)!$a->required) <=> ((int)!$b->required);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ($statusrank[$a->status] ?? 9) <=> ($statusrank[$b->status] ?? 9);
            if ($comparison !== 0) {
                return $comparison;
            }
            $comparison = ((int)($b->gap ?? -1)) <=> ((int)($a->gap ?? -1));
            return $comparison !== 0 ? $comparison : strcasecmp($a->shortname, $b->shortname);
        });

        if (!$roles) {
            $state = self::STATE_NO_ROLES;
        } else if (!$profile) {
            $state = self::STATE_EMPTY_PROFILE;
        } else if ($counts->unassessed > 0) {
            $state = self::STATE_NEEDS_ASSESSMENT;
        } else if ($counts->criticalgaps > 0) {
            $state = self::STATE_CRITICAL_GAPS;
        } else if ($counts->mandatorygaps > 0) {
            $state = self::STATE_REQUIRED_GAPS;
        } else if ($counts->supportinggaps > 0) {
            $state = self::STATE_SUPPORTING_GAPS;
        } else {
            $state = self::STATE_READY;
        }

        return (object)[
            'companyid' => $companyid,
            'userid' => $userid,
            'roles' => array_values($roles),
            'rows' => $rows,
            'counts' => $counts,
            'state' => $state,
            'complete' => $counts->unassessed === 0,
        ];
    }

    /** Filter a calculated projection without recalculating Moodle data. */
    public static function filter(array $rows, string $status = 'all', string $priority = 'all',
            string $kind = 'all', string $query = ''): array {
        $allowedstatuses = ['all', self::ROW_GAP, self::ROW_UNASSESSED, self::ROW_PENDING, self::ROW_MET];
        $allowedpriorities = ['all', business_roles::PRIORITY_CRITICAL,
            business_roles::PRIORITY_CORE, business_roles::PRIORITY_ADDITIONAL];
        $allowedkinds = ['all', 'mandatory', 'supporting'];
        if (!in_array($status, $allowedstatuses, true)) {
            $status = 'all';
        }
        if (!in_array($priority, $allowedpriorities, true)) {
            $priority = 'all';
        }
        if (!in_array($kind, $allowedkinds, true)) {
            $kind = 'all';
        }
        $query = \core_text::strtolower(trim($query));

        return array_values(array_filter($rows, static function($row) use ($status, $priority, $kind, $query) {
            if ($status !== 'all' && $row->status !== $status) {
                return false;
            }
            if ($priority !== 'all' && $row->priority !== $priority) {
                return false;
            }
            if ($kind === 'mandatory' && !$row->required) {
                return false;
            }
            if ($kind === 'supporting' && $row->required) {
                return false;
            }
            if ($query !== '') {
                $roles = implode(' ', array_map(static function($role) {
                    return (string)($role->name ?? '');
                }, $row->roles));
                $haystack = \core_text::strtolower(trim($row->shortname . ' ' . $row->idnumber . ' ' . $roles));
                if (\core_text::strpos($haystack, $query) === false) {
                    return false;
                }
            }
            return true;
        }));
    }
}
