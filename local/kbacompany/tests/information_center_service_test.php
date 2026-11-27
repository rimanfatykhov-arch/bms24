<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\information_center_service;
use local_kbacorporate\local\repository;

/**
 * Information Centre by divisions (#190): board order, bindings from the registry, company isolation and the
 * chart model (gaps, empty series, single point, real zero, trend text).
 *
 * @covers \local_kbacompany\local\information_center_service
 */
final class information_center_service_test extends \advanced_testcase {
    private function company(string $name): array {
        $companyid = repository::save_company((object)['name' => $name, 'active' => 1]);
        return [$companyid, identity_registry::company_uuid($companyid)];
    }

    private function node(string $company, string $branch, string $uuid, string $parent, string $type, string $code,
            int $sort): void {
        global $DB;
        $DB->insert_record('local_kbacompany_orgnode', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'uuid' => $uuid, 'parentuuid' => $parent, 'code' => $code, 'nodetype' => $type, 'name' => 'Node ' . $code,
            'sortorder' => $sort, 'color' => '#0C6C52', 'sourcecomponent' => '', 'sourceentity' => '', 'sourceid' => 0,
            'active' => 1, 'timecreated' => time(), 'timemodified' => time()]);
    }

    private function stat(string $company, string $uuid, string $code, string $direction = 'up'): void {
        global $DB;
        $DB->insert_record('local_kbacompany_stat', (object)['companyuuid' => $company, 'uuid' => $uuid,
            'productuuid' => '', 'code' => $code, 'name' => 'Metric ' . $code, 'unit' => 'pcs', 'periodtype' => 'weekly',
            'direction' => $direction, 'timecreated' => time()]);
    }

    private function bind(string $company, string $uuid, string $stat, string $kind, string $context, int $primary = 0): void {
        global $DB;
        $DB->insert_record('local_kbacompany_statbind', (object)['companyuuid' => $company, 'uuid' => $uuid,
            'revision' => 1, 'status' => 'active', 'statuuid' => $stat, 'contextkind' => $kind, 'contextuuid' => $context,
            'contextrevision' => 0, 'isprimary' => $primary, 'critical' => 0, 'weight' => 1, 'sortorder' => 0,
            'createdby' => 2, 'timecreated' => time() - 3600]);
    }

    private function fact(string $company, string $stat, string $period, $amount, string $kind = 'fact'): void {
        global $DB;
        $DB->insert_record('local_kbacompany_statval', (object)['companyuuid' => $company, 'statuuid' => $stat,
            'periodstart' => $period, 'kind' => $kind, 'amount' => $amount, 'revision' => 1, 'reason' => '',
            'writerid' => 2, 'timecreated' => time()]);
    }

    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$companyid, $company] = $this->company('Info centre A');
        [, $other] = $this->company('Info centre B');
        $branch = '71000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_orgbranch', (object)['companyuuid' => $company, 'uuid' => $branch,
            'code' => 'MAIN', 'name' => 'Main', 'isdefault' => 1, 'active' => 1, 'sortorder' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $divsecond = '71000000-0000-4000-8000-000000000010';
        $divfirst = '71000000-0000-4000-8000-000000000011';
        $department = '71000000-0000-4000-8000-000000000012';
        // Inserted out of order on purpose: board order comes from sortorder, not insertion.
        $this->node($company, $branch, $divsecond, '', 'division', '2.0.0', 30);
        $this->node($company, $branch, $divfirst, '', 'division', '1.0.0', 20);
        $this->node($company, $branch, $department, $divfirst, 'department', '1.1.0', 10);
        $position = '71000000-0000-4000-8000-000000000020';
        $DB->insert_record('local_kbacompany_position', (object)['companyuuid' => $company, 'branchuuid' => $branch,
            'orgnodeuuid' => $department, 'uuid' => $position, 'code' => 'P-1', 'name' => 'Sales lead',
            'purpose' => 'Sell', 'ismanager' => 0, 'plannedcount' => 1, 'sortorder' => 0, 'active' => 1,
            'timecreated' => time(), 'timemodified' => time()]);
        $main = '72000000-0000-4000-8000-000000000001';
        $posstat = '72000000-0000-4000-8000-000000000002';
        $divstat = '72000000-0000-4000-8000-000000000003';
        $loose = '72000000-0000-4000-8000-000000000004';
        $foreign = '72000000-0000-4000-8000-000000000005';
        $this->stat($company, $main, 'M-1');
        $this->stat($company, $posstat, 'P-1');
        $this->stat($company, $divstat, 'D-1', 'down');
        $this->stat($company, $loose, 'L-1');
        $this->stat($other, $foreign, 'F-1');
        $this->bind($company, '73000000-0000-4000-8000-000000000001', $main, 'company', $company, 1);
        $this->bind($company, '73000000-0000-4000-8000-000000000002', $posstat, 'position', $position);
        $this->bind($company, '73000000-0000-4000-8000-000000000003', $divstat, 'orgnode', $divsecond);
        // A binding row of another company that points at this company's node must never leak in.
        $this->bind($other, '73000000-0000-4000-8000-000000000004', $foreign, 'orgnode', $divfirst);
        return compact('companyid', 'company', 'other', 'divfirst', 'divsecond', 'department', 'position',
            'main', 'posstat', 'divstat', 'loose', 'foreign');
    }

    public function test_overview_follows_board_order_and_registry_bindings(): void {
        global $USER;
        $f = $this->fixture();
        $view = information_center_service::overview($f['company'], (int)$USER->id);
        $this->assertTrue($view['has_board']);
        $this->assertSame([$f['main']], array_column($view['main'], 'uuid'));
        $this->assertSame([$f['divfirst'], $f['divsecond']], array_column($view['divisions'], 'uuid'));

        $first = $view['divisions'][0];
        $this->assertSame(1, $first['metric_count']);
        $this->assertSame('position', $first['groups'][0]['kind']);
        $this->assertSame($f['position'], $first['groups'][0]['uuid']);
        $this->assertSame([$f['posstat']], array_column($first['groups'][0]['metrics'], 'uuid'));

        $second = $view['divisions'][1];
        $this->assertSame('orgnode', $second['groups'][0]['kind']);
        $this->assertSame('division', $second['groups'][0]['nodetype']);
        $this->assertSame([$f['divstat']], array_column($second['groups'][0]['metrics'], 'uuid'));

        $this->assertSame([$f['loose']], array_column($view['unbound'], 'uuid'));
    }

    public function test_overview_is_scoped_to_the_company(): void {
        global $USER;
        $f = $this->fixture();
        $view = information_center_service::overview($f['company'], (int)$USER->id);
        $all = array_merge(array_column($view['main'], 'uuid'), array_column($view['unbound'], 'uuid'));
        foreach ($view['divisions'] as $division) {
            foreach ($division['groups'] as $group) {
                $all = array_merge($all, array_column($group['metrics'], 'uuid'));
            }
        }
        $this->assertNotContains($f['foreign'], $all);

        $other = information_center_service::overview($f['other'], (int)$USER->id);
        $this->assertFalse($other['has_board']);
        $this->assertSame([$f['foreign']], array_column($other['unbound'], 'uuid'));
    }

    public function test_non_member_cannot_open_the_company(): void {
        $f = $this->fixture();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $this->expectException(\moodle_exception::class);
        information_center_service::overview($f['company'], (int)$stranger->id);
    }

    public function test_series_gaps_zero_single_and_empty(): void {
        global $USER;
        $f = $this->fixture();
        $this->fact($f['company'], $f['posstat'], '2026-09-07', 5);
        $this->fact($f['company'], $f['posstat'], '2026-09-14', 0);
        // 2026-09-21 missing: a gap marker must split the line.
        $this->fact($f['company'], $f['posstat'], '2026-09-28', 7);
        $this->fact($f['company'], $f['posstat'], '2026-09-28', 6, 'plan');
        $this->fact($f['company'], $f['divstat'], '2026-09-28', 3);

        $view = information_center_service::overview($f['company'], (int)$USER->id);
        $card = $view['divisions'][0]['groups'][0]['metrics'][0];
        $this->assertSame(['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'], array_column($card['points'], 'period'));
        $this->assertSame([false, false, true, false], array_column($card['points'], 'gap'));
        $this->assertSame(0.0, $card['points'][1]['fact'], 'A real zero is a value, not missing data.');
        $this->assertSame(6.0, $card['points'][3]['plan']);
        $this->assertSame(3, $card['fact_count']);
        $geo = information_center_service::mini_geometry($card['mini']);
        $this->assertFalse($geo['empty']);
        $this->assertCount(1, $geo['segments'], 'Only 5 → 0 is joined; 7 stands alone after the gap.');
        $this->assertCount(3, $geo['dots']);

        $single = $view['divisions'][1]['groups'][0]['metrics'][0];
        $geo = information_center_service::mini_geometry($single['mini']);
        $this->assertCount(1, $geo['dots']);
        $this->assertSame([], $geo['segments'], 'One point is drawn without a trend line.');
        $this->assertSame('insufficient', $single['trend']['movement']);

        $empty = $view['unbound'][0];
        $this->assertSame([], $empty['points']);
        $this->assertTrue(information_center_service::mini_geometry($empty['mini'])['empty']);
    }

    public function test_period_steps_and_tail(): void {
        $this->assertSame('2026-10-05', information_center_service::next_period('2026-09-28', 'weekly'));
        $this->assertSame('2026-03-01', information_center_service::next_period('2026-02-01', 'monthly'));
        $this->assertSame('2027-01-01', information_center_service::next_period('2026-10-01', 'quarterly'));
        $this->assertSame('2027-01-01', information_center_service::next_period('2026-01-01', 'annual'));
        $this->assertSame('2026-09-29', information_center_service::next_period('2026-09-28', 'daily'));
        $this->assertNull(information_center_service::next_period('not-a-date', 'weekly'));
        $this->assertNull(information_center_service::next_period('2026-09-28', 'unknown'));

        $points = information_center_service::points([
            ['period' => '2026-01-01', 'fact' => '1', 'plan' => null],
            ['period' => '2026-03-01', 'fact' => '3', 'plan' => null],
            ['period' => '2026-04-01', 'fact' => '-2', 'plan' => null],
        ], 'monthly');
        $this->assertSame([false, true, false, false], array_column($points, 'gap'));
        $this->assertSame(-2.0, $points[3]['fact']);
        $tail = information_center_service::tail($points, 2);
        $this->assertSame(['2026-03-01', '2026-04-01'], array_column($tail, 'period'));
    }

    public function test_trend_view_uses_symbol_and_words_and_metric_direction(): void {
        $up = ['movement' => 'sustained_up', 'quality' => 'improving', 'fact_count' => 3];
        $view = information_center_service::trend_view($up, 'up');
        $this->assertSame('↑', $view['symbol']);
        $this->assertSame('infocenter2_trend_up', $view['key']);
        $this->assertSame('infocenter2_quality_better', $view['assessment']);

        $down = information_center_service::trend_view(['movement' => 'sustained_up', 'quality' => 'worsening',
            'fact_count' => 3], 'down');
        $this->assertSame('infocenter2_quality_worse', $down['assessment']);

        $neutral = information_center_service::trend_view(['movement' => 'sustained_down', 'quality' => 'neutral',
            'fact_count' => 3], 'neutral');
        $this->assertSame('↓', $neutral['symbol']);
        $this->assertSame('infocenter2_quality_neutral', $neutral['assessment']);

        $mixed = information_center_service::trend_view(['movement' => 'mixed', 'quality' => 'neutral',
            'fact_count' => 3], 'up');
        $this->assertSame('≈', $mixed['symbol']);
        $this->assertSame('', $mixed['assessment']);
    }

    public function test_flat_series_and_negative_values_produce_a_valid_mini_chart(): void {
        $flat = information_center_service::points([
            ['period' => '2026-09-07', 'fact' => '4', 'plan' => null],
            ['period' => '2026-09-14', 'fact' => '4', 'plan' => null],
        ], 'weekly');
        $geo = information_center_service::mini_geometry($flat);
        $this->assertCount(1, $geo['segments']);
        $this->assertSame($geo['dots'][0]['y'], $geo['dots'][1]['y']);
        $this->assertGreaterThan(8.0, $geo['dots'][0]['y']);
        $this->assertLessThan(86.0, $geo['dots'][0]['y']);

        $negative = information_center_service::points([
            ['period' => '2026-09-07', 'fact' => '-5', 'plan' => null],
            ['period' => '2026-09-14', 'fact' => '0', 'plan' => null],
            ['period' => '2026-09-21', 'fact' => '5', 'plan' => null],
        ], 'weekly');
        $geo = information_center_service::mini_geometry($negative);
        $this->assertSame(86.0, $geo['dots'][0]['y']);
        $this->assertSame(8.0, $geo['dots'][2]['y']);
    }

    public function test_only_safe_colours_reach_the_style_attribute(): void {
        $this->assertSame('#0C6C52', information_center_service::safe_color('#0C6C52'));
        $this->assertSame('#abc', information_center_service::safe_color('#abc'));
        $this->assertSame('', information_center_service::safe_color('red;background:url(x)'));
        $this->assertSame('', information_center_service::safe_color(''));
    }
}
