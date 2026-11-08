<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only object navigation projection; authorisation stays in battle_plan_service::context(). */
final class battle_plan_navigation_service {
    /**
     * Produce safe local navigation targets from an ALREADY authorised plan context.
     * No URL from request data is accepted, and this service never grants capabilities.
     *
     * @return array{week:string, plan:string, status:string, modes:array, tabs:array, canmanage:bool}
     */
    public static function project(array $context, string $view, string $tab): array {
        $p = $context['selected'];
        $editable = !empty($context['selected_can_edit']) && (string)$p['status'] !== 'closed';
        $status = (string)$p['status'];
        $active = $view === 'metrics' ? 'indicators' : ($view === 'detail' ? $tab : '');
        $tabs = [];
        foreach (['overview', 'tasks', 'indicators', 'review', 'history'] as $key) {
            $tabs[] = ['key'=>$key, 'selected'=>$active === $key,
                'params'=>['plan'=>(string)$p['uuid'], 'view'=>'detail', 'tab'=>$key]];
        }
        $modes = [];
        if ($editable) {
            $modes[] = ['key'=>'edit', 'selected'=>$view === 'edit',
                'params'=>['plan'=>(string)$p['uuid'], 'view'=>'edit']];
            if (in_array($status, ['draft', 'active'], true)) {
                $modes[] = ['key'=>'fill', 'selected'=>$view === 'fill',
                    'params'=>['plan'=>(string)$p['uuid'], 'view'=>'fill']];
            }
        }
        return [
            'week'=>(string)$p['period_start'], 'plan'=>(string)$p['uuid'],
            'status'=>$status, 'active'=>$active, 'tabs'=>$tabs, 'modes'=>$modes,
            'canmanage'=>!empty($context['can_manage']),
            'mode'=>$view === 'action' ? 'action' : ($view === 'metrics' ? 'metrics' : ''),
        ];
    }
}
