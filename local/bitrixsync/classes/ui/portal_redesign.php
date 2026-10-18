<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class portal_redesign {

    public function get(): array {
        return [
            'cards' => [
                ['title'=>'Deal','value'=>'3761','status'=>'READY'],
                ['title'=>'Company','value'=>'ООО АЛТРЕЙД','status'=>'READY'],
                ['title'=>'Courses','value'=>'8,2,13','status'=>'MAPPED'],
                ['title'=>'Corporate','value'=>'ID 1','status'=>'READY'],
            ],
            'navigation' => [
                'planner',
                'approval',
                'execution',
                'timeline',
                'metrics',
                'sla',
                'history',
                'diagnostics',
                'recovery'
            ]
        ];
    }
}
