<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation-only renderer for the generated process documentation projection. */
class process_document_ui_service {
    private static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    private static function val(string $value): string { return trim($value) !== '' ? s($value) : '—'; }
    private static function join(array $values): string { return $values ? s(implode('; ', $values)) : '—'; }
    private static function status_label(string $status): string {
        if ($status === 'none') { return self::tr('processdoc_nomodel'); }
        $key = 'procmodel_status_' . $status;
        return get_string_manager()->string_exists($key, 'local_kbacompany') ? self::tr($key) : $status;
    }
    private static function table(array $head, array $rows, string $class=''): string {
        if (!$rows) { return \html_writer::div('—', 'processdoc-empty'); }
        return bms_workspace_ux_service::record_table($head, $rows);
    }
    private static function list_items(array $items): string {
        if (!$items) { return \html_writer::div('—', 'processdoc-empty'); }
        $out=''; foreach ($items as $item) { $out.=\html_writer::tag('li',s((string)$item)); }
        return \html_writer::tag('ul',$out,['class'=>'processdoc-list']);
    }
    private static function canonical_list(array $items): string {
        if (!$items) { return \html_writer::div('—','processdoc-empty'); }
        $out='';
        foreach ($items as $item) {
            $type=(string)($item['type']??'');$label=self::tr('processdoc_ref_'.$type);
            $name=trim((string)($item['code']??'')); if ($name!=='') { $name.=' — '; } $name.=(string)($item['name']??'');
            $out.=\html_writer::tag('li',\html_writer::span(s($label),'processdoc-ref-type').' '.s($name));
        }
        return \html_writer::tag('ul',$out,['class'=>'processdoc-list']);
    }
    private static function diagnostics(array $d): string {
        $rows=[];$consistency=$d['idef0']['consistency'];$boundary=$d['idef0']['boundary'];$balance=$d['idef0']['balance'];
        if (!empty($consistency['applicable'])) {
            $rows[]=[self::tr('processdoc_diag_consistency'),s((string)($consistency['score']??0).'%'),s(self::tr('processdoc_consistency_'.$consistency['status']))];
        }
        if (!empty($boundary['applicable'])) {
            $rows[]=[self::tr('processdoc_diag_boundary'),s((string)($boundary['score']??0).'%'),s(self::tr('processdoc_issues',(int)($boundary['issue_count']??0)))];
        }
        if (!empty($balance['total'])) {
            $rows[]=[self::tr('processdoc_diag_connections'),s((string)$balance['balanced'].' / '.(string)$balance['total']),s(self::tr('processdoc_issues',(int)$balance['issues']))];
        }
        return self::table([self::tr('processdoc_diagnostic'),self::tr('processdoc_result'),self::tr('processdoc_state')],$rows);
    }

    public static function screen(string $companyuuid, array $d): string {
        $p=$d['process'];$doc=$d['document'];$model=$d['model'];
        $back=new \moodle_url('/local/kbacompany/processes.php',['uuid'=>$companyuuid,'process'=>$p['uuid']]);
        $print=new \moodle_url('/local/kbacompany/process_document.php',['uuid'=>$companyuuid,'process'=>$p['uuid'],'format'=>'print']);
        $download=new \moodle_url('/local/kbacompany/process_document.php',['uuid'=>$companyuuid,'process'=>$p['uuid'],'format'=>'html']);
        $actions=\html_writer::link($print,s(self::tr('processdoc_print')),['class'=>'btn btn-outline-secondary','target'=>'_blank']).' '.
            \html_writer::link($download,s(self::tr('processdoc_downloadhtml')),['class'=>'btn btn-outline-secondary']);
        $out=\html_writer::div(\html_writer::link($back,'← '.s(self::tr('processdoc_back'))),'processdoc-back');
        $out.=bms_workspace_ux_service::record_header(
            s(self::tr('processdoc_title').' '.$p['code'].' — '.$p['name']),
            s(self::tr('processdoc_generated')),
            s($d['company']['name']),
            s(self::tr('processdoc_revisionline',(object)[
                'process'=>(int)$doc['process_revision'],
                'model'=>(int)$doc['model_revision'],
                'status'=>self::status_label((string)$doc['model_status']),
            ])), $actions);
        $out.=\html_writer::div(s(self::tr('processdoc_snapshotnotice')),'bms-pdu-notice processdoc-notice');

        $meta=bms_workspace_ux_service::record_fields([
            self::tr('productcode')=>$p['code'],
            self::tr('process_type')=>process_ui_service::types()[$p['type']],
            self::tr('process_level')=>$p['level'],
            self::tr('process_owner')=>$p['owner_name']?:'—',
            self::tr('process_product')=>$p['product_name']?:'—',
            self::tr('process_status')=>self::tr('process_status_'.$p['status']),
        ]);
        $meta.=\html_writer::div(\html_writer::tag('strong',s(self::tr('process_purpose'))).\html_writer::div(self::val((string)$p['purpose']),'processdoc-text'),'processdoc-field');
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_identity'),$meta);

        $modelbody=bms_workspace_ux_service::record_fields([
            self::tr('procmodel_scope')=>$d['model_context']['scope']?:'—',
            self::tr('procmodel_trigger')=>$d['model_context']['trigger']?:'—',
            self::tr('procmodel_startcondition')=>$d['model_context']['start_condition']?:'—',
            self::tr('procmodel_endcondition')=>$d['model_context']['end_condition']?:'—',
        ]);
        if (!$model) { $modelbody=\html_writer::div(s(self::tr('processdoc_nomodelhelp')),'processdoc-empty'); }
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_model'),$modelbody);

        $icomrows=[];
        foreach ([
            [self::tr('pru_idef0_inputs'),$d['idef0']['inputs']],
            [self::tr('pru_idef0_controls'),$d['idef0']['controls']],
            [self::tr('pru_idef0_outputs'),$d['idef0']['outputs']],
            [self::tr('pru_idef0_mechanisms'),$d['idef0']['mechanisms']],
        ] as $row) {
            $names=[];foreach ($row[1] as $item) { $name=trim((string)($item['product_name']??'')); if ($name==='') {$name=trim((string)($item['name']??''));} if ($name!=='') {$names[]=$name;} }
            $icomrows[]=[s($row[0]),self::join(array_values(array_unique($names)))];
        }
        $idef0=\html_writer::div(\html_writer::span(s($d['idef0']['notation']),'processdoc-notation').' '.s($p['name']),'processdoc-function');
        $idef0.=self::table([self::tr('processdoc_icom_type'),self::tr('processdoc_icom_items')],$icomrows);
        $idef0.=\html_writer::tag('h4',s(self::tr('processdoc_canonical_controls'))).self::canonical_list($d['idef0']['canonical_controls']);
        $idef0.=\html_writer::tag('h4',s(self::tr('processdoc_canonical_mechanisms'))).self::canonical_list($d['idef0']['canonical_mechanisms']);
        $out.=bms_workspace_ux_service::record_panel('IDEF0',$idef0);

        $decomp=[];foreach ($d['idef0']['children'] as $child) {
            $decomp[]=[s($child['notation']),s($child['code'].' — '.$child['name']),self::val($child['owner']),self::val($child['product'])];
        }
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_decomposition'),
            self::table([self::tr('processdoc_notation'),self::tr('process_name'),self::tr('process_owner'),self::tr('process_product')],$decomp));

        $edges=[];foreach ($d['idef0']['interconnections'] as $edge) {
            $from=trim($edge['from_notation'].' '.$edge['from_code'].' — '.$edge['from']);
            $to=trim($edge['to_notation'].' '.$edge['to_code'].' — '.$edge['to']);
            $edges[]=[s($from),s($to),self::val($edge['product']),s(self::tr('processdoc_balance_'.$edge['balance_status']))];
        }
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_interconnections'),
            self::table([self::tr('processdoc_from'),self::tr('processdoc_to'),self::tr('process_product'),self::tr('processdoc_state')],$edges));
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_diagnostics'),self::diagnostics($d));

        $sipoc=[[
            self::join($d['sipoc']['suppliers']),self::join($d['sipoc']['inputs']),s($p['name']),
            self::join($d['sipoc']['outputs']),self::join($d['sipoc']['customers'])
        ]];
        $out.=bms_workspace_ux_service::record_panel('SIPOC',self::table(['Supplier','Input','Process','Output','Customer'],$sipoc));

        $steps=[];foreach ($d['procedure'] as $step) {
            $activity=$step['name'].($step['description']!==''?' — '.$step['description']:'');
            $steps[]=[(string)$step['number'],s($activity),self::val($step['product']),self::join($step['raci']['R']),self::join($step['raci']['A']),self::join($step['raci']['C']),self::join($step['raci']['I'])];
        }
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_procedure'),
            self::table(['#',self::tr('procmodel_action'),self::tr('procmodel_output'),'R','A','C','I'],$steps));

        $ann=$d['annotations'];
        $other=bms_workspace_ux_service::record_fields([
            self::tr('stp_metrics')=>$ann['metrics']?implode('; ',$ann['metrics']):'—',
            self::tr('stp_documents')=>$ann['documents']?implode('; ',$ann['documents']):'—',
            self::tr('stp_systems')=>$ann['systems']?implode('; ',$ann['systems']):'—',
            self::tr('stp_resources')=>$ann['resources']?implode('; ',$ann['resources']):'—',
            self::tr('stp_records')=>$ann['records']?implode('; ',$ann['records']):'—',
            self::tr('stp_controls')=>$ann['controls']?implode('; ',$ann['controls']):'—',
        ]);
        $out.=bms_workspace_ux_service::record_panel(self::tr('processdoc_section_resources'),$other);
        $out.=\html_writer::div(s(self::tr('processdoc_fingerprint')).': '.s($doc['source_fingerprint']),'processdoc-fingerprint');
        return \html_writer::div($out,'processdoc-screen');
    }

    private static function esc($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private static function raw_join(array $values): string { return self::esc($values ? implode('; ',$values) : '—'); }
    private static function raw_rows(array $rows): string {
        $out=''; foreach ($rows as $row) { $out.='<tr>';foreach ($row as $cell) {$out.='<td>'.self::esc($cell).'</td>';} $out.='</tr>'; } return $out;
    }
    private static function raw_table(array $head, array $rows): string {
        if (!$rows) { return '<p>—</p>'; }
        $h='';foreach ($head as $cell){$h.='<th>'.self::esc($cell).'</th>';}
        return '<table><thead><tr>'.$h.'</tr></thead><tbody>'.self::raw_rows($rows).'</tbody></table>';
    }

    /** Standalone printable/exportable HTML snapshot. */
    public static function standalone_html(array $d): string {
        $p=$d['process'];$doc=$d['document'];$model=$d['model'];
        $title=self::tr('processdoc_title').' '.$p['code'].' — '.$p['name'];
        $css='@page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#1f2a24;margin:0;background:#fff;font-size:12px;line-height:1.38}main{max-width:1100px;margin:0 auto;padding:24px}h1{font-size:24px;margin:0 0 6px}h2{font-size:17px;margin:24px 0 8px;border-bottom:1px solid #cfd9d2;padding-bottom:5px}h3{font-size:14px;margin:16px 0 6px}.meta{color:#52655a;margin:0 0 16px}.notice{padding:9px 11px;background:#f3f8f4;border:1px solid #d8e4dc;border-radius:6px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px}.field b{display:block;color:#52655a;font-size:10px;text-transform:uppercase}.function{border:2px solid #496d58;padding:14px;text-align:center;font-size:18px;font-weight:700;margin:12px auto;max-width:560px}.tag{display:inline-block;padding:2px 7px;background:#eef6f0;border-radius:999px;margin-right:5px;font-size:10px}table{border-collapse:collapse;width:100%;margin:8px 0 14px}th,td{border:1px solid #bfcac2;padding:6px;vertical-align:top;text-align:left}th{background:#f3f7f4;font-size:10px}ul{margin:6px 0 12px;padding-left:20px}.fingerprint{margin-top:28px;color:#7a857d;font-size:9px;word-break:break-all}@media print{main{max-width:none;padding:0}a{color:inherit;text-decoration:none}h2{break-after:avoid}table{break-inside:auto}tr{break-inside:avoid}.no-print{display:none}}';
        $html='<!doctype html><html lang="'.self::esc(current_language()).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::esc($title).'</title><style>'.$css.'</style></head><body><main>';
        $html.='<h1>'.self::esc($title).'</h1><p class="meta">'.self::esc(self::tr('processdoc_revisionline',(object)[
            'process'=>$doc['process_revision'],'model'=>$doc['model_revision'],'status'=>self::status_label($doc['model_status'])
        ])).' · '.self::esc(userdate($doc['generated_at'])).'</p>';
        $html.='<p class="notice">'.self::esc(self::tr('processdoc_snapshotnotice')).'</p>';
        $html.='<h2>'.self::esc(self::tr('processdoc_section_identity')).'</h2><div class="grid">';
        foreach ([self::tr('productcode')=>$p['code'],self::tr('process_type')=>process_ui_service::types()[$p['type']],self::tr('process_level')=>$p['level'],self::tr('process_owner')=>$p['owner_name']?:'—',self::tr('process_product')=>$p['product_name']?:'—',self::tr('process_status')=>self::tr('process_status_'.$p['status'])] as $label=>$value) {
            $html.='<div class="field"><b>'.self::esc($label).'</b>'.self::esc($value).'</div>';
        }
        $html.='</div><h3>'.self::esc(self::tr('process_purpose')).'</h3><p>'.self::esc($p['purpose']?:'—').'</p>';
        $html.='<h2>'.self::esc(self::tr('processdoc_section_model')).'</h2>';
        if (!$model) { $html.='<p>'.self::esc(self::tr('processdoc_nomodelhelp')).'</p>'; }
        else {
            foreach ([self::tr('procmodel_scope')=>$d['model_context']['scope'],self::tr('procmodel_trigger')=>$d['model_context']['trigger'],self::tr('procmodel_startcondition')=>$d['model_context']['start_condition'],self::tr('procmodel_endcondition')=>$d['model_context']['end_condition']] as $label=>$value) {
                $html.='<p><b>'.self::esc($label).':</b> '.self::esc($value?:'—').'</p>';
            }
        }
        $html.='<h2>IDEF0</h2><div class="function"><span class="tag">'.self::esc($d['idef0']['notation']).'</span>'.self::esc($p['name']).'</div>';
        $icom=[];foreach ([[self::tr('pru_idef0_inputs'),$d['idef0']['inputs']],[self::tr('pru_idef0_controls'),$d['idef0']['controls']],[self::tr('pru_idef0_outputs'),$d['idef0']['outputs']],[self::tr('pru_idef0_mechanisms'),$d['idef0']['mechanisms']]] as $r){$names=[];foreach($r[1] as $i){$n=trim((string)($i['product_name']??''));if($n===''){$n=trim((string)($i['name']??''));}if($n!==''){$names[]=$n;}}$icom[]=[$r[0],implode('; ',array_values(array_unique($names)))?:'—'];}
        $html.=self::raw_table([self::tr('processdoc_icom_type'),self::tr('processdoc_icom_items')],$icom);
        $html.='<h3>'.self::esc(self::tr('processdoc_canonical_controls')).'</h3><ul>';foreach($d['idef0']['canonical_controls'] as $i){$html.='<li>'.self::esc(($i['code']? $i['code'].' — ':'').$i['name']).'</li>';}$html.='</ul>';
        $html.='<h3>'.self::esc(self::tr('processdoc_canonical_mechanisms')).'</h3><ul>';foreach($d['idef0']['canonical_mechanisms'] as $i){$html.='<li>'.self::esc(($i['code']? $i['code'].' — ':'').$i['name']).'</li>';}$html.='</ul>';
        $rows=[];foreach($d['idef0']['children'] as $c){$rows[]=[$c['notation'],$c['code'].' — '.$c['name'],$c['owner']?:'—',$c['product']?:'—'];}
        $html.='<h2>'.self::esc(self::tr('processdoc_section_decomposition')).'</h2>'.self::raw_table([self::tr('processdoc_notation'),self::tr('process_name'),self::tr('process_owner'),self::tr('process_product')],$rows);
        $rows=[];foreach($d['idef0']['interconnections'] as $e){$rows[]=[trim($e['from_notation'].' '.$e['from_code'].' — '.$e['from']),trim($e['to_notation'].' '.$e['to_code'].' — '.$e['to']),$e['product']?:'—',self::tr('processdoc_balance_'.$e['balance_status'])];}
        $html.='<h2>'.self::esc(self::tr('processdoc_section_interconnections')).'</h2>'.self::raw_table([self::tr('processdoc_from'),self::tr('processdoc_to'),self::tr('process_product'),self::tr('processdoc_state')],$rows);
        $diagrows=[];$consistency=$d['idef0']['consistency'];$boundary=$d['idef0']['boundary'];$balance=$d['idef0']['balance'];
        if (!empty($consistency['applicable'])) {$diagrows[]=[self::tr('processdoc_diag_consistency'),(string)($consistency['score']??0).'%',self::tr('processdoc_consistency_'.$consistency['status'])];}
        if (!empty($boundary['applicable'])) {$diagrows[]=[self::tr('processdoc_diag_boundary'),(string)($boundary['score']??0).'%',self::tr('processdoc_issues',(int)($boundary['issue_count']??0))];}
        if (!empty($balance['total'])) {$diagrows[]=[self::tr('processdoc_diag_connections'),(string)$balance['balanced'].' / '.(string)$balance['total'],self::tr('processdoc_issues',(int)$balance['issues'])];}
        $html.='<h2>'.self::esc(self::tr('processdoc_section_diagnostics')).'</h2>'.self::raw_table([self::tr('processdoc_diagnostic'),self::tr('processdoc_result'),self::tr('processdoc_state')],$diagrows);
        $html.='<h2>SIPOC</h2>'.self::raw_table(['Supplier','Input','Process','Output','Customer'],[[implode('; ',$d['sipoc']['suppliers'])?:'—',implode('; ',$d['sipoc']['inputs'])?:'—',$p['name'],implode('; ',$d['sipoc']['outputs'])?:'—',implode('; ',$d['sipoc']['customers'])?:'—']]);
        $rows=[];foreach($d['procedure'] as $step){$activity=$step['name'].($step['description']!==''?' — '.$step['description']:'');$rows[]=[$step['number'],$activity,$step['product']?:'—',implode('; ',$step['raci']['R'])?:'—',implode('; ',$step['raci']['A'])?:'—',implode('; ',$step['raci']['C'])?:'—',implode('; ',$step['raci']['I'])?:'—'];}
        $html.='<h2>'.self::esc(self::tr('processdoc_section_procedure')).'</h2>'.self::raw_table(['#',self::tr('procmodel_action'),self::tr('procmodel_output'),'R','A','C','I'],$rows);
        $html.='<h2>'.self::esc(self::tr('processdoc_section_resources')).'</h2>';
        foreach ([self::tr('stp_metrics')=>$d['annotations']['metrics'],self::tr('stp_documents')=>$d['annotations']['documents'],self::tr('stp_systems')=>$d['annotations']['systems'],self::tr('stp_resources')=>$d['annotations']['resources'],self::tr('stp_records')=>$d['annotations']['records'],self::tr('stp_controls')=>$d['annotations']['controls']] as $label=>$items){$html.='<p><b>'.self::esc($label).':</b> '.self::raw_join($items).'</p>';}
        $html.='<p class="fingerprint">'.self::esc(self::tr('processdoc_fingerprint')).': '.self::esc($doc['source_fingerprint']).'</p></main></body></html>';
        return $html;
    }
}
