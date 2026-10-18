<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Process-owned screen composition using the shared BMS CRUD primitives. No DB writes. */
class process_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = [], array $context = [], string $route = 'processes'): \moodle_url {
        return new \moodle_url('/local/kbacompany/' . $route . '.php', ['uuid'=>$company] + $params + $context);
    }
    private static function link($url, string $label, bool $primary = false): string { return bms_workspace_ux_service::record_link($url,$label,$primary); }
    private static function note(string $text): string { return bms_workspace_ux_service::record_note($text); }
    private static function panel(string $title, string $body): string { return bms_workspace_ux_service::record_panel($title,$body); }
    private static function control(string $name, string $label, string $value, array $spec = [], string $error = ''): string {
        return bms_workspace_ux_service::form_control($name,$label,$value,$spec,$error);
    }
    private static function form_state(): string {
        return \html_writer::tag('p',s(self::tr('pru_formready')),['class'=>'bms-pdu-note','role'=>'status','aria-live'=>'polite',
            'data-pru-form-state'=>'1','data-dirty'=>self::tr('pru_formdirty'),'data-saving'=>self::tr('pru_formsaving')]);
    }
    public static function types(): array {
        $out=[]; foreach (process_service::TYPES as $type) { $out[$type]=self::tr('process_type_'.$type); } return $out;
    }
    private static function status(array $p): string {
        return \html_writer::span(s(self::tr('process_status_'.$p['status'])), 'bms-pdu-badge '.($p['status']==='published'?'bms-pdu-active':'bms-pdu-muted'));
    }
    public static function pager(string $company, array $data, array $params = [], string $pagekey = 'page'): string {
        if ($data['total'] <= $data['pagesize']) { return ''; }
        $page=$data['page']; $out='';
        if ($page>0) { $out.=self::link(self::url($company,[$pagekey=>$page-1],$params),self::tr('pdu_previous')); }
        $out.=self::note(self::tr('pdu_page',(object)['page'=>$page+1,'pages'=>(int)ceil($data['total']/$data['pagesize'])]));
        if (($page+1)*$data['pagesize']<$data['total']) { $out.=self::link(self::url($company,[$pagekey=>$page+1],$params),self::tr('pdu_next')); }
        return \html_writer::tag('nav',$out,['class'=>'bms-pdu-actions bms-pdu-pager','aria-label'=>self::tr('pdu_pagination')]);
    }
    private static function process_name(string $company, array $p, array $ctx): string {
        return \html_writer::link(self::url($company,['process'=>$p['uuid']],$ctx),s($p['code'].' — '.$p['name']),['class'=>'bms-pdu-record-link']);
    }
    private static function parent_name(string $company, array $p, array $ctx): string {
        if ($p['parent_uuid']==='') { return ''; }
        return self::note(self::tr('pru_parent').':') . ($p['parent_name']!=='' ? \html_writer::link(self::url($company,['process'=>$p['parent_uuid']],$ctx),s($p['parent_code'].' — '.$p['parent_name'])) : self::note(self::tr('pru_unavailable')));
    }
    private static function menu(string $company, array $p, array $ctx, bool $manager): string {
        if (!$manager || $p['status']==='archived') { return ''; }
        $items=[[self::url($company,['process'=>$p['uuid'],'view'=>'edit'],$ctx),self::tr('pru_edit')]];
        if ($p['level']!=='L2') { $items[]=[self::url($company,['view'=>'create','parent'=>$p['uuid']],$ctx),self::tr('pru_subprocess')]; }
        $items[]=[self::url($company,['process'=>$p['uuid'],'view'=>'delete'],$ctx),self::tr('pru_delete'),'bms-pdu-destructive'];
        return bms_workspace_ux_service::record_menu(self::tr('pru_actions'),$items);
    }
    private static function architecture_health_label(string $health): string {
        $key=['ready'=>'pru_arch_health_ready','warning'=>'pru_arch_health_warning','critical'=>'pru_arch_health_critical',
            'empty'=>'pru_arch_health_empty'][$health]??'pru_arch_health_warning';
        return self::tr($key);
    }
    private static function architecture_node(string $company, array $p, array $nodes, array $ctx, bool $manager,
            array $seen=[]): string {
        if (isset($seen[$p['uuid']])) { return ''; } $seen[$p['uuid']]=true;
        $a=$p['architecture'];$health=(string)$a['health'];$notation=(string)($p['idef0_notation']??'A?');
        $title=\html_writer::span(s($notation),'pru-arch-notation').
            \html_writer::link(self::url($company,['process'=>$p['uuid']],$ctx),s($p['code'].' — '.$p['name']),['class'=>'pru-arch-title']);
        $head=\html_writer::div($title.\html_writer::span(s(self::architecture_health_label($health)),
            'pru-arch-health is-'.$health),'pru-arch-node-head');
        $meta=[];$meta[]=self::tr('process_level').' '.$p['level'];
        $meta[]=self::tr('process_owner').': '.($p['owner_name']?:self::tr('pru_notassigned'));
        $meta[]=self::tr('process_product').': '.($p['product_name']?:self::tr('pru_notset'));
        $chips=self::status($p);
        $modelstatus=(string)$a['model_status'];
        $chips.=\html_writer::span(s(self::tr($modelstatus==='published'?'pru_arch_model_published':($modelstatus==='draft'?'pru_arch_model_draft':'pru_arch_model_none'))),
            'pru-arch-chip'.($modelstatus==='published'?' is-ok':' is-warning'));
        if ($modelstatus!=='none') {
            $state=(string)$a['consistency_status'];
            $label=$state==='consistent'?self::tr('pru_arch_sipoc_ok',(object)['score'=>$a['consistency_score']]):
                self::tr('pru_arch_sipoc_issue',(object)['score'=>$a['consistency_score']]);
            $chips.=\html_writer::span(s($label),'pru-arch-chip'.($state==='consistent'?' is-ok':' is-warning'));
        }
        if (!empty($a['boundary']['applicable'])) {
            $chips.=\html_writer::span(s(self::tr('pru_arch_boundary',(object)['score'=>$a['boundary']['score']])),
                'pru-arch-chip'.((int)$a['boundary']['issues']===0?' is-ok':' is-danger'));
        }
        $actions=\html_writer::link(self::url($company,['process'=>$p['uuid'],'tab'=>'idef0'],$ctx),s('IDEF0'),['class'=>'pru-arch-link']).
            \html_writer::link(self::url($company,['process'=>$p['uuid'],'tab'=>'sipoc'],$ctx),s('SIPOC'),['class'=>'pru-arch-link']).
            \html_writer::link(self::url($company,['process'=>$p['uuid']],[],'process_document'),s(self::tr('processdoc_short')),['class'=>'pru-arch-link']);
        if ($manager && $p['status']!=='archived') { $actions.=self::menu($company,$p,$ctx,$manager); }
        $body=$head.\html_writer::div(s(implode(' · ',$meta)),'pru-arch-meta').
            \html_writer::div($chips,'pru-arch-chips').
            \html_writer::div($actions,'pru-arch-actions');
        $children='';
        foreach ($nodes as $child) {
            if ($child['parent_uuid']===$p['uuid']) { $children.=self::architecture_node($company,$child,$nodes,$ctx,$manager,$seen); }
        }
        $branch=\html_writer::tag('article',$body,[
            'class'=>'pru-arch-node is-'.$health.' level-'.strtolower($p['level']),
            'data-level'=>$p['level'],
            'data-health'=>$health,
        ]).($children!==''?\html_writer::tag('ul',$children,['class'=>'pru-arch-children']):'');
        return \html_writer::tag('li',\html_writer::div($branch,'pru-arch-branch'),[
            'class'=>'pru-arch-item level-'.strtolower($p['level']),
        ]);
    }
    private static function architecture_workspace_view(string $company, array $m, array $ctx): string {
        $arch=$m['architecture'];$summary=$arch['summary'];
        $metric=static function(string $label, int $value, string $class=''): string {
            return \html_writer::div(\html_writer::div((string)$value,'pru-arch-kpi-value').
                \html_writer::div(s($label),'pru-arch-kpi-label'),'pru-arch-kpi '.$class);
        };
        $out=\html_writer::div(
            $metric(self::tr('pru_arch_total'),(int)$summary['total']).
            $metric(self::tr('pru_arch_published'),(int)$summary['published'],'is-ok').
            $metric(self::tr('pru_arch_modelled'),(int)$summary['modelled']).
            $metric(self::tr('pru_arch_ready'),(int)$summary['ready'],'is-ok').
            $metric(self::tr('pru_arch_attention'),(int)$summary['attention'],'is-warning'),
            'pru-arch-kpis');
        $legend='';
        foreach (['ready','warning','critical','empty'] as $state) {
            $legend.=\html_writer::span(s(self::architecture_health_label($state)),'pru-arch-legend-item is-'.$state);
        }
        $out.=\html_writer::div(\html_writer::div(s(self::tr('pru_arch_workspace_help')),'pru-arch-help').
            \html_writer::div($legend,'pru-arch-legend'),'pru-arch-intro');
        $nodes=array_values($arch['nodes']);$map=[];foreach ($nodes as $node) { $map[$node['uuid']]=$node; }
        $groups='';
        foreach (self::types() as $type=>$label) {
            if ($m['filters']['kind']!=='' && $m['filters']['kind']!==$type) { continue; }
            $roots=[];$shown=0;
            foreach ($nodes as $node) {
                if ($node['type']===$type && !empty($node['matched'])) { $shown++; }
                if (($node['parent_uuid']==='' || !isset($map[$node['parent_uuid']])) && $node['type']===$type) { $roots[]=$node; }
            }
            if (!$roots && $shown===0) { continue; }
            $head=\html_writer::tag('h2',s($label)).self::note(self::tr('pru_groupcount',(object)['shown'=>$shown,'total'=>$m['matches'][$type]]));
            if ($m['can_manage']) { $head.=self::link(self::url($company,['view'=>'create','presettype'=>$type],$ctx),'+ '.self::tr('pru_create')); }
            $tree='';foreach ($roots as $root) { $tree.=self::architecture_node($company,$root,$nodes,$ctx,$m['can_manage']); }
            if ($tree==='') { $tree=\html_writer::tag('li',self::note(self::tr('pru_emptygroup'))); }
            $groups.=\html_writer::tag('section',\html_writer::div($head,'pru-grouphead').
                \html_writer::tag('ul',$tree,['class'=>'pru-arch-tree']),['class'=>'pru-group pru-arch-group']);
        }
        $canvashead=\html_writer::div(
            \html_writer::tag('h2',s(self::tr('pru_arch_map_title'))).
            \html_writer::div(s(self::tr('pru_arch_map_hint')),'pru-arch-map-hint'),
            'pru-arch-map-head');
        $out.=\html_writer::div($canvashead.\html_writer::div($groups,'pru-arch-canvas'),
            'pru-arch-canvas-shell');
        return $out;
    }

    private static function architecture_issue_label(string $issue): string {
        $key=[
            'unpublished'=>'pru_analytics_issue_unpublished','owner_missing'=>'pru_analytics_issue_owner',
            'product_missing'=>'pru_analytics_issue_product','model_missing'=>'pru_analytics_issue_model',
            'model_draft'=>'pru_analytics_issue_modeldraft','consistency_formalisation'=>'pru_analytics_issue_formalisation',
            'consistency_mismatch'=>'pru_analytics_issue_consistency','boundary_issues'=>'pru_analytics_issue_boundary',
        ][$issue]??'pru_analytics_issue_model';
        return self::tr($key);
    }
    private static function architecture_analytics_view(string $company, array $m, array $ctx): string {
        $a=$m['analytics'];$summary=$a['summary'];
        $metric=static function(string $label,string $value,string $class=''): string {
            return \html_writer::div(\html_writer::div(s($value),'pru-analytics-kpi-value').
                \html_writer::div(s($label),'pru-analytics-kpi-label'),'pru-analytics-kpi '.$class);
        };
        $out=\html_writer::div(
            $metric(self::tr('pru_analytics_maturity'),$summary['maturity_score'].'%','is-primary').
            $metric(self::tr('pru_analytics_total'),(string)$summary['total']).
            $metric(self::tr('pru_analytics_ready'),(string)$summary['ready'],'is-ok').
            $metric(self::tr('pru_analytics_attention'),(string)$summary['attention'],'is-warning').
            $metric(self::tr('pru_analytics_critical'),(string)$summary['critical'],'is-danger'),
            'pru-analytics-kpis');
        $out.=\html_writer::div(s(self::tr('pru_analytics_help')),'pru-analytics-intro');
        $coverage='';
        $coverlabels=['published'=>'pru_analytics_coverage_published','owner'=>'pru_analytics_coverage_owner',
            'product'=>'pru_analytics_coverage_product','model'=>'pru_analytics_coverage_model',
            'consistency'=>'pru_analytics_coverage_consistency','boundary'=>'pru_analytics_coverage_boundary'];
        foreach ($coverlabels as $key=>$labelkey) {
            $item=$a['coverage'][$key];$percent=(int)$item['percent'];
            $coverage.=\html_writer::div(
                \html_writer::div(\html_writer::span(s(self::tr($labelkey)),'pru-analytics-coverage-label').
                    \html_writer::span(s($percent.'%'),'pru-analytics-coverage-value'),'pru-analytics-coverage-head').
                \html_writer::div(\html_writer::span('', 'pru-analytics-progress-fill', ['style'=>'width:'.$percent.'%']),
                    'pru-analytics-progress',['aria-hidden'=>'true']).
                self::note(self::tr('pru_analytics_coverage_count',(object)['covered'=>$item['covered'],'total'=>$item['total']])),
                'pru-analytics-coverage-card');
        }
        $out.=self::panel(self::tr('pru_analytics_coverage'),\html_writer::div($coverage,'pru-analytics-coverage-grid'));
        $typerows=[];
        foreach (self::types() as $type=>$label) {
            $x=$a['by_type'][$type];
            $typerows[]=[s($label),s((string)$x['total']),s((string)$x['ready']),s((string)$x['attention']),s($x['score'].'%')];
        }
        $levelrows=[];
        foreach (process_service::LEVELS as $level) {
            $x=$a['by_level'][$level];
            $levelrows[]=[s($level),s((string)$x['total']),s((string)$x['ready']),s((string)$x['attention']),s($x['score'].'%')];
        }
        $tables=\html_writer::div(self::panel(self::tr('pru_analytics_by_type'),bms_workspace_ux_service::record_table([
                self::tr('process_type'),self::tr('pru_analytics_total'),self::tr('pru_analytics_ready'),self::tr('pru_analytics_attention'),self::tr('pru_analytics_maturity')],$typerows)).
            self::panel(self::tr('pru_analytics_by_level'),bms_workspace_ux_service::record_table([
                self::tr('process_level'),self::tr('pru_analytics_total'),self::tr('pru_analytics_ready'),self::tr('pru_analytics_attention'),self::tr('pru_analytics_maturity')],$levelrows)),
            'pru-analytics-breakdown');
        $out.=$tables;
        $issues='';
        foreach ($a['issue_counts'] as $key=>$count) {
            if (!$count) { continue; }
            $issues.=\html_writer::span(s(self::architecture_issue_label($key).' · '.$count),'pru-analytics-issue-chip');
        }
        if ($issues==='') { $issues=\html_writer::span(s(self::tr('pru_analytics_noissues')),'pru-analytics-issue-chip is-ok'); }
        $out.=self::panel(self::tr('pru_analytics_issues'),\html_writer::div($issues,'pru-analytics-issue-cloud'));
        $rows=[];
        foreach (array_slice($a['priorities'],0,50) as $p) {
            $labels=[];foreach ($p['issues'] as $issue) { $labels[]=self::architecture_issue_label($issue); }
            $actions=\html_writer::link(self::url($company,['process'=>$p['uuid']],$ctx),s(self::tr('pru_open')),['class'=>'pru-arch-link']).
                \html_writer::link(self::url($company,['process'=>$p['uuid'],'tab'=>'idef0'],$ctx),s('IDEF0'),['class'=>'pru-arch-link']).
                \html_writer::link(self::url($company,['process'=>$p['uuid']],[],'process_document'),s(self::tr('processdoc_short')),['class'=>'pru-arch-link']);
            $rows[]=[\html_writer::span(s($p['notation']),'pru-arch-notation').' '.s($p['code'].' — '.$p['name']),
                s($p['level'].' · '.self::types()[$p['type']]),s($p['score'].'%'),s(implode(' · ',$labels)),
                \html_writer::div($actions,'pru-arch-actions')];
        }
        if ($rows) {
            $out.=self::panel(self::tr('pru_analytics_priorities'),self::note(self::tr('pru_analytics_priorities_help')).
                bms_workspace_ux_service::record_table([self::tr('process_name'),self::tr('pru_analytics_slice'),
                    self::tr('pru_analytics_maturity'),self::tr('pru_analytics_issues'),self::tr('pru_actions')],$rows));
        } else {
            $out.=self::panel(self::tr('pru_analytics_priorities'),self::note(self::tr('pru_analytics_allready')));
        }
        return $out;
    }

    public static function listing(string $company, array $m, array $owners): string {
        $f=$m['filters']; $ctx=$f+['page'=>$m['page']];
        $actions=$m['can_manage']?self::link(self::url($company,['view'=>'create','presettype'=>$f['kind']],$ctx),'+ '.self::tr('pru_create'),true):'';
        $actions.=self::link(self::url($company,[],[],'ofs'),self::tr('process_openofs'));
        $out=bms_workspace_ux_service::record_header(s(self::tr('process_title')),s(self::tr('pru_intro')),s($m['company']['name']),'',$actions);
        $cards='';
        foreach (self::types() as $type=>$label) {
            $cards.=\html_writer::link(self::url($company,['kind'=>$type,'page'=>0],$f),
                \html_writer::div((string)$m['summary'][$type],'kbacompany-summary-value').\html_writer::div(s($label),'kbacompany-summary-label'),
                ['class'=>'kbacompany-summary-card'.($f['kind']===$type?' is-active':''),'aria-current'=>$f['kind']===$type?'true':null]);
        }
        $out.=\html_writer::div($cards,'kbacompany-summary-grid pru-summary').self::note(self::tr('pru_summaryhelp'));
        $toolbar=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'layout'=>$f['layout'],'parentfilter'=>$f['parentfilter']]);
        $toolbar.=self::control('q',self::tr('pru_search'),$f['q'],['type'=>'search','maxlength'=>200]);
        $toolbar.=self::control('kind',self::tr('process_type'),$f['kind'],['options'=>[''=>self::tr('pru_anytype')]+self::types()]);
        $toolbar.=self::control('owner',self::tr('process_owner'),$f['owner'],['options'=>[''=>self::tr('pru_anyowner')]+$owners['options']]);
        $toolbar.=self::control('level',self::tr('process_level'),$f['level'],['options'=>[''=>self::tr('pru_anylevel')]+array_combine(process_service::LEVELS,process_service::LEVELS)]);
        $toolbar.=\html_writer::div(\html_writer::tag('button',s(self::tr('pru_apply')),['class'=>'btn btn-primary','type'=>'submit']).
            self::link(self::url($company,['layout'=>$f['layout']]),self::tr('pru_reset')),'bms-pdu-actions');
        $toolbar.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_ownersearch'))).
            self::control('ownerq',self::tr('pru_ownersearch'),$f['ownerq'],['type'=>'search','maxlength'=>200,'help'=>self::tr('pru_limited')]),['class'=>'pru-full']);
        $out.=\html_writer::tag('form',$toolbar,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-pdu-filters']);
        $views=[]; foreach (['architecture','analytics','registry'] as $layout) { $views[$layout]=['url'=>self::url($company,['layout'=>$layout],$ctx),'label'=>s(self::tr('pru_'.$layout))]; }
        $out.=bms_workspace_ux_service::action_bar(self::note(self::tr('pru_found',(string)$m['total'])),bms_workspace_ux_service::view_switcher($views,$f['layout']));
        if (!$m['total']) {
            if (!array_sum($m['summary'])) {
                $out.=self::panel(self::tr('pru_empty'),self::note(self::tr('pru_emptyhelp')).($m['can_manage']?self::link(self::url($company,['view'=>'create']),self::tr('pru_first'),true):''));
            } else { $out.=self::panel(self::tr('pru_notfound'),self::link(self::url($company,['layout'=>$f['layout']]),self::tr('pru_reset'))); }
        } else if ($f['layout']==='registry') {
            $rows=[];
            foreach ($m['rows'] as $p) {
                $rows[]=[self::process_name($company,$p,$ctx).self::note($p['level']).self::parent_name($company,$p,$ctx),
                    s(self::types()[$p['type']]).'<br>'.self::status($p), s($p['owner_name']?:self::tr('pru_notassigned')),
                    s($p['product_name']?:self::tr('pru_notset')),self::menu($company,$p,$ctx,$m['can_manage'])];
            }
            $out.=bms_workspace_ux_service::record_table([self::tr('process_name'),self::tr('process_type'),self::tr('process_owner'),self::tr('process_product'),self::tr('pru_actions')],$rows);
        } else if ($f['layout']==='analytics') {
            $out.=self::architecture_analytics_view($company,$m,$ctx);
        } else {
            $out.=self::architecture_workspace_view($company,$m,$ctx);
        }
        if ($m['total'] && $f['layout']==='registry') { $out.=self::pager($company,$m,$f).self::note(self::tr('pru_pagehelp')); }
        return $out.\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_help'))).self::note(self::tr('pru_helptext')),['class'=>'bms-pdu-help']);
    }
    private static function tree_item(string $company, array $p, array $rows, array $ctx, bool $manager, array $seen=[]): string {
        if (isset($seen[$p['uuid']])) { return ''; } $seen[$p['uuid']]=true;
        $body=\html_writer::div(self::process_name($company,$p,$ctx).self::note($p['level']).self::status($p).self::menu($company,$p,$ctx,$manager),'pru-cardhead');
        $body.=self::parent_name($company,$p,$ctx);
        $body.=bms_workspace_ux_service::record_fields([self::tr('process_owner')=>$p['owner_name']?:self::tr('pru_notassigned'),self::tr('process_product')=>$p['product_name']?:self::tr('pru_notset')]);
        if ($p['children']) { $body.=\html_writer::link(self::url($company,['parentfilter'=>$p['uuid'],'q'=>'','kind'=>'','owner'=>'','level'=>'','page'=>0],$ctx),s(self::tr('pru_childcount',(string)$p['children']))); }
        $children=''; foreach ($rows as $child) { if ($child['parent_uuid']===$p['uuid']) { $children.=self::tree_item($company,$child,$rows,$ctx,$manager,$seen); } }
        return \html_writer::tag('li',\html_writer::div($body,'bms-pdu-panel pru-card').($children?\html_writer::tag('ul',$children,['class'=>'pru-tree']):''));
    }
    public static function header(string $company, array $m, array $ctx, string $tab='passport'): string {
        $p=$m['process'];$params=['process'=>$p['uuid']];
        $actions=self::link(self::url($company,$params,[],'process_document'),self::tr('processdoc_open'));
        $items=[];
        if ($m['can_manage'] && $p['status']!=='archived') {
            $actions.=self::link(self::url($company,$params+['view'=>'edit'],$ctx),self::tr('pru_edit'),true);
            if ($p['level']!=='L2') { $items[]=[self::url($company,['view'=>'create','parent'=>$p['uuid']],$ctx),self::tr('pru_subprocess')]; }
            $items[]=[self::url($company,$params+['view'=>'links'],$ctx),self::tr('pru_editlinks')];
            if ($p['status']==='draft') { $items[]=[self::url($company,$params+['view'=>'publish'],$ctx),self::tr('process_publish')]; }
            $items[]=[self::url($company,$params,[],'stp'),self::tr('procmodel_openstp')];
            $items[]=[self::url($company,$params+['view'=>'delete'],$ctx),self::tr('pru_delete'),'bms-pdu-destructive'];
            $actions.=bms_workspace_ux_service::record_menu(self::tr('pru_actions'),$items);
        }
        $out=\html_writer::link(self::url($company,[],$ctx),s(self::tr('pru_back')),['class'=>'bms-pdu-back']);
        $out.=bms_workspace_ux_service::record_header(s($p['name']),s(self::types()[$p['type']].' · '.$p['level']),s($m['company']['name'].' · '.$p['code']),
            self::status($p).' · '.s(self::tr('pru_rev',(string)$p['revision'])).' · '.s($p['owner_name']?:self::tr('pru_notassigned')),$actions);
        $tabs='';foreach (['passport'=>self::tr('pru_passport'),'idef0'=>'IDEF0','sipoc'=>'SIPOC','bpmn'=>'BPMN'] as $k=>$label) {
            $tabs.=\html_writer::link(self::url($company,$params+['tab'=>$k],$ctx),s($label),['class'=>'bms-pdu-tab'.($tab===$k?' is-active':''),'aria-current'=>$tab===$k?'page':null]);
        }
        return $out.\html_writer::tag('nav',$tabs,['class'=>'bms-pdu-tabs','aria-label'=>self::tr('pru_tabs')]);
    }
    public static function passport(string $company, array $m, array $ctx, array $children, array $links, array $history): string {
        $p=$m['process'];$out=self::header($company,$m,$ctx);
        $out.=\html_writer::div(s(self::tr($p['status']==='archived'?'pru_archived':($p['status']==='draft'?'pru_nextdraft':'pru_nextpublished'))),'bms-pdu-notice');
        $body=bms_workspace_ux_service::record_fields([self::tr('process_purpose')=>$p['purpose'],self::tr('process_product')=>$p['product_name']?:self::tr('pru_notset'),self::tr('process_owner')=>$p['owner_name']?:self::tr('pru_notassigned')]);
        $body.=self::parent_name($company,$p,$ctx);
        if ($p['owner_name']!=='' || $p['product_name']!=='') { $body.=self::link(self::url($company,[],[],'ofs'),self::tr('process_openofs')); }
        $out.=self::panel(self::tr('pru_passport'),$body);
        if ($p['children']) {
            $body=''; foreach ($children['rows'] as $child) { $body.=\html_writer::tag('li',self::process_name($company,$child,$ctx)); }
            $out.=self::panel(self::tr('pru_childcount',(string)$p['children']),\html_writer::tag('ul',$body).self::pager($company,$children,['process'=>$p['uuid']]+$ctx,'childpage'));
        }
        $out.=self::links_table($company,$m,$ctx,$links,false);
        $rows=[];foreach ($history as $h) { $rows[]=[s((string)$h->revision),s(self::tr('process_status_'.$h->status)),s(userdate($h->timecreated)),s($h->name)]; }
        if ($rows) {
            $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_history'))).self::note(self::tr('pru_historyhelp')).
                bms_workspace_ux_service::record_table([self::tr('procmodel_revision'),self::tr('process_status'),self::tr('pdu_modified'),self::tr('process_name')],$rows),['class'=>'bms-pdu-help']);
        }
        return $out;
    }
    public static function form(string $company, bool $creating, array $m, array $v, array $ctx, array $options, array $proof, array $errors, string $lookup, bool $dirty = false): string {
        $p=$m['process']??null; $cancel=$creating?self::url($company,[],$ctx):self::url($company,['process'=>$p['uuid']],$ctx);
        $out=bms_workspace_ux_service::record_header(s(self::tr($creating?'pru_create':'process_edit')),s(self::tr('pru_drafthelp')),s($m['company']['name']));
        $body=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'view'=>$creating?'create':'edit','process'=>$p['uuid']??'','sesskey'=>sesskey()]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::note(self::tr('productcode').': '.($p?$p['code']:self::tr('pru_codeauto'))).self::note(self::tr('pru_codehelp'));
        $main=self::control('name',self::tr('process_name'),(string)$v['name'],['required'=>true,'maxlength'=>255],$errors['name']??'');
        $main.=self::control('processtype',self::tr('process_type'),(string)$v['processtype'],['options'=>self::types(),'required'=>true],$errors['processtype']??'');
        $body.=self::panel(self::tr('pru_main'),\html_writer::div($main,'bms-pdu-grid'));
        $place=self::control('processlevel',self::tr('process_level'),(string)$v['processlevel'],['options'=>array_combine(process_service::LEVELS,process_service::LEVELS),'help'=>self::tr('pru_levelhelp'),'required'=>true],$errors['processlevel']??'');
        $place.=self::control('parentuuid',self::tr('pru_parent'),(string)$v['parentuuid'],['options'=>[''=>self::tr('process_noparent')]+$options['parent']['options']],$errors['parentuuid']??'');
        $body.=self::panel(self::tr('pru_place'),\html_writer::div($place,'bms-pdu-grid'));
        $fields=self::control('ownerorgunituuid',self::tr('process_owner'),(string)$v['ownerorgunituuid'],['options'=>[''=>self::tr('pru_notassigned')]+$options['owner']['options'],'help'=>self::tr('pru_ownerhelp')],$errors['ownerorgunituuid']??'');
        $fields.=self::control('productuuid',self::tr('process_product'),(string)$v['productuuid'],['options'=>[''=>self::tr('pru_notset')]+$options['product']['options']],$errors['productuuid']??'');
        $body.=self::panel(self::tr('pru_result'),\html_writer::div($fields,'bms-pdu-grid').self::control('purpose',self::tr('process_purpose'),(string)$v['purpose'],['type'=>'textarea','maxlength'=>4000],$errors['purpose']??''));
        $pick=self::control('lookup',self::tr('pru_lookup'),$lookup,['type'=>'search','maxlength'=>200,'help'=>self::tr('pru_lookuphelp')]);
        $pick.=\html_writer::tag('button',s(self::tr('pru_refreshoptions')),['type'=>'submit','name'=>'action','value'=>'lookup','formnovalidate'=>'formnovalidate','class'=>'btn btn-outline-secondary']);
        if ($options['owner']['limited']||$options['parent']['limited']||$options['product']['limited']) { $pick.=self::note(self::tr('pru_limited')); }
        $body.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_lookup'))).$pick,['class'=>'bms-pdu-help','open'=>$lookup!==''?'open':null]);
        $body.=\html_writer::div(\html_writer::tag('button',s(self::tr($creating?'pru_create':'pru_save')),['type'=>'submit','name'=>'action','value'=>'save','class'=>'btn btn-primary']).self::link($cancel,self::tr('pru_cancel')),'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['action'=>self::url($company)->out(false),'method'=>'post','class'=>'bms-record-editview bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }
    public static function confirmation(string $company, array $m, array $ctx, string $op, array $proof, array $errors, string $reason, bool $confirmed, array $dependencies=[], array $relation=[]): string {
        $p=$m['process'];$blocked=$op==='delete'&&(bool)$dependencies;
        $title=self::tr($op==='publish'?'process_publish':($op==='unlink'?'pru_unlink':($blocked?'pru_deleteblocked':'pru_delete')));
        $out=bms_workspace_ux_service::record_header(s($title),s($p['code'].' — '.$p['name']),s($m['company']['name']));
        $out.=self::note(self::tr($op==='publish'?'pru_publishhelp':($op==='unlink'?'pru_unlinkhelp':($blocked?'pru_deleteblockedhelp':'pru_deletehelp'))));
        if (!empty($m['relation_label'])) { $out.=self::panel(self::tr('pru_linktarget'),self::note($m['relation_label'])); }
        $cancel=self::url($company,['process'=>$p['uuid']]+($op==='unlink'?['view'=>'links']:[]),$ctx);
        if ($blocked) {
            $rows=[];foreach ($dependencies as $dep) { $rows[]=[s(self::tr('pru_dep_'.$dep['key'])),(string)$dep['count'],self::link(self::url($company,$dep['params'],[],$dep['route']),self::tr('pru_open'))]; }
            return $out.bms_workspace_ux_service::record_table([self::tr('pru_dependencies'),self::tr('pdu_records'),self::tr('pru_actions')],$rows).self::link($cancel,self::tr('pru_cancel'));
        }
        $body=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'process'=>$p['uuid'],'view'=>$op,'action'=>$op,'sesskey'=>sesskey()]+$proof+$ctx+$relation);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::control('reason',self::tr('pru_reason'),$reason,['type'=>'textarea','required'=>true,'maxlength'=>4000],$errors['reason']??'');
        $body.=\html_writer::div(\html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','id'=>'pru_confirm','value'=>'1','required'=>'required','checked'=>$confirmed?'checked':null]).
            \html_writer::label(s(self::tr('pru_confirm')),'pru_confirm'),'bms-pdu-confirm');
        $body.=\html_writer::div(self::link($cancel,self::tr('pru_cancel')).\html_writer::tag('button',s($title),['type'=>'submit','class'=>'btn '.($op==='publish'?'btn-primary':'btn-danger')]),'bms-pdu-actions');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-pdu-form']);
    }
    public static function links_table(string $company, array $m, array $ctx, array $data, bool $editing): string {
        $p=$m['process'];$rows=[];
        foreach ($data['rows'] as $r) {
            $target=s($r['target_name']?:self::tr('pru_unavailable'));
            if ($r['target_name']!=='') { $target=\html_writer::link(self::url($company,$r['kind']==='metric'?['stat'=>$r['target_uuid']]:[],[],$r['kind']==='metric'?'indicators':'ofs'),$target); }
            $row=[s(self::tr('process_link_'.$r['kind'])),$target];
            if ($editing) { $row[]=self::link(self::url($company,['process'=>$p['uuid'],'view'=>'unlink','relation'=>$r['relation_uuid'],'relationrevision'=>$r['revision']],$ctx),self::tr('pru_unlink')); }
            $rows[]=$row;
        }
        $body=$rows?bms_workspace_ux_service::record_table(array_merge([self::tr('pru_linktype'),self::tr('pru_linktarget')],$editing?[self::tr('pru_actions')]:[]),$rows):self::note(self::tr('pru_emptylinks'));
        $body.=self::pager($company,$data,['process'=>$p['uuid']]+($editing?['view'=>'links']:[])+$ctx,'linkpage');
        return self::panel(self::tr('pru_links'),$body);
    }
    public static function link_form(string $company, array $m, array $ctx, array $proof, array $data, array $v, array $options, array $errors, string $lookup, bool $dirty = false): string {
        $out=self::header($company,$m,$ctx).self::links_table($company,$m,$ctx,$data,true);
        $body=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'view'=>'links','process'=>$m['process']['uuid'],'sesskey'=>sesskey()]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $types=[];foreach (['supplier','customer','participant','metric'] as $kind) { $types[$kind]=self::tr('process_link_'.$kind); }
        $body.=self::control('linktype',self::tr('pru_linktype'),$v['linktype'],['options'=>$types]);
        $opts=[];foreach (['owner','metric'] as $kind) { foreach ($options[$kind]['options'] as $key=>$name) { $opts[$kind.'|'.$key]=self::tr($kind==='metric'?'process_link_metric':'orgunitname').' · '.$name; } }
        $body.=self::control('target',self::tr('pru_linktarget'),$v['target'],['options'=>[''=>self::tr('pru_choose')]+$opts,'required'=>true]);
        $body.=self::control('reason',self::tr('pru_reason'),$v['reason'],['maxlength'=>4000,'required'=>true]);
        $body.=self::control('lookup',self::tr('pru_lookup'),$lookup,['type'=>'search','maxlength'=>200,'help'=>self::tr('pru_limited')]);
        $body.=\html_writer::div(\html_writer::tag('button',s(self::tr('pru_addlink')),['type'=>'submit','name'=>'action','value'=>'link','class'=>'btn btn-primary']).
            \html_writer::tag('button',s(self::tr('pru_refreshoptions')),['type'=>'submit','name'=>'action','value'=>'lookup','class'=>'btn btn-outline-secondary','formnovalidate'=>'formnovalidate']),'bms-pdu-actions');
        return $out.self::panel(self::tr('pru_addlink'),\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company)->out(false),'data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]));
    }
    /** IDEF0 context view over the canonical process model. Managers edit ICOM only in a draft revision. */
    public static function idef0(string $company, array $m, array $ctx, array $data): string {
        $p=$m['process'];
        $model=$data['model'];
        $canmanage=!empty($m['can_manage']) && $p['status']!=='archived';
        $draft=$canmanage && $model && $model['status']==='draft';
        $decomposition=$data['decomposition']??['notation'=>'A?','diagram_notation'=>'A?','parent_notation'=>'','children'=>[],
            'can_add_child'=>false,'can_add_interconnection'=>false,'interconnections'=>[],'balance'=>['total'=>0,'balanced'=>0,'issues'=>0],
            'boundary_balance'=>['applicable'=>false,'score'=>0,'total'=>0,'balanced'=>0,'issue_count'=>0,'items'=>[]],
            'controls_mechanisms'=>['controls'=>[],'mechanisms'=>[]],'can_link_control_mechanism'=>false];
        $balance=$decomposition['balance']??['total'=>0,'balanced'=>0,'issues'=>0];
        $boundary=$decomposition['boundary_balance']??['applicable'=>false,'score'=>0,'total'=>0,'balanced'=>0,'issue_count'=>0,'items'=>[]];
        $cm=$decomposition['controls_mechanisms']??['controls'=>[],'mechanisms'=>[]];
        $consistency=$data['idef0_sipoc_consistency']??['applicable'=>false,'status'=>'consistent','score'=>100,
            'inputs'=>['idef0'=>0,'sipoc'=>0,'match'=>true],'outputs'=>['idef0'=>0,'sipoc'=>0,'match'=>true],
            'primary'=>['uuid'=>'','match'=>true,'present'=>false],'canonical'=>['total'=>0,'bound'=>0],
            'structural_issues'=>0,'formalisation_issues'=>0,'issue_count'=>0,'items'=>[]];

        $out=self::header($company,$m,$ctx,'idef0');

        // Workspace status: show the state first; keep editing actions compact and contextual.
        $statuschips='';
        $statuschips.=\html_writer::span(s((string)$decomposition['notation']),'pru-idef0-workspace-chip is-notation');
        if ($model) {
            $statuschips.=\html_writer::span(s(self::tr('pru_rev',(string)$model['revision']).' · '.self::tr('procmodel_status_'.$model['status'])),
                'pru-idef0-workspace-chip');
        }
        if (!empty($balance['total'])) {
            $statuschips.=\html_writer::span(s(self::tr('pru_idef0_balance_short',(object)[
                'balanced'=>(int)$balance['balanced'],'total'=>(int)$balance['total'],
            ])),'pru-idef0-workspace-chip'.((int)$balance['issues']>0?' is-warning':' is-ok'));
        }
        if (!empty($boundary['applicable'])) {
            $statuschips.=\html_writer::span(s(self::tr('pru_idef0_boundary_short',(object)['score'=>(int)$boundary['score']])),
                'pru-idef0-workspace-chip'.((int)$boundary['issue_count']>0?' is-warning':' is-ok'));
        }
        if (!empty($consistency['applicable'])) {
            $statuschips.=\html_writer::span(s(self::tr('pru_idef0_sipoc_short',(object)['score'=>(int)$consistency['score']])),
                'pru-idef0-workspace-chip'.(($consistency['status']??'consistent')==='consistent'?' is-ok':' is-warning'));
        }
        $workspaceactions='';
        if ($canmanage) {
            if (!$draft) {
                $label=$model?self::tr('procmodel_newrevision'):self::tr('procmodel_createdraft');
                $drafturl=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'draft','tab'=>'idef0'],$ctx,'process_design');
                $workspaceactions.=self::link($drafturl,$label,true);
            } else {
                $publish=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'publish','tab'=>'idef0'],$ctx,'process_design');
                $workspaceactions.=self::link($publish,self::tr('procmodel_publish'),true);
            }
        }
        $out.=\html_writer::div(
            \html_writer::div($statuschips,'pru-idef0-workspace-status').
            ($workspaceactions!==''?\html_writer::div($workspaceactions,'pru-idef0-workspace-actions'):''),
            'pru-idef0-workspacebar');

        if (!$model) {
            $out.=\html_writer::div(s(self::tr('pru_idef0_nomodel')),'bms-pdu-notice pru-idef0-editstate');
        } else if ($canmanage) {
            $out.=\html_writer::div(s(self::tr($draft?'pru_idef0_draft_active':'pru_idef0_draft_needed')),
                'bms-pdu-notice pru-idef0-editstate'.($draft?' is-draft':''));
        }

        $anchors=\html_writer::tag('a',s(self::tr('pru_idef0_diagram')),['href'=>'#idef0-context','class'=>'pru-idef0-anchor']).
            \html_writer::tag('a',s(self::tr('pru_idef0_decomposition')),['href'=>'#idef0-decomposition','class'=>'pru-idef0-anchor']);
        if (!empty($boundary['applicable'])) {
            $anchors.=\html_writer::tag('a',s(self::tr('pru_idef0_boundary_title')),['href'=>'#idef0-boundary-balance','class'=>'pru-idef0-anchor']);
        }
        if (!empty($consistency['applicable'])) {
            $anchors.=\html_writer::tag('a',s(self::tr('pru_idef0_sipoc_title')),['href'=>'#idef0-sipoc-consistency','class'=>'pru-idef0-anchor']);
        }
        if ($p['level']!=='L2') {
            $anchors.=\html_writer::tag('a',s(self::tr('pru_idef0_interconnections')),['href'=>'#idef0-interconnections','class'=>'pru-idef0-anchor']);
        }
        $out.=\html_writer::tag('nav',$anchors,['class'=>'pru-idef0-anchorbar','aria-label'=>self::tr('pru_tabs')]);

        $itemactions=static function(array $item, string $type) use ($company,$p,$ctx,$draft): string {
            if (!$draft || !empty($item['primary']) || empty($item['uuid'])) { return ''; }
            $edit=process_ui_service::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0','item'=>$item['uuid'],'elementtype'=>$type],$ctx,'process_design');
            $remove=process_ui_service::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeicom','tab'=>'idef0','item'=>$item['uuid']],$ctx,'process_design');
            return \html_writer::div(process_ui_service::link($edit,process_ui_service::tr('pru_edit')).
                process_ui_service::link($remove,process_ui_service::tr('pru_removeelement')),'pru-idef0-card-actions');
        };
        $cards=static function(array $items, string $emptykey, string $type, bool $showempty=true) use ($itemactions): string {
            $html='';
            foreach ($items as $item) {
                $name=trim((string)($item['name']??$item['target_name']??''));
                if ($name==='') { continue; }
                $meta='';
                if (!empty($item['description'])) { $meta=\html_writer::div(s((string)$item['description']),'pru-idef0-meta'); }
                if (in_array($type,['input','output'],true) && !empty($item['product_name'])) {
                    $meta.=\html_writer::div(s(process_ui_service::tr('pru_idef0_canonical_product').': '.(string)$item['product_name']),'pru-idef0-meta');
                }
                if ($type==='output' && !empty($item['primary'])) {
                    $meta.=\html_writer::div(s(process_ui_service::tr('pru_idef0_primary_output')),'pru-idef0-meta');
                }
                $html.=\html_writer::div(\html_writer::tag('strong',s($name)).$meta.$itemactions($item,$type),'pru-idef0-card');
            }
            if ($html!=='' || !$showempty) { return $html; }
            return \html_writer::div(s(process_ui_service::tr($emptykey)),'pru-idef0-empty');
        };
        $canonicalcards=static function(array $items) use ($company,$p,$ctx,$canmanage): string {
            $html='';
            $labels=[
                'policy'=>'pru_idef0_ref_policy','document'=>'pru_idef0_ref_document','metric'=>'pru_idef0_ref_metric',
                'orgunit'=>'pru_idef0_ref_orgunit','position'=>'pru_idef0_ref_position',
            ];
            foreach ($items as $item) {
                $type=(string)($item['type']??'');
                $name=trim((string)($item['name']??''));
                if ($name==='') { continue; }
                $badge=process_ui_service::tr($labels[$type]??'pru_idef0_ref_object');
                $title=trim((string)($item['code']??''));
                if ($title!=='') { $title.=' — '; }
                $title.=$name;
                $meta=\html_writer::span(s($badge),'pru-idef0-canonical-badge');
                if (!empty($item['status']) && !in_array((string)$item['status'],['active','published'],true)) {
                    $meta.=\html_writer::span(s((string)$item['status']),'pru-idef0-canonical-status');
                }
                $actions='';
                if ($canmanage && !empty($item['relation_uuid'])) {
                    $remove=process_ui_service::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeicomlink','tab'=>'idef0',
                        'item'=>$item['relation_uuid'],'relationrevision'=>(int)$item['revision']],$ctx,'process_design');
                    $actions=\html_writer::div(process_ui_service::link($remove,process_ui_service::tr('pru_idef0_unlink_object')),'pru-idef0-card-actions');
                }
                $html.=\html_writer::div(\html_writer::div($meta,'pru-idef0-canonical-meta').
                    \html_writer::tag('strong',s($title)).$actions,'pru-idef0-card is-canonical');
            }
            return $html;
        };
        $add=static function(string $type, string $label) use ($company,$p,$ctx,$draft): string {
            if (!$draft) { return ''; }
            $url=process_ui_service::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0','elementtype'=>$type],$ctx,'process_design');
            return process_ui_service::link($url,'+ '.$label);
        };
        $addcanonical=static function(string $kind) use ($company,$p,$ctx,$canmanage,$decomposition): string {
            if (!$canmanage || empty($decomposition['can_link_control_mechanism'])) { return ''; }
            $url=process_ui_service::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icomlink','tab'=>'idef0','linkkind'=>$kind],$ctx,'process_design');
            return process_ui_service::link($url,'+ '.process_ui_service::tr('pru_idef0_link_object'));
        };

        $inputsitems=$data['sipoc']['inputs'];
        $controlsitems=$data['bpmn']['controls'];
        $mechanismsitems=$data['bpmn']['resources'];
        $outputs=[];
        foreach ($data['sipoc']['outputs'] as $item) {
            if (trim((string)($item['name']??''))!=='') { $outputs[]=$item; }
        }
        $inputs=$cards($inputsitems,'pru_idef0_empty','input');
        $canonicalcontrols=$canonicalcards($cm['controls']??[]);
        $canonicalmechanisms=$canonicalcards($cm['mechanisms']??[]);
        $controls=$cards($controlsitems,'pru_idef0_empty','control',false).$canonicalcontrols;
        if ($controls==='') { $controls=\html_writer::div(s(self::tr('pru_idef0_empty')),'pru-idef0-empty'); }
        $mechanisms=$cards($mechanismsitems,'pru_idef0_empty','resource',false).$canonicalmechanisms;
        if ($mechanisms==='') { $mechanisms=\html_writer::div(s(self::tr('pru_idef0_empty')),'pru-idef0-empty'); }
        $outputhtml=$cards($outputs,'pru_idef0_empty','output');

        $functionmeta=bms_workspace_ux_service::record_fields([
            self::tr('productcode')=>$p['code'],
            self::tr('process_level')=>$p['level'],
            self::tr('process_type')=>self::types()[$p['type']],
            self::tr('process_owner')=>$p['owner_name']?:self::tr('pru_notassigned'),
        ]);
        if ($p['purpose']!=='') { $functionmeta.=\html_writer::div(s($p['purpose']),'pru-idef0-purpose'); }
        if ($p['parent_name']!=='') { $functionmeta.=\html_writer::div(s(self::tr('pru_parent').': '.$p['parent_code'].' — '.$p['parent_name']),'pru-idef0-parent'); }
        $function=\html_writer::tag('section',
            \html_writer::div(\html_writer::span(s((string)$decomposition['notation']),'pru-idef0-node-id').
                \html_writer::span(s(self::tr('pru_idef0_function')),'pru-idef0-kicker-label'),'pru-idef0-kicker').
            \html_writer::tag('h2',s($p['name'])).$functionmeta,
            ['class'=>'pru-idef0-function','aria-label'=>self::tr('pru_idef0_function')]);

        $area=static function(string $class, string $title, string $body, string $arrow, string $action='', int $count=0): string {
            $titlehtml=\html_writer::div(s($title).\html_writer::span((string)$count,'pru-idef0-count'),'pru-idef0-area-title');
            $heading=\html_writer::div($titlehtml.$action,'pru-idef0-area-head');
            return \html_writer::tag('section',$heading.$body.
                \html_writer::span($arrow,'pru-idef0-arrow',['aria-hidden'=>'true']),['class'=>'pru-idef0-area '.$class]);
        };
        $controlactions=$add('control',self::tr('pru_idef0_add_control')).$addcanonical('control');
        $mechanismactions=$add('resource',self::tr('pru_idef0_add_mechanism')).$addcanonical('mechanism');
        $controlcount=count($controlsitems)+count($cm['controls']??[]);
        $mechanismcount=count($mechanismsitems)+count($cm['mechanisms']??[]);
        $diagram=$area('is-control',self::tr('pru_idef0_control'),$controls,'↓',$controlactions,$controlcount).
            \html_writer::div(
                $area('is-input',self::tr('pru_idef0_input'),$inputs,'→',$add('input',self::tr('pru_idef0_add_input')),count($inputsitems)).
                $function.
                $area('is-output',self::tr('pru_idef0_output'),$outputhtml,'→',$add('output',self::tr('pru_idef0_add_output')),count($outputs)),
                'pru-idef0-middle').
            $area('is-mechanism',self::tr('pru_idef0_mechanism'),$mechanisms,'↑',$mechanismactions,$mechanismcount);
        $contextsummary=self::tr('pru_idef0_context_summary',(object)[
            'inputs'=>count($inputsitems),'controls'=>$controlcount,'outputs'=>count($outputs),'mechanisms'=>$mechanismcount,
        ]);
        $contextheading=\html_writer::div(
            \html_writer::div(\html_writer::tag('h2',s(self::tr('pru_idef0_diagram'))).
                \html_writer::span(s((string)$decomposition['notation']),'pru-idef0-section-id'),'pru-idef0-section-heading').
            \html_writer::div(s($contextsummary),'pru-idef0-section-summary'),
            'pru-idef0-section-top');
        $out.=\html_writer::tag('section',$contextheading.
            \html_writer::div(\html_writer::div($diagram,'pru-idef0 is-visual-final',[
                'role'=>'figure','aria-label'=>self::tr('pru_idef0_diagram')
            ]),'pru-idef0-visual-frame'),
            ['class'=>'pru-idef0-section','id'=>'idef0-context']);

        if (!empty($consistency['applicable'])) {
            $state=(string)($consistency['status']??'consistent');
            $statekey=['consistent'=>'pru_idef0_sipoc_state_consistent','needs_formalisation'=>'pru_idef0_sipoc_state_formalisation',
                'inconsistent'=>'pru_idef0_sipoc_state_inconsistent'][$state]??'pru_idef0_sipoc_state_inconsistent';
            $metric=static function(string $label, string $value, bool $ok): string {
                return \html_writer::div(\html_writer::span(s($label),'pru-idef0-consistency-metric-label').
                    \html_writer::span(s($value),'pru-idef0-consistency-metric-value'.($ok?' is-ok':' is-warning')),
                    'pru-idef0-consistency-metric');
            };
            $metrics=$metric(self::tr('pru_idef0_sipoc_inputs'),(int)$consistency['inputs']['idef0'].' / '.(int)$consistency['inputs']['sipoc'],
                    !empty($consistency['inputs']['match'])).
                $metric(self::tr('pru_idef0_sipoc_outputs'),(int)$consistency['outputs']['idef0'].' / '.(int)$consistency['outputs']['sipoc'],
                    !empty($consistency['outputs']['match'])).
                $metric(self::tr('pru_idef0_sipoc_primary'),!empty($consistency['primary']['present'])?self::tr('pru_yes'):self::tr('pru_no'),
                    !empty($consistency['primary']['match']) && !empty($consistency['primary']['present'])).
                $metric(self::tr('pru_idef0_sipoc_canonical'),(int)$consistency['canonical']['bound'].' / '.(int)$consistency['canonical']['total'],
                    (int)$consistency['formalisation_issues']===0);
            $issuehtml='';
            foreach (($consistency['items']??[]) as $issue) {
                $type=(string)($issue['type']??'');
                $params=(object)['element'=>(string)($issue['element_name']??'')];
                $key=[
                    'input_projection_mismatch'=>'pru_idef0_sipoc_issue_input_projection',
                    'output_projection_mismatch'=>'pru_idef0_sipoc_issue_output_projection',
                    'primary_projection_mismatch'=>'pru_idef0_sipoc_issue_primary_projection',
                    'primary_product_missing'=>'pru_idef0_sipoc_issue_primary_missing',
                    'input_unbound'=>'pru_idef0_sipoc_issue_input_unbound',
                    'output_unbound'=>'pru_idef0_sipoc_issue_output_unbound',
                ][$type]??'pru_idef0_sipoc_issue_generic';
                $actions='';
                if ($canmanage && $draft && in_array($type,['input_unbound','output_unbound'],true) && !empty($issue['element_uuid'])) {
                    $edit=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0',
                        'item'=>$issue['element_uuid'],'elementtype'=>$type==='input_unbound'?'input':'output'],$ctx,'process_design');
                    $actions=self::link($edit,self::tr('pru_edit'));
                } else if ($canmanage && $type==='primary_product_missing') {
                    $actions=self::link(self::url($company,['process'=>$p['uuid'],'view'=>'edit'],$ctx),self::tr('pru_edit'),true);
                }
                $issuehtml.=\html_writer::div(\html_writer::div(s(self::tr($key,$params)),'pru-idef0-consistency-issue-text').
                    ($actions!==''?\html_writer::div($actions,'pru-idef0-consistency-issue-actions'):''),'pru-idef0-consistency-issue');
            }
            if ($issuehtml==='') { $issuehtml=\html_writer::div(s(self::tr('pru_idef0_sipoc_all_consistent')),'pru-idef0-consistency-ok'); }
            $sipocurl=self::url($company,['process'=>$p['uuid'],'tab'=>'sipoc'],$ctx);
            $consistencyactions=self::link($sipocurl,self::tr('pru_idef0_sipoc_open_sipoc'));
            if ($canmanage) {
                $consistencyactions.=self::link(self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'sipoc'],$ctx,'process_design'),
                    self::tr('pru_idef0_sipoc_edit_sipoc'));
            }
            $consistencyhead=\html_writer::div(
                \html_writer::div(\html_writer::tag('h2',s(self::tr('pru_idef0_sipoc_title'))).
                    \html_writer::span(s(self::tr($statekey)),'pru-idef0-section-id'.($state==='consistent'?' is-ok':' is-warning')),
                    'pru-idef0-section-heading').
                \html_writer::div($consistencyactions,'bms-pdu-actions'),'pru-idef0-section-top');
            $out.=\html_writer::tag('section',$consistencyhead.
                \html_writer::div(s(self::tr('pru_idef0_sipoc_help')),'pru-idef0-section-description').
                \html_writer::div($metrics,'pru-idef0-consistency-metrics').
                \html_writer::div($issuehtml,'pru-idef0-consistency-issues'),
                ['class'=>'pru-idef0-section pru-idef0-consistency','id'=>'idef0-sipoc-consistency']);
        }

        // Decomposition is a separate workspace section, not a nested technical panel.
        $decompactions='';
        if ($p['parent_uuid']!=='' && $decomposition['parent_notation']!=='') {
            $decompactions.=self::link(self::url($company,['process'=>$p['parent_uuid'],'tab'=>'idef0'],$ctx),
                self::tr('pru_idef0_open_parent').' · '.$decomposition['parent_notation']);
        }
        if (!empty($decomposition['can_add_child'])) {
            $decompactions.=self::link(self::url($company,['view'=>'create','parent'=>$p['uuid']],$ctx),'+ '.self::tr('pru_idef0_add_child'),true);
        }
        $children='';
        foreach ($decomposition['children'] as $child) {
            $childurl=self::url($company,['process'=>$child['uuid'],'tab'=>'idef0'],$ctx);
            $head=\html_writer::div(
                \html_writer::span(s((string)$child['idef0_notation']),'pru-idef0-decomp-id').
                \html_writer::link($childurl,s($child['code'].' — '.$child['name']),['class'=>'pru-idef0-decomp-link']),
                'pru-idef0-decomp-card-head');
            $meta=[];
            $meta[]=self::tr('process_level').' '.$child['level'];
            if ($child['product_name']!=='') { $meta[]=self::tr('process_product').': '.$child['product_name']; }
            if ($child['owner_name']!=='') { $meta[]=self::tr('process_owner').': '.$child['owner_name']; }
            $children.=\html_writer::tag('article',$head.\html_writer::div(s(implode(' · ',$meta)),'pru-idef0-decomp-meta').
                ($child['purpose']!==''?\html_writer::div(s($child['purpose']),'pru-idef0-decomp-purpose'):'').
                \html_writer::div(self::status($child),'pru-idef0-decomp-status'),['class'=>'pru-idef0-decomp-card']);
        }
        $decompheading=\html_writer::div(
            \html_writer::div(\html_writer::span(s((string)$decomposition['diagram_notation']),'pru-idef0-decomp-diagram-id').
                \html_writer::tag('h2',s(self::tr('pru_idef0_decomposition'))),'pru-idef0-decomp-heading').
            ($decompactions!==''?\html_writer::div($decompactions,'bms-pdu-actions pru-idef0-decomp-actions'):''),
            'pru-idef0-section-top');
        $decompbody=\html_writer::div(s(self::tr('pru_idef0_decomposition_help')),'pru-idef0-section-description');
        $decompbody.=$children!==''?\html_writer::div($children,'pru-idef0-decomp-grid',['role'=>'figure','aria-label'=>self::tr('pru_idef0_decomposition')]):
            \html_writer::div(s(self::tr($p['level']==='L2'?'pru_idef0_leaf':'pru_idef0_decomposition_empty')),'pru-idef0-empty');

        if (!empty($boundary['applicable'])) {
            $metric=static function(string $label, array $row): string {
                $ok=(int)($row['issues']??0)===0;
                $value=(int)($row['total']??0)>0 ? (int)($row['balanced']??0).' / '.(int)$row['total'] : '—';
                return \html_writer::div(\html_writer::span(s($label),'pru-idef0-boundary-metric-label').
                    \html_writer::span(s($value),'pru-idef0-boundary-metric-value'.($ok?' is-ok':' is-warning')),
                    'pru-idef0-boundary-metric');
            };
            $metrics=$metric(self::tr('pru_idef0_boundary_inputs'),$boundary['inputs']??[]).
                $metric(self::tr('pru_idef0_boundary_internal'),$boundary['internal']??[]).
                $metric(self::tr('pru_idef0_boundary_outputs'),$boundary['outputs']??[]).
                $metric(self::tr('pru_idef0_boundary_primary'),$boundary['primary']??[]);
            $issuehtml='';
            foreach (($boundary['items']??[]) as $issue) {
                $type=(string)($issue['type']??'');
                $params=(object)[
                    'process'=>(string)($issue['process_notation']??''),
                    'name'=>(string)($issue['process_name']??''),
                    'element'=>(string)($issue['element_name']??''),
                    'product'=>(string)($issue['product_name']??''),
                ];
                $key=[
                    'parent_input_unbound'=>'pru_idef0_boundary_issue_parent_input_unbound',
                    'parent_input_unused'=>'pru_idef0_boundary_issue_parent_input_unused',
                    'internal_unbalanced'=>'pru_idef0_boundary_issue_internal',
                    'parent_output_unbound'=>'pru_idef0_boundary_issue_parent_output_unbound',
                    'terminal_product_missing'=>'pru_idef0_boundary_issue_terminal_product_missing',
                    'terminal_output_missing'=>'pru_idef0_boundary_issue_terminal_output_missing',
                    'parent_product_missing'=>'pru_idef0_boundary_issue_parent_product_missing',
                    'primary_output_unproduced'=>'pru_idef0_boundary_issue_primary_unproduced',
                ][$type]??'pru_idef0_boundary_issue_generic';
                $actions='';
                if ($canmanage) {
                    if (in_array($type,['parent_input_unbound','parent_output_unbound'],true) && $draft && !empty($issue['element_uuid'])) {
                        $editurl=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0',
                            'item'=>$issue['element_uuid'],'elementtype'=>$type==='parent_input_unbound'?'input':'output'],$ctx,'process_design');
                        $actions.=self::link($editurl,self::tr('pru_edit'));
                    } else if ($type==='terminal_product_missing' && !empty($issue['process_uuid'])) {
                        $actions.=self::link(self::url($company,['process'=>$issue['process_uuid']],$ctx),self::tr('pru_idef0_boundary_open_child'));
                    } else if ($type==='terminal_output_missing' && $draft) {
                        $addoutput=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0','elementtype'=>'output'],$ctx,'process_design');
                        $actions.=self::link($addoutput,self::tr('pru_idef0_boundary_add_output'),true);
                    } else if ($type==='parent_product_missing') {
                        $actions.=self::link(self::url($company,['process'=>$p['uuid'],'view'=>'edit'],$ctx),self::tr('pru_edit'),true);
                    } else if ($type==='internal_unbalanced') {
                        $actions.=\html_writer::tag('a',s(self::tr('pru_idef0_boundary_open_links')),
                            ['href'=>'#idef0-interconnections','class'=>'bms-pdu-link']);
                    }
                }
                $issuehtml.=\html_writer::div(
                    \html_writer::div(s(self::tr($key,$params)),'pru-idef0-boundary-issue-text').
                    ($actions!==''?\html_writer::div($actions,'pru-idef0-boundary-issue-actions'):''),
                    'pru-idef0-boundary-issue');
            }
            if ($issuehtml==='') {
                $issuehtml=\html_writer::div(s(self::tr('pru_idef0_boundary_all_balanced')),'pru-idef0-boundary-ok');
            }
            $boundaryhead=\html_writer::div(
                \html_writer::div(\html_writer::tag('h3',s(self::tr('pru_idef0_boundary_title'))).
                    \html_writer::span(s(self::tr('pru_idef0_boundary_short',(object)['score'=>(int)$boundary['score']])),
                        'pru-idef0-section-id'.((int)$boundary['issue_count']>0?' is-warning':' is-ok')),'pru-idef0-section-heading'),
                'pru-idef0-boundary-head');
            $decompbody.=\html_writer::tag('section',$boundaryhead.
                \html_writer::div(s(self::tr('pru_idef0_boundary_help')),'pru-idef0-section-description').
                \html_writer::div($metrics,'pru-idef0-boundary-metrics').
                \html_writer::div($issuehtml,'pru-idef0-boundary-issues'),
                ['class'=>'pru-idef0-boundary','id'=>'idef0-boundary-balance']);
        }
        $out.=\html_writer::tag('section',$decompheading.$decompbody,['class'=>'pru-idef0-section','id'=>'idef0-decomposition']);

        if ($p['level']!=='L2') {
            $interactions='';
            if (!empty($decomposition['can_add_interconnection'])) {
                $addlink=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'interconnect','tab'=>'idef0'],$ctx,'process_design');
                $interactions.=self::link($addlink,'+ '.self::tr('pru_idef0_add_interconnection'),true);
            }
            $interheading=\html_writer::div(
                \html_writer::tag('h2',s(self::tr('pru_idef0_interconnections'))).
                (!empty($balance['total'])?\html_writer::span(s(self::tr('pru_idef0_balance_short',(object)[
                    'balanced'=>(int)$balance['balanced'],'total'=>(int)$balance['total'],
                ])),'pru-idef0-section-id'.((int)$balance['issues']>0?' is-warning':' is-ok')):''),
                'pru-idef0-section-heading');
            $intertop=\html_writer::div($interheading.
                ($interactions!==''?\html_writer::div($interactions,'bms-pdu-actions pru-idef0-inter-actions'):''),'pru-idef0-section-top');
            $interbody=\html_writer::div(s(self::tr('pru_idef0_interconnections_help')),'pru-idef0-section-description');
            $intercards='';
            foreach (($decomposition['interconnections']??[]) as $edge) {
                $from=s(trim((string)$edge['from_notation']).' · '.$edge['from_code'].' — '.$edge['from_name']);
                $to=s(trim((string)$edge['to_notation']).' · '.$edge['to_code'].' — '.$edge['to_name']);
                $label=trim((string)$edge['product_name']);
                $product=\html_writer::div(s($label!==''?self::tr('pru_idef0_interconnection_product').': '.$label:self::tr('pru_idef0_interconnection_unlabelled')),
                    'pru-idef0-inter-meta');
                $balancestatus=(string)($edge['balance_status']??'target_input_missing');
                $balancekey=['balanced'=>'pru_idef0_balance_balanced','source_product_missing'=>'pru_idef0_balance_source_missing',
                    'target_input_missing'=>'pru_idef0_balance_target_missing'][$balancestatus]??'pru_idef0_balance_target_missing';
                $balanceclass=$balancestatus==='balanced'?' is-balanced':' is-warning';
                $balancedetail=self::tr($balancekey);
                if ($balancestatus==='balanced' && !empty($edge['balance_input_name'])) {
                    $balancedetail.=' · '.self::tr('pru_idef0_balance_input').': '.(string)$edge['balance_input_name'];
                }
                $balancelabel=self::tr($balancestatus==='balanced'?'pru_idef0_balance_state_ok':'pru_idef0_balance_state_issue');
                $balancehtml=\html_writer::div(
                    \html_writer::span(s($balancelabel),'pru-idef0-balance-label').
                    \html_writer::span(s($balancedetail),'pru-idef0-balance-detail'),
                    'pru-idef0-balance'.$balanceclass,['role'=>'status']);
                $actions='';
                if ($canmanage) {
                    if ($balancestatus==='target_input_missing' && !empty($edge['product_uuid'])) {
                        if (!empty($edge['can_balance'])) {
                            $balanceurl=self::url($company,['process'=>$edge['to_uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0',
                                'elementtype'=>'input','balanceproduct'=>$edge['product_uuid']],$ctx,'process_design');
                            $actions.=self::link($balanceurl,self::tr('pru_idef0_balance_add_input'),true);
                        } else {
                            $targeturl=self::url($company,['process'=>$edge['to_uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
                            $actions.=self::link($targeturl,self::tr('pru_idef0_balance_open_target'));
                        }
                    }
                    $removeurl=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeinterconnect','tab'=>'idef0',
                        'item'=>$edge['relation_uuid'],'relationrevision'=>(int)$edge['revision']],$ctx,'process_design');
                    $actions.=self::link($removeurl,self::tr('pru_idef0_remove_interconnection'));
                }
                $fromnode=\html_writer::div(
                    \html_writer::span(s((string)$edge['from_notation']),'pru-idef0-inter-notation').
                    \html_writer::span(s($edge['from_code'].' — '.$edge['from_name']),'pru-idef0-inter-node-title'),
                    'pru-idef0-inter-node');
                $tonode=\html_writer::div(
                    \html_writer::span(s((string)$edge['to_notation']),'pru-idef0-inter-notation').
                    \html_writer::span(s($edge['to_code'].' — '.$edge['to_name']),'pru-idef0-inter-node-title'),
                    'pru-idef0-inter-node');
                $route=\html_writer::div($fromnode.
                    \html_writer::span('→','pru-idef0-inter-arrow',['aria-hidden'=>'true']).$tonode,'pru-idef0-inter-route');
                $intercards.=\html_writer::tag('article',$route.$product.$balancehtml.
                    ($actions!==''?\html_writer::div($actions,'pru-idef0-card-actions pru-idef0-inter-card-actions'):''),
                    ['class'=>'pru-idef0-inter-card']);
            }
            if ($intercards==='') { $intercards=\html_writer::div(s(self::tr('pru_idef0_interconnections_empty')),'pru-idef0-empty'); }
            $interbody.=\html_writer::div($intercards,'pru-idef0-interconnections');
            $out.=\html_writer::tag('section',$intertop.$interbody,['class'=>'pru-idef0-section','id'=>'idef0-interconnections']);
        }

        // Architecture explanation stays available without competing with the working surface.
        $help=self::note(self::tr('pru_idef0_intro')).self::note(self::tr('pru_idef0_foundation')).
            self::note(self::tr('pru_idef0_source_help')).self::note(self::tr('pru_idef0_controls_mechanisms_help')).
            self::note(self::tr('pru_idef0_notation_help'));
        $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_help'))).$help,['class'=>'bms-pdu-help pru-idef0-help']);
        return $out;
    }

    /** Add one sibling Process -> supplies -> Process IDEF0 interconnection through Semantic Core. */
    public static function idef0_interconnection_form(string $company, array $record, array $ctx, array $data,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];$children=$data['decomposition']['children']??[];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
        $out=bms_workspace_ux_service::record_header(s(self::tr('pru_idef0_add_interconnection')),
            s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_idef0_interconnection_form_help'));
        $options=[''=>self::tr('pru_choose')];
        foreach ($children as $child) {
            $options[$child['uuid']]=(string)$child['idef0_notation'].' · '.$child['code'].' — '.$child['name'];
        }
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'interconnect','tab'=>'idef0',
            'action'=>'interconnect','sesskey'=>sesskey(),
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::control('fromuuid',self::tr('pru_idef0_from_function'),(string)$values['fromuuid'],['options'=>$options,'required'=>true],$errors['fromuuid']??'');
        $body.=self::control('touuid',self::tr('pru_idef0_to_function'),(string)$values['touuid'],['options'=>$options,'required'=>true],$errors['touuid']??'');
        $body.=self::control('reason',self::tr('pru_reason'),(string)$values['reason'],['type'=>'textarea','maxlength'=>4000,'required'=>true],$errors['reason']??'');
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_save')),['type'=>'submit','class'=>'btn btn-primary']),
            'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    /** Confirmation form for archiving an IDEF0 interconnection relation. */
    public static function idef0_interconnection_remove_form(string $company, array $record, array $ctx, array $edge,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
        $from=(string)$edge['from_notation'].' · '.$edge['from_code'].' — '.$edge['from_name'];
        $to=(string)$edge['to_notation'].' · '.$edge['to_code'].' — '.$edge['to_name'];
        $out=bms_workspace_ux_service::record_header(s(self::tr('pru_idef0_remove_interconnection')),
            s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_idef0_remove_interconnection_help'));
        $out.=self::panel(self::tr('pru_idef0_interconnections'),
            \html_writer::div(s($from).' → '.s($to),'pru-idef0-inter-remove-route'));
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeinterconnect','tab'=>'idef0',
            'action'=>'removeinterconnect','item'=>$edge['relation_uuid'],'relationrevision'=>(int)$edge['revision'],'sesskey'=>sesskey(),
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::control('reason',self::tr('pru_reason'),(string)$values['reason'],['type'=>'textarea','maxlength'=>4000,'required'=>true],$errors['reason']??'');
        $body.=\html_writer::div(\html_writer::empty_tag('input',['type'=>'checkbox','id'=>'pru_confirm_inter','name'=>'confirmed','value'=>'1','required'=>'required','checked'=>!empty($values['confirmed'])?'checked':null]).\html_writer::label(s(self::tr('pru_confirm')),'pru_confirm_inter'),'bms-pdu-confirm');
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_idef0_remove_interconnection')),['type'=>'submit','class'=>'btn btn-danger']),
            'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    /** Link an existing canonical BMS object as an IDEF0 Control or Mechanism through Semantic Core. */
    public static function idef0_control_mechanism_form(string $company, array $record, array $ctx, array $values,
            array $options, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];$kind=(string)$values['linkkind'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
        $title=self::tr($kind==='control'?'pru_idef0_link_control':'pru_idef0_link_mechanism');
        $out=bms_workspace_ux_service::record_header(s($title),s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_idef0_link_object_help'));
        $select=[''=>self::tr('pru_choose')];
        $typelabels=['policy'=>'pru_idef0_ref_policy','document'=>'pru_idef0_ref_document','metric'=>'pru_idef0_ref_metric',
            'orgunit'=>'pru_idef0_ref_orgunit','position'=>'pru_idef0_ref_position'];
        foreach (($options['options']??[]) as $key=>$row) {
            $label=self::tr($typelabels[$row['type']]??'pru_idef0_ref_object').' · ';
            if ((string)$row['code']!=='') { $label.=(string)$row['code'].' — '; }
            $label.=(string)$row['name'];
            $select[$key]=$label;
        }
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'icomlink','tab'=>'idef0','action'=>'icomlink',
            'linkkind'=>$kind,'sesskey'=>sesskey(),
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::control('target',self::tr('pru_idef0_canonical_object'),(string)$values['target'],
            ['options'=>$select,'required'=>true],$errors['target']??'');
        if (count($select)===1) { $body.=self::note(self::tr('pru_idef0_link_object_empty')); }
        $body.=self::control('reason',self::tr('pru_reason'),(string)$values['reason'],
            ['type'=>'textarea','maxlength'=>4000,'required'=>true],$errors['reason']??'');
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_save')),['type'=>'submit','class'=>'btn btn-primary']),
            'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    /** Confirm archive of one canonical IDEF0 Control/Mechanism Semantic Core relation. */
    public static function idef0_control_mechanism_remove_form(string $company, array $record, array $ctx, array $relation,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
        $label=trim((string)($relation['code']??''));
        if ($label!=='') { $label.=' — '; }
        $label.=(string)($relation['name']??self::tr('pru_unavailable'));
        $out=bms_workspace_ux_service::record_header(s(self::tr('pru_idef0_unlink_object')),
            s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_idef0_unlink_object_help'));
        $out.=self::panel(self::tr(($relation['kind']??'control')==='mechanism'?'pru_idef0_mechanism':'pru_idef0_control'),s($label));
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeicomlink','tab'=>'idef0',
            'action'=>'removeicomlink','item'=>(string)$relation['relation_uuid'],'relationrevision'=>(int)$relation['revision'],
            'sesskey'=>sesskey(),
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::control('reason',self::tr('pru_reason'),(string)$values['reason'],
            ['type'=>'textarea','maxlength'=>4000,'required'=>true],$errors['reason']??'');
        $body.=\html_writer::div(\html_writer::empty_tag('input',['type'=>'checkbox','id'=>'pru_confirm_icomlink',
            'name'=>'confirmed','value'=>'1','required'=>'required','checked'=>!empty($values['confirmed'])?'checked':null]).
            \html_writer::label(s(self::tr('pru_confirm')),'pru_confirm_icomlink'),'bms-pdu-confirm');
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_idef0_unlink_object')),['type'=>'submit','class'=>'btn btn-danger']),
            'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    /** Focused IDEF0 ICOM form. Writes are delegated to the canonical process-model service. */
    public static function icom_form(string $company, array $record, array $ctx, array $data, string $item,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];
        $type=(string)$values['elementtype'];
        $labels=['input'=>'pru_idef0_input','control'=>'pru_idef0_control','output'=>'pru_idef0_output','resource'=>'pru_idef0_mechanism'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'idef0'],$ctx,'process_design');
        $title=self::tr($item!==''?'pru_idef0_edit_icom':'pru_idef0_add_icom');
        $out=bms_workspace_ux_service::record_header(s($title),s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_idef0_icom_help'));
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'icom','tab'=>'idef0','item'=>$item,
            'action'=>'icom','sesskey'=>sesskey(),'elementtype'=>$type,'executor'=>'','laneuuid'=>(string)$values['laneuuid'],
            'activityuuid'=>(string)$values['activityuuid'],
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::note(self::tr($labels[$type]));
        $body.=self::control('name',self::tr('procmodel_name'),(string)$values['name'],['required'=>true,'maxlength'=>255],$errors['name']??'');
        $body.=self::control('description',self::tr('pru_idef0_description'),(string)$values['description'],['type'=>'textarea','maxlength'=>4000],$errors['description']??'');
        if (in_array($type,['input','output'],true)) {
            $products=[''=>$type==='input'?self::tr('pru_idef0_input_unbound'):self::tr('procmodel_freeoutput')];
            foreach ($data['products'] as $r) { $products[$r['uuid']]=$r['name']; }
            $productlabel=$type==='input'?self::tr('pru_idef0_input_product'):self::tr('procmodel_product');
            $body.=self::control('productuuid',$productlabel,(string)$values['productuuid'],['options'=>$products],$errors['productuuid']??'');
            if ($type==='input') { $body.=self::note(self::tr('pru_idef0_input_product_help')); }
            else { $body.=self::control('outputtext',self::tr('procmodel_output'),(string)$values['outputtext'],['maxlength'=>2000],$errors['outputtext']??''); }
        } else {
            $body.=bms_workspace_ux_service::form_hidden(['productuuid'=>(string)$values['productuuid']]);
        }
        if ($type!=='output') { $body.=bms_workspace_ux_service::form_hidden(['outputtext'=>(string)$values['outputtext']]); }
        $more=self::control('code',self::tr('pru_modelcode'),(string)$values['code'],['maxlength'=>50]);
        $more.=self::control('sortorder',self::tr('procmodel_sortorder'),(string)$values['sortorder'],['type'=>'number','min'=>0,'max'=>99999]);
        $body.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_more'))).$more,['class'=>'bms-pdu-help']);
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_save')),['type'=>'submit','class'=>'btn btn-primary']),'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    public static function model(string $company, array $m, array $ctx, string $tab, array $data): string {
        $out=self::header($company,$m,$ctx,$tab);$p=$m['process'];$model=$data['model'];
        $out.=self::note(self::tr('pru_readonly'));
        if ($model) { $out.=self::note(self::tr('pru_rev',(string)$model['revision']).' · '.self::tr('procmodel_status_'.$model['status']));
            if ($model['process_revision']) { $out.=self::note(self::tr('pru_modelpin',(string)$model['process_revision'])); }
        } else { $out.=self::panel(self::tr('pru_nomodel'),self::note(self::tr('pru_nomodelhelp'))); }
        if ($m['can_manage'] && $p['status']!=='archived') { $out.=self::link(self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>$tab],$ctx,'process_design'),self::tr('pru_editmodel')); }
        if ($tab==='sipoc') {
            $cols=['supplier'=>$data['sipoc']['suppliers'],'input'=>$data['sipoc']['inputs'],'steps'=>$data['bpmn']['nodes'],'output'=>$data['sipoc']['outputs'],'customer'=>$data['sipoc']['customers']];
            $body='';foreach ($cols as $key=>$items) {
                $content=\html_writer::tag('h2',s(self::tr('pru_'.$key)));
                if ($key==='steps') { $content.=\html_writer::div(s($p['name']),'pru-sipoc-item'); }
                foreach ($items as $item) { if ($key==='steps' && $item['type']!=='task') { continue; } $content.=\html_writer::div(s(($item['target_name']??$item['name']??'')?:self::tr('pru_notset')),'pru-sipoc-item'); }
                if (!$items && $key!=='steps') { $content.=self::note(self::tr('pru_notset')); }
                $body.=\html_writer::tag('section',$content,['class'=>'pru-sipoc-col']);
            }
            $out.=\html_writer::div($body,'pru-sipoc');
            $consistency=$data['idef0_sipoc_consistency']??null;
            if ($consistency && !empty($consistency['applicable'])) {
                $state=(string)($consistency['status']??'consistent');
                $statekey=['consistent'=>'pru_idef0_sipoc_state_consistent','needs_formalisation'=>'pru_idef0_sipoc_state_formalisation',
                    'inconsistent'=>'pru_idef0_sipoc_state_inconsistent'][$state]??'pru_idef0_sipoc_state_inconsistent';
                $summary=self::note(self::tr('pru_idef0_sipoc_short',(object)['score'=>(int)$consistency['score']]).' · '.self::tr($statekey));
                $summary.=self::note(self::tr('pru_idef0_sipoc_help'));
                $summary.=self::link(self::url($company,['process'=>$p['uuid'],'tab'=>'idef0'],$ctx),self::tr('pru_idef0_sipoc_open_idef0'));
                $out.=self::panel(self::tr('pru_idef0_sipoc_title'),$summary);
            }
        } else {
            if ($model) {
                $out.=self::bpmn_canvas($data['bpmn']['xml'], false, $data['bpmn']['xml_stored'], $data['bpmn']['xml_hash']);
            }
            $struct=self::note(self::tr('pru_structuralhelp'));
            $nodes=[];$map=[];foreach ($data['bpmn']['nodes'] as $n) { $map[$n['uuid']]=$n['name'];$nodes[]=[s(self::tr('procmodel_type_'.$n['type'])),s($n['name']),s($n['executor_name']?:self::tr('pru_notassigned')),s($n['product_name']?:$n['output'])]; }
            if ($nodes) { $struct.=self::panel(self::tr('pru_nodes'),bms_workspace_ux_service::record_table([self::tr('process_type'),self::tr('procmodel_name'),self::tr('procmodel_executor'),self::tr('procmodel_output')],$nodes)); }
            $flows=[];foreach ($data['bpmn']['flows'] as $fl) { $flows[]=[s($map[$fl['from_uuid']]??self::tr('pru_unavailable')),s($map[$fl['to_uuid']]??self::tr('pru_unavailable')),s($fl['condition'])]; }
            if ($flows) { $struct.=self::panel(self::tr('pru_flows'),bms_workspace_ux_service::record_table([self::tr('procmodel_from'),self::tr('procmodel_to'),self::tr('procmodel_condition')],$flows)); }
            $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_bpmn_structural'))).$struct,['class'=>'bms-pdu-help']);
        }
        if ($model) { $out.=self::panel(self::tr('procmodel_boundaries'),bms_workspace_ux_service::record_fields([self::tr('procmodel_scope')=>$model['scope'],self::tr('procmodel_trigger')=>$model['trigger'],self::tr('procmodel_startcondition')=>$model['start_condition'],self::tr('procmodel_endcondition')=>$model['end_condition']])); }
        return $out;
    }
    /** Existing structured model editor: no canvas, XML engine or duplicate models. */
    public static function model_editor(string $company, array $record, array $ctx, string $tab, array $data): string {
        $out=self::header($company,$record,$ctx,$tab).self::note(self::tr('pru_editornote'));
        $p=$record['process'];$model=$data['model'];
        $link=static function($tool,$label,$extra=[])use($company,$p,$ctx,$tab) {
            return self::link(self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>$tool,'tab'=>$tab]+$extra,$ctx,'process_design'),self::tr($label));
        };
        $out.=self::link(self::url($company,['process'=>$p['uuid'],'tab'=>$tab],$ctx),self::tr('pru_viewmodel'));
        if (!$model || $model['status']==='published') {
            $out.=self::panel(self::tr('pru_modelstate'),self::note(self::tr($model?'pru_modelimmutable':'pru_nomodelhelp')).$link('draft',$model?'procmodel_newrevision':'procmodel_createdraft'));
        } else {
            $out.=self::panel(self::tr('pru_modelstate'),self::note(self::tr('pru_rev',(string)$model['revision']).' · '.self::tr('procmodel_status_draft')));
            $actions=($tab==='sipoc'?$link('sipoc','pru_sipoc_editor'):$link('bpmn','pru_bpmn_editor')).$link('metadata','procmodel_boundaries').$link('element','procmodel_addinput',['elementtype'=>'input']).$link('element','procmodel_addnode',['elementtype'=>'task']).$link('element','procmodel_addlane',['elementtype'=>'lane']).$link('flow','procmodel_addflow');
            $out.=\html_writer::div($actions,'bms-pdu-actions');
        }
        $out.=self::link(self::url($company,['process'=>$p['uuid'],'view'=>'links'],$ctx),self::tr('procmodel_interfaces'));
        $rows=[];
        foreach ($data['elements'] as $el) {
            $actions='';if ($model && $model['status']==='draft') { $actions=$link('element','pru_edit',['item'=>$el['uuid']]).$link('removeelement','pru_removeelement',['item'=>$el['uuid']]); }
            $rows[]=[s(self::element_type($el['type'])),s($el['name']),s($el['description']),s($el['executor_name']?:self::tr('pru_notassigned')),(string)$el['sort_order'],\html_writer::div($actions,'bms-pdu-actions')];
        }
        if ($rows) { $out.=self::panel(self::tr('pru_elements'),bms_workspace_ux_service::record_table([self::tr('procmodel_nodetype'),self::tr('procmodel_name'),self::tr('procmodel_description'),self::tr('procmodel_executor'),self::tr('procmodel_sortorder'),self::tr('pru_actions')],$rows)); }
        $map=[];foreach ($data['elements'] as $el) { $map[$el['uuid']]=$el['name']; }
        $rows=[];
        foreach ($data['bpmn']['flows'] as $fl) {
            $actions='';if ($model && $model['status']==='draft') { $actions=$link('flow','pru_edit',['item'=>$fl['uuid']]).$link('removeflow','pru_removeflow',['item'=>$fl['uuid']]); }
            $rows[]=[s($map[$fl['from_uuid']]??self::tr('pru_unavailable')),s($map[$fl['to_uuid']]??self::tr('pru_unavailable')),s($fl['condition']),\html_writer::div($actions,'bms-pdu-actions')];
        }
        if ($rows) { $out.=self::panel(self::tr('pru_flows'),bms_workspace_ux_service::record_table([self::tr('procmodel_from'),self::tr('procmodel_to'),self::tr('procmodel_condition'),self::tr('pru_actions')],$rows)); }
        if ($model && $model['status']==='draft') {
            $issues=[];foreach ($data['publication_issues'] as $key) { $issues[]=\html_writer::tag('li',s(self::tr('procmodel_issue_'.$key))); }
            $out.=self::panel(self::tr('procmodel_publishcheck'),$issues?\html_writer::tag('ul',implode('',$issues)):self::note(self::tr('procmodel_ready')).$link('publish','procmodel_publish'));
        }
        if ($data['history']) { $rows=[];foreach ($data['history'] as $h) { $rows[]=[(string)$h['revision'],s(self::tr('procmodel_status_'.$h['status'])),s(userdate($h['time_modified']))]; }
            $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('procmodel_history'))).bms_workspace_ux_service::record_table([self::tr('procmodel_revision'),self::tr('process_status'),self::tr('pdu_modified')],$rows),['class'=>'bms-pdu-help']); }
        return $out;
    }
    public static function element_type(string $type): string {
        return self::tr(['input'=>'pru_input','output'=>'pru_output','lane'=>'procmodel_lane'][$type]??'procmodel_type_'.$type);
    }
    /** Visual SIPOC editor backed by the existing canonical process model and links. */
    public static function sipoc_editor(string $company, array $record, array $ctx, array $data,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];
        $model=$data['model'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'sipoc'],$ctx,'process_design');
        $out=bms_workspace_ux_service::record_header(s(self::tr('pru_sipoc_editor')),s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_sipoc_editor_help'));
        if (!$model || $model['status']!=='draft') {
            return $out.self::panel(self::tr('pru_modelstate'),self::note(self::tr('procmodel_immutable'))).
                self::link($back,self::tr('pru_cancel'));
        }
        $body=bms_workspace_ux_service::form_hidden([
            'uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'sipoc','tab'=>'sipoc',
            'action'=>'sipoc','sesskey'=>sesskey(),'ordering'=>(string)$values['ordering'],
        ]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();

        $manageinterfaces=self::url($company,['process'=>$p['uuid'],'view'=>'links'],$ctx);
        $addinput=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'element','tab'=>'sipoc','elementtype'=>'input'],$ctx,'process_design');
        $addtask=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'element','tab'=>'sipoc','elementtype'=>'task'],$ctx,'process_design');
        $addoutput=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'element','tab'=>'sipoc','elementtype'=>'output'],$ctx,'process_design');

        $elementcards=['input'=>[],'task'=>[],'output'=>[]];
        foreach ($data['elements'] as $el) {
            if (!isset($elementcards[$el['type']])) { continue; }
            $edit=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'element','tab'=>'sipoc','item'=>$el['uuid']],$ctx,'process_design');
            $remove=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'removeelement','tab'=>'sipoc','item'=>$el['uuid']],$ctx,'process_design');
            $meta='';
            if ($el['type']==='task' && $el['executor_name']!=='') { $meta.=\html_writer::div(s($el['executor_name']),'pru-sipoc-meta'); }
            if ($el['type']==='output' && ($el['product_name']!=='' || $el['output']!=='')) {
                $meta.=\html_writer::div(s($el['product_name']!==''?$el['product_name']:$el['output']),'pru-sipoc-meta');
            }
            if ($el['description']!=='') { $meta.=\html_writer::div(s($el['description']),'pru-sipoc-desc'); }
            $actions=\html_writer::tag('button','↑',['type'=>'button','class'=>'btn btn-sm btn-outline-secondary','data-sipoc-move'=>'up','title'=>self::tr('pru_moveup'),'aria-label'=>self::tr('pru_moveup')]).
                \html_writer::tag('button','↓',['type'=>'button','class'=>'btn btn-sm btn-outline-secondary','data-sipoc-move'=>'down','title'=>self::tr('pru_movedown'),'aria-label'=>self::tr('pru_movedown')]).
                self::link($edit,self::tr('pru_edit')).self::link($remove,self::tr('pru_removeelement'));
            $elementcards[$el['type']][]=\html_writer::div(
                \html_writer::div(\html_writer::tag('strong',s($el['name'])).\html_writer::span('↕','pru-sipoc-grip',['aria-hidden'=>'true']),'pru-sipoc-card-title').
                $meta.\html_writer::div($actions,'pru-sipoc-card-actions'),
                'pru-sipoc-card',[
                    'draggable'=>'true','data-sipoc-item'=>$el['uuid'],'data-sipoc-type'=>$el['type'],'tabindex'=>'0',
                ]);
        }

        $relationcards=static function(array $items): string {
            $html='';
            foreach ($items as $item) {
                $label=(string)($item['target_name']??$item['name']??'');
                if ($label==='') { continue; }
                $html.=\html_writer::div(\html_writer::tag('strong',s($label)),'pru-sipoc-card pru-sipoc-card-static');
            }
            return $html;
        };
        $column=static function(string $key, string $title, string $cards, string $action) {
            $head=\html_writer::div(\html_writer::tag('h2',s($title)).$action,'pru-sipoc-colhead');
            $empty=$cards===''?\html_writer::div(s(process_ui_service::tr('pru_sipoc_empty')),'pru-sipoc-empty'):'';
            return \html_writer::tag('section',$head.\html_writer::div($cards.$empty,'pru-sipoc-list',['data-sipoc-list'=>$key]),['class'=>'pru-sipoc-col pru-sipoc-editcol']);
        };
        $supplieraction=self::link($manageinterfaces,self::tr('pru_sipoc_manage_interfaces'));
        $customeraction=self::link($manageinterfaces,self::tr('pru_sipoc_manage_interfaces'));
        $inputaction=self::link($addinput,self::tr('pru_sipoc_add_input'));
        $taskaction=self::link($addtask,self::tr('pru_sipoc_add_step'));
        $outputaction=self::link($addoutput,self::tr('pru_sipoc_add_output'));
        $primary='';
        if (!empty($p['product_name'])) {
            $primary=\html_writer::div(\html_writer::tag('strong',s($p['product_name'])).\html_writer::div(s(self::tr('pru_sipoc_primary_output')),'pru-sipoc-meta'),'pru-sipoc-card pru-sipoc-card-static pru-sipoc-primary');
        }
        $processanchor=\html_writer::div(\html_writer::tag('strong',s($p['name'])).\html_writer::div(s(self::tr('pru_sipoc_process_anchor')),'pru-sipoc-meta'),'pru-sipoc-card pru-sipoc-card-static pru-sipoc-process');
        $board=$column('supplier',self::tr('pru_supplier'),$relationcards($data['sipoc']['suppliers']),$supplieraction).
            $column('input',self::tr('pru_input'),implode('',$elementcards['input']),$inputaction).
            $column('task',self::tr('pru_steps'),$processanchor.implode('',$elementcards['task']),$taskaction).
            $column('output',self::tr('pru_output'),$primary.implode('',$elementcards['output']),$outputaction).
            $column('customer',self::tr('pru_customer'),$relationcards($data['sipoc']['customers']),$customeraction);
        $body.=\html_writer::div($board,'pru-sipoc pru-sipoc-editor',['data-sipoc-board'=>'1']);
        $body.=self::note(self::tr('pru_sipoc_order_help'));
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).
            \html_writer::tag('button',s(self::tr('pru_sipoc_save_order')),['type'=>'submit','class'=>'btn btn-primary']),'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,[
            'method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),
            'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null,
            'data-sipoc-form'=>'1',
        ]);
    }

    private static function bpmn_canvas(string $xml, bool $editable, bool $stored, string $hash): string {
        $toolbar=\html_writer::tag('button',s(self::tr('pru_bpmn_fit')),['type'=>'button','class'=>'btn btn-outline-secondary','data-bpmn-fit'=>'1']).
            \html_writer::tag('button',s(self::tr('pru_bpmn_download')),['type'=>'button','class'=>'btn btn-outline-secondary','data-bpmn-download'=>'1']);
        $status=\html_writer::tag('span',s(self::tr('pru_bpmn_loading')),['class'=>'bms-pdu-note','role'=>'status','aria-live'=>'polite','data-bpmn-status'=>'1','data-loading'=>self::tr('pru_bpmn_loading'),'data-ready'=>self::tr('pru_bpmn_ready'),'data-warning'=>self::tr('pru_bpmn_warning'),'data-saving'=>self::tr('pru_formsaving'),'data-error'=>self::tr('pru_bpmn_unavailable')]);
        $meta=self::note(self::tr($stored?'pru_bpmn_saved_layout':'pru_bpmn_generated_layout').' · SHA256 '.substr($hash,0,12).'…');
        $canvas=\html_writer::div('', 'pru-bpmn-canvas'.($editable?' pru-bpmn-layout-only':' pru-bpmn-readonly'), [
            'data-bpmn-canvas'=>'1','data-bpmn-mode'=>$editable?'edit':'view',
            'data-bpmn-version'=>'18.30.1',
        ]);
        $source=\html_writer::tag('textarea',s($xml),['data-bpmn-source'=>'1','hidden'=>'hidden','aria-hidden'=>'true']);
        return \html_writer::div(\html_writer::div($toolbar.$status,'bms-pdu-actions pru-bpmn-toolbar').$meta.$canvas.$source,'pru-bpmn-shell');
    }

    /** bpmn-js layout editor. Semantic BPMN nodes/flows remain the canonical structural model. */
    public static function bpmn_editor(string $company, array $record, array $ctx, array $data,
            array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process']; $model=$data['model'];
        $back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>'bpmn'],$ctx,'process_design');
        $out=bms_workspace_ux_service::record_header(s(self::tr('pru_bpmn_editor')),s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_bpmn_editor_help'));
        if (!$model || $model['status']!=='draft') {
            return $out.self::panel(self::tr('pru_modelstate'),self::note(self::tr('procmodel_immutable'))).self::link($back,self::tr('pru_cancel'));
        }
        $body=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>'bpmn','tab'=>'bpmn','action'=>'bpmn','sesskey'=>sesskey()]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        $body.=self::bpmn_canvas((string)$values['bpmnxml'],true,(bool)$data['bpmn']['xml_stored'],(string)$data['bpmn']['xml_hash']);
        $body.=\html_writer::div(\html_writer::tag('label',s(self::tr('pru_bpmn_import_file')),['for'=>'pru_bpmn_import']).\html_writer::empty_tag('input',['id'=>'pru_bpmn_import','type'=>'file','accept'=>'.bpmn,.xml,application/xml,text/xml','data-bpmn-import'=>'1']).self::note(self::tr('pru_bpmn_import_help')),'bms-pdu-field');
        $body.=\html_writer::tag('textarea',s((string)$values['bpmnxml']),['name'=>'bpmnxml','data-bpmn-submit'=>'1','hidden'=>'hidden','aria-hidden'=>'true']);
        $body.=self::note(self::tr('pru_bpmn_layout_only'));
        $reset=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tool'=>'bpmnreset','tab'=>'bpmn'],$ctx,'process_design');
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).self::link($reset,self::tr('pru_bpmn_reset')).
            \html_writer::tag('button',s(self::tr('pru_bpmn_save')),['type'=>'submit','class'=>'btn btn-primary','data-bpmn-save'=>'1','disabled'=>'disabled']),
            'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-bpmn-form'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }

    public static function model_form(string $company, array $record, array $ctx, string $tab, array $data, string $tool, string $item, array $values, array $proof, array $errors, bool $dirty = false): string {
        $p=$record['process'];$back=self::url($company,['process'=>$p['uuid'],'mode'=>'edit','tab'=>$tab],$ctx,'process_design');
        $labels=['metadata'=>'procmodel_boundaries','element'=>'pru_modelelement','flow'=>'procmodel_addflow','bpmnreset'=>'pru_bpmn_reset','draft'=>'procmodel_newrevision','publish'=>'procmodel_publish','removeelement'=>'pru_removeelement','removeicom'=>'pru_idef0_remove_icom','removeflow'=>'pru_removeflow'];
        $title=self::tr($labels[$tool]);
        $out=bms_workspace_ux_service::record_header(s($title),s($p['code'].' — '.$p['name']),s($record['company']['name']));
        $out.=self::note(self::tr('pru_editornote'));
        $body=bms_workspace_ux_service::form_hidden(['uuid'=>$company,'process'=>$p['uuid'],'mode'=>'edit','tool'=>$tool,'item'=>$item,'tab'=>$tab,'action'=>$tool,'sesskey'=>sesskey()]+$proof+$ctx);
        $body.=bms_workspace_ux_service::form_errors($errors).self::form_state();
        if ($tool==='metadata') {
            foreach (['scope'=>'procmodel_scope','triggertext'=>'procmodel_trigger','startcondition'=>'procmodel_startcondition','endcondition'=>'procmodel_endcondition'] as $field=>$label) { $body.=self::control($field,self::tr($label),$values[$field],['type'=>'textarea','maxlength'=>$field==='scope'?4000:2000],$errors[$field]??''); }
        } else if ($tool==='element') {
            $opts=[];foreach (process_model_service::ELEMENT_TYPES as $type) { $opts[$type]=self::element_type($type); }
            // Existing element kind is immutable in the canonical model.
            if ($item!=='') { $body.=bms_workspace_ux_service::form_hidden(['elementtype'=>$values['elementtype']]).self::note(self::element_type($values['elementtype'])); }
            else { $body.=self::control('elementtype',self::tr('procmodel_nodetype'),$values['elementtype'],['options'=>$opts]); }
            $body.=self::control('name',self::tr('procmodel_name'),$values['name'],['required'=>true,'maxlength'=>255],$errors['name']??'');
            $body.=self::control('description',self::tr('procmodel_action'),$values['description'],['type'=>'textarea','maxlength'=>4000],$errors['description']??'');
            $exec=[''=>self::tr('procmodel_noexecutor')];foreach (['orgunits'=>'orgunit','positions'=>'position','roles'=>'role'] as $key=>$type) { foreach ($data[$key] as $r) { $exec[$type.'|'.$r['uuid']]=self::tr('procmodel_exec_'.$type).' · '.$r['name']; } }
            $body.=self::control('executor',self::tr('procmodel_executor'),$values['executor'],['options'=>$exec,'help'=>self::tr('pru_taskrequirements')],$errors['executor']??'');
            $products=[''=>self::tr('procmodel_freeoutput')];foreach ($data['products'] as $r) { $products[$r['uuid']]=$r['name']; }
            $body.=self::control('productuuid',self::tr('procmodel_product'),$values['productuuid'],['options'=>$products],$errors['productuuid']??'');
            $body.=self::control('outputtext',self::tr('procmodel_output'),$values['outputtext'],['maxlength'=>2000],$errors['outputtext']??'');
            $lanes=[''=>self::tr('procmodel_nolane')];$activities=[''=>self::tr('procmodel_processlevelref')];
            foreach ($data['elements'] as $r) { if ($r['type']==='lane') { $lanes[$r['uuid']]=$r['name']; } if ($r['type']==='task') { $activities[$r['uuid']]=$r['name']; } }
            $more=self::control('laneuuid',self::tr('procmodel_lane'),$values['laneuuid'],['options'=>$lanes]);
            $more.=self::control('activityuuid',self::tr('pru_activityref'),$values['activityuuid'],['options'=>$activities]);
            $more.=self::control('code',self::tr('pru_modelcode'),$values['code'],['maxlength'=>50]);
            $more.=self::control('sortorder',self::tr('procmodel_sortorder'),(string)$values['sortorder'],['type'=>'number','min'=>0,'max'=>99999]);
            $body.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pru_more'))).$more,['class'=>'bms-pdu-help']);
        } else if ($tool==='flow') {
            $opts=[''=>self::tr('pru_choose')];foreach ($data['bpmn']['nodes'] as $n) { $opts[$n['uuid']]=self::element_type($n['type']).' · '.$n['name']; }
            foreach (['fromuuid'=>'procmodel_from','touuid'=>'procmodel_to'] as $f=>$label) { $body.=self::control($f,self::tr($label),$values[$f],['options'=>$opts,'required'=>true],$errors[$f]??''); }
            $body.=self::control('label',self::tr('pru_flowlabel'),$values['label'],['maxlength'=>255],$errors['label']??'');
            $body.=self::control('conditiontext',self::tr('procmodel_condition'),$values['conditiontext'],['maxlength'=>2000],$errors['conditiontext']??'');
            $body.=self::control('sortorder',self::tr('procmodel_sortorder'),(string)$values['sortorder'],['type'=>'number','min'=>0,'max'=>99999]);
        } else {
            if (!empty($values['label'])) { $body.=self::note($values['label']); }
            $body.=self::note(self::tr($tool==='draft'?'pru_clonemodel':($tool==='publish'?'pru_publishmodelhelp':($tool==='bpmnreset'?'pru_bpmn_reset_help':'pru_removemodelhelp'))));
            $body.=\html_writer::div(\html_writer::empty_tag('input',['type'=>'checkbox','id'=>'pru_confirm','name'=>'confirmed','value'=>'1','required'=>'required','checked'=>!empty($values['confirmed'])?'checked':null]).\html_writer::label(s(self::tr('pru_confirm')),'pru_confirm'),'bms-pdu-confirm');
        }
        $destructive=in_array($tool,['removeelement','removeicom','removeflow'],true);
        $body.=\html_writer::div(self::link($back,self::tr('pru_cancel')).\html_writer::tag('button',s(self::tr(in_array($tool,['metadata','element','flow'],true)?'pru_save':$labels[$tool])),['type'=>'submit','class'=>'btn '.($destructive?'btn-danger':'btn-primary')]),'bms-pdu-actions bms-pdu-footer');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company,[],[],'process_design')->out(false),'class'=>'bms-pdu-form','data-pru-dirty'=>'1','data-pru-initial-dirty'=>($dirty||$errors)?'1':null]);
    }
}
