<?php
require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageimplementationprograms', $context);
$id = optional_param('id', 0, PARAM_INT);
$record = $id ? $DB->get_record('local_kbacorp_impl_package', ['id' => $id], '*', MUST_EXIST) : null;
if (optional_param('save', 0, PARAM_BOOL)) {
    require_sesskey();
    $code = $record ? $record->code : clean_param(required_param('code', PARAM_ALPHANUMEXT), PARAM_ALPHANUMEXT);
    $data = (object)[
        'code' => strtolower($code), 'name' => required_param('name', PARAM_TEXT),
        'description' => optional_param('description', '', PARAM_TEXT),
        'features' => optional_param('features', '', PARAM_TEXT),
        'price' => optional_param('price', '', PARAM_RAW) === '' ? null : (float)str_replace(',', '.', optional_param('price', '', PARAM_RAW)),
        'currency' => strtoupper(optional_param('currency', 'RUB', PARAM_ALPHA)),
        'durationdays' => optional_param('durationdays', 0, PARAM_INT),
        'sortorder' => optional_param('sortorder', 0, PARAM_INT), 'active' => optional_param('active', 0, PARAM_BOOL),
        'timemodified' => time(),
    ];
    if ($record) { $data->id = $record->id; $DB->update_record('local_kbacorp_impl_package', $data); }
    else { $data->timecreated = time(); $DB->insert_record('local_kbacorp_impl_package', $data); }
    redirect(new moodle_url('/local/kbacorporate/implementation_packages.php'), get_string('implementationpackagesaved', 'local_kbacorporate'));
}
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_packages.php', $id ? ['id' => $id] : []));
$PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('implementationpackages', 'local_kbacorporate'));
$PAGE->set_heading(get_string('implementationpackages', 'local_kbacorporate'));
echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation(0);
echo $OUTPUT->heading(get_string('implementationpackages', 'local_kbacorporate'));
$table = new html_table(); $table->head = [get_string('code'), get_string('name'), get_string('price'),
    get_string('durationdays', 'local_kbacorporate'), get_string('status'), get_string('actions')];
foreach (\local_kbacorporate\local\implementation_packages::all() as $package) {
    $price = $package->price === null ? '—' : format_float($package->price, 2) . ' ' . s($package->currency);
    $table->data[] = [s($package->code), format_string($package->name), $price, (int)$package->durationdays,
        $package->active ? get_string('active') : get_string('inactive'), html_writer::link(
            new moodle_url('/local/kbacorporate/implementation_packages.php', ['id' => $package->id]), get_string('edit'))];
}
echo html_writer::table($table);
echo $OUTPUT->heading(get_string($record ? 'editimplementationpackage' : 'addimplementationpackage', 'local_kbacorporate'), 3);
$values = $record ?: (object)['code' => '', 'name' => '', 'description' => '', 'features' => '', 'price' => null,
    'currency' => 'RUB', 'durationdays' => 0, 'sortorder' => 0, 'active' => 1];
echo html_writer::start_tag('form', ['method' => 'post']);
foreach (['sesskey' => sesskey(), 'save' => 1, 'id' => $id] as $n => $v) echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>$n,'value'=>$v]);
$input = static function($name, $value, $type='text') { return html_writer::empty_tag('input',
    ['class'=>'form-control','type'=>$type,'name'=>$name,'value'=>$value]); };
echo html_writer::div(html_writer::tag('label', get_string('code')) . $input('code', s($values->code)) , 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('name')) . $input('name', s($values->name)), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('description')) . html_writer::tag('textarea', s($values->description), ['name'=>'description','class'=>'form-control']), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('packagefeatures', 'local_kbacorporate')) . html_writer::tag('textarea', s($values->features), ['name'=>'features','class'=>'form-control']), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('price')) . $input('price', $values->price), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('currency')) . $input('currency', s($values->currency)), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('durationdays', 'local_kbacorporate')) . $input('durationdays', $values->durationdays, 'number'), 'form-group');
echo html_writer::div(html_writer::tag('label', get_string('sortorder')) . $input('sortorder', $values->sortorder, 'number'), 'form-group');
echo html_writer::checkbox('active', 1, $values->active, get_string('active'));
echo html_writer::tag('button', get_string('savechanges'), ['class'=>'btn btn-primary ml-2','type'=>'submit']);
echo html_writer::end_tag('form'); echo $OUTPUT->footer();
