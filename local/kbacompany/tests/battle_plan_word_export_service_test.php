<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_word_export_service;

/** DOCX export structure regression tests. */
final class battle_plan_word_export_service_test extends \basic_testcase {
    public function test_ooxml_contains_real_word_document_and_cyrillic_text(): void {
        $model = [
            'title' => 'Боевой план', 'company' => 'ООО Тест', 'employee' => 'Иван Иванов',
            'position' => 'Исполнительный директор', 'period' => '2026-09-14 — 2026-09-20',
            'status' => 'Согласован / в работе', 'reviewer' => 'Иван Иванов', 'summary' => 'Задач: 1',
            'generated' => 'Сформировано в BMS', 'observer' => '',
            'labels' => ['company'=>'Компания','employee'=>'Сотрудник','position'=>'Должность','period'=>'Период',
                'status'=>'Статус','reviewer'=>'Согласующий','observer'=>'Наблюдатель','tasks'=>'Задачи','task'=>'Задача','product'=>'Ожидаемый продукт',
                'state'=>'Статус / Приоритет','dates'=>'Срок','time'=>'План / факт, мин','result'=>'Результат'],
            'streams' => [['key'=>'operate','label'=>'Операционная работа','tasks'=>[[
                'title'=>'Подготовить отчёт','product'=>'Утверждённый отчёт','status'=>'В работе','priority'=>'Обычный',
                'dates'=>'2026-09-18','time'=>'60 / 30','result'=>'',
            ]]]],
        ];
        $files = battle_plan_word_export_service::ooxml_files($model);
        $this->assertArrayHasKey('[Content_Types].xml', $files);
        $this->assertArrayHasKey('word/document.xml', $files);
        $this->assertStringContainsString('wordprocessingml.document.main+xml', $files['[Content_Types].xml']);
        $this->assertStringContainsString('Боевой план', $files['word/document.xml']);
        $this->assertStringContainsString('Подготовить отчёт', $files['word/document.xml']);
        $this->assertStringContainsString('orient="landscape"', $files['word/document.xml']);
    }

    public function test_canonical_metric_table_uses_six_battle_plan_indicator_columns(): void {
        $model = [
            'title' => 'Боевой план', 'company' => 'ООО Тест', 'employee' => 'Иван Иванов',
            'position' => 'РОП', 'period' => '2026-09-14 — 2026-09-20', 'status' => 'В работе',
            'reviewer' => 'Руководитель', 'observer' => '', 'summary' => 'Задач: 0', 'generated' => 'BMS',
            'metric_mode' => 'canonical',
            'metrics' => [[
                'name' => 'KPI-1 — Договоры', 'previous' => '90', 'quota' => '100',
                'condition' => 'Нормальная деятельность', 'fact' => '105', 'trend' => 'Устойчивый рост · Улучшение',
            ]],
            'overall_condition' => ['performance'=>null,'condition'=>''],
            'labels' => [
                'company'=>'Компания','employee'=>'Сотрудник','position'=>'Должность','period'=>'Период',
                'status'=>'Статус','reviewer'=>'Согласующий','observer'=>'Наблюдатель','tasks'=>'Задачи',
                'task'=>'Задача','product'=>'Ожидаемый продукт','state'=>'Статус / Приоритет','dates'=>'Срок',
                'time'=>'План / факт, мин','result'=>'Результат','metrics'=>'Показатели должности',
                'metric'=>'Показатель','previous'=>'Прошлая неделя','quota'=>'Квота / норма','condition'=>'Состояние',
                'fact'=>'Факт текущей недели','performance'=>'Исполнение','trend'=>'Динамика',
            ],
            'streams' => [],
        ];
        $files = battle_plan_word_export_service::ooxml_files($model);
        $xml = $files['word/document.xml'];
        $this->assertStringContainsString('Показатели должности', $xml);
        $this->assertStringContainsString('KPI-1 — Договоры', $xml);
        $this->assertStringContainsString('Устойчивый рост · Улучшение', $xml);
        $this->assertStringNotContainsString('Исполнение', $xml);
    }

    /** Previous-week actual is a canonical fact, not copied from the old Battle Plan document. */
    public function test_previous_week_actual_and_target_are_rendered_without_shift(): void {
        $model = [
            'title'=>'Боевой план','company'=>'Бизнес Академия','employee'=>'Сотрудник',
            'position'=>'Коммерческий директор','period'=>'2026-10-12 — 2026-10-18',
            'status'=>'Черновик','reviewer'=>'Руководитель','observer'=>'',
            'summary'=>'','generated'=>'BMS24','metric_mode'=>'canonical',
            'metrics'=>[[
                'name'=>'001 — Выручка (руб.)','previous'=>'42000,00 (Факт за 1 из 7 дней)',
                'quota'=>'100000,00','condition'=>'Не определено','fact'=>'62000,00',
                'trend'=>'Недостаточно данных',
            ]],
            'labels'=>[
                'employee'=>'Сотрудник','position'=>'Должность','period'=>'Период',
                'status'=>'Статус','reviewer'=>'Руководитель','observer'=>'Наблюдатель',
                'metrics'=>'Метрики','metric'=>'Показатель','previous'=>'Прошлая неделя',
                'quota'=>'Квота / план','condition'=>'Состояние','fact'=>'Факт',
                'trend'=>'Динамика',
            ],
            'overall_condition'=>['performance'=>null,'condition'=>''], 'streams'=>[],
        ];
        $xml = battle_plan_word_export_service::ooxml_files($model)['word/document.xml'];
        $this->assertStringContainsString('Прошлая неделя', $xml);
        $this->assertStringContainsString('Факт за 1 из 7 дней', $xml);
        $this->assertStringContainsString('42000,00', $xml);
        $this->assertStringContainsString('62000,00', $xml);
        $this->assertStringContainsString('Квота / план', $xml);
    }

    public function test_model_projects_previous_partial_week_and_current_target(): void {
        $metric = [
            'code'=>'001', 'name'=>'Выручка', 'unit'=>'руб.',
            'previous_fact'=>42000.0, 'previous_fact_days'=>1, 'daily_aggregate'=>true,
            'current_plan'=>120000.0, 'current_fact'=>62000.0,
            'norm_label'=>'', 'condition'=>'',
            'trend'=>['movement'=>'insufficient', 'quality'=>'insufficient'],
            'task'=>['visible'=>false], 'previous_action'=>['exists'=>false],
        ];
        $context = [
            'selected'=>['user_id'=>123, 'position_uuid'=>'test-position',
                'reviewer_id'=>124, 'observer_id'=>0,
                'period_start'=>'2026-10-12', 'period_end'=>'2026-10-18', 'status'=>'draft'],
            'company'=>['name'=>'Бизнес Академия'],
            'employees'=>[123=>'Николай Лаптев', 124=>'Руководитель'],
            'positions'=>['test-position'=>'Коммерческий директор'],
            'summary'=>[], 'position_indicators'=>[$metric],
        ];
        $model = battle_plan_word_export_service::model($context);
        $this->assertCount(1, $model['metrics']);
        $this->assertStringContainsString('Выручка (руб.)', $model['metrics'][0]['name']);
        $this->assertStringContainsString('42', $model['metrics'][0]['previous']);
        $this->assertSame(format_float(42000.0, 2) . ' (' . get_string('bpu_metric_previous_fact_days', 'local_kbacompany', 1) . ')', $model['metrics'][0]['previous']);
        $this->assertStringContainsString('120', $model['metrics'][0]['quota']);
        $this->assertStringContainsString('62', $model['metrics'][0]['fact']);
        $this->assertSame('12.10.2026 — 18.10.2026', $model['period']);
    }

    public function test_signature_block_contains_recorded_people_positions_and_action_times(): void {
        $model = [
            'title'=>'Боевой план', 'company'=>'Бизнес Академия', 'employee'=>'Николай Лаптев',
            'position'=>'Коммерческий директор', 'period'=>'12.10.2026 — 18.10.2026',
            'status'=>'Согласован', 'reviewer'=>'Риман Фатыхов', 'observer'=>'',
            'summary'=>'', 'generated'=>'BMS24', 'metrics'=>[], 'streams'=>[],
            'labels'=>['employee'=>'Сотрудник','position'=>'Должность','period'=>'Период',
                'status'=>'Статус','reviewer'=>'Руководитель','observer'=>'Наблюдатель',
                'prepared'=>'План подготовил','approved'=>'План одобрил',
                'signature_position'=>'Должность','signature_name'=>'ФИО',
                'signature_date'=>'Дата и время','signature_pending'=>'Не одобрен'],
            'prepared_signature'=>['position'=>'Коммерческий директор', 'name'=>'Николай Лаптев',
                'datetime'=>'12.10.2026 08:30'],
            'approved_signature'=>['position'=>'Генеральный директор','name'=>'Риман Фатыхов',
                'datetime'=>'12.10.2026 09:45'],
        ];
        $xml = battle_plan_word_export_service::ooxml_files($model)['word/document.xml'];
        foreach (['План подготовил','План одобрил','Николай Лаптев','Риман Фатыхов',
            'Коммерческий директор','Генеральный директор','12.10.2026 08:30',
            '12.10.2026 09:45'] as $expected) {
            $this->assertStringContainsString($expected, $xml);
        }
        $this->assertStringNotContainsString('Не одобрен', $xml);
        // Without an actual approval event the identity of a suggested reviewer is NOT a signature.
        $model['approved_signature'] = ['position'=>'','name'=>'','datetime'=>''];
        $xml = battle_plan_word_export_service::ooxml_files($model)['word/document.xml'];
        $this->assertStringContainsString('Не одобрен', $xml);
        $this->assertStringNotContainsString('Генеральный директор', $xml);
    }

}
