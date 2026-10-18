<?php
require_once('../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('kbaelement', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!is_siteadmin() && !has_capability('mod/kbaelement:grade', $context)
        && !has_capability('local/kbaai:manage', $context)) {
    throw new required_capability_exception($context, 'local/kbaai:manage', 'nopermissions', '');
}

$PAGE->set_url(new moodle_url('/local/kbaai/rubric.php', array('cmid' => $cmid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('visualrubric', 'local_kbaai'));
$PAGE->set_heading(format_string($course->fullname));

$adapter = new \local_kbaai\source\kbaelement_adapter();
$maxscore = $adapter->get_max_grade($cm->instance);
$service = new \local_kbaai\service\rubric_service();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $payload = required_param('rubricpayload', PARAM_RAW);
    try {
        $service->save($cm->instance, $payload, $USER->id, $maxscore);
        redirect($PAGE->url, get_string('rubricsaved', 'local_kbaai'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (Throwable $e) {
        redirect($PAGE->url, get_string('rubricsavefailed', 'local_kbaai', $e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$rubric = $service->get($cm->instance, $maxscore);
$consoleurl = new moodle_url('/local/kbaai/console.php', array('cmid' => $cmid));
$workurl = new moodle_url('/mod/kbaelement/view.php', array('id' => $cmid, 'forceview' => 1));

// Use a clean serialisable payload for the JS builder.
$initial = array('criteria' => array_values($rubric['criteria']));

$PAGE->requires->js_init_code("(function(){
    var initial = " . json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
    var maxScore = " . json_encode((float)$maxscore) . ";
    var container = document.getElementById('kbaai-rubric-criteria');
    var totalNode = document.getElementById('kbaai-rubric-total');
    var stateNode = document.getElementById('kbaai-rubric-state');
    var payloadNode = document.getElementById('kbaai-rubric-payload');
    var form = document.getElementById('kbaai-rubric-form');

    function esc(v){ return String(v == null ? '' : v).replace(/[&<>\"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#039;'}[c];}); }
    function criterionTemplate(c, idx){
        var levels = Array.isArray(c.levels) ? c.levels : [];
        var levelHtml = levels.map(function(l){ return levelTemplate(l); }).join('');
        return '<div class=\"card mb-3 kbaai-rubric-item\" data-index=\"'+idx+'\">' +
          '<div class=\"card-header d-flex justify-content-between align-items-center\"><strong>'+" . json_encode(get_string('criterion', 'local_kbaai')) . "+' '+(idx+1)+'</strong><button type=\"button\" class=\"btn btn-sm btn-outline-danger kbaai-remove-criterion\">'+" . json_encode(get_string('removecriterion', 'local_kbaai')) . "+'</button></div>' +
          '<div class=\"card-body\">' +
          '<div class=\"form-group\"><label>'+" . json_encode(get_string('criteriontitle', 'local_kbaai')) . "+'</label><input type=\"text\" class=\"form-control kbaai-title\" value=\"'+esc(c.title || '')+'\"></div>' +
          '<div class=\"form-group\"><label>'+" . json_encode(get_string('criteriondescription', 'local_kbaai')) . "+'</label><textarea class=\"form-control kbaai-description\" rows=\"3\">'+esc(c.description || '')+'</textarea></div>' +
          '<div class=\"form-row\"><div class=\"form-group col-md-4\"><label>'+" . json_encode(get_string('criterionmaxscore', 'local_kbaai')) . "+'</label><input type=\"number\" min=\"0.01\" step=\"0.01\" class=\"form-control kbaai-maxscore\" value=\"'+esc(c.maxscore || '')+'\"></div>' +
          '<div class=\"form-group col-md-4 d-flex align-items-end\"><label class=\"mb-2\"><input type=\"checkbox\" class=\"kbaai-required\" '+(c.required === false ? '' : 'checked')+'> '+" . json_encode(get_string('criterionrequired', 'local_kbaai')) . "+'</label></div></div>' +
          '<h6 class=\"mt-2\">'+" . json_encode(get_string('rubriclevels', 'local_kbaai')) . "+'</h6><div class=\"kbaai-levels\">'+levelHtml+'</div>' +
          '<button type=\"button\" class=\"btn btn-sm btn-outline-secondary kbaai-add-level\">+ '+" . json_encode(get_string('addlevel', 'local_kbaai')) . "+'</button>' +
          '</div></div>';
    }
    function levelTemplate(l){
        return '<div class=\"border rounded p-2 mb-2 kbaai-level\"><div class=\"form-row\">' +
          '<div class=\"form-group col-md-2 mb-2\"><label>'+" . json_encode(get_string('levelscore', 'local_kbaai')) . "+'</label><input type=\"number\" min=\"0\" step=\"0.01\" class=\"form-control kbaai-level-score\" value=\"'+esc(l.score == null ? 0 : l.score)+'\"></div>' +
          '<div class=\"form-group col-md-3 mb-2\"><label>'+" . json_encode(get_string('levellabel', 'local_kbaai')) . "+'</label><input type=\"text\" class=\"form-control kbaai-level-label\" value=\"'+esc(l.label || '')+'\"></div>' +
          '<div class=\"form-group col-md-6 mb-2\"><label>'+" . json_encode(get_string('leveldescription', 'local_kbaai')) . "+'</label><input type=\"text\" class=\"form-control kbaai-level-description\" value=\"'+esc(l.description || '')+'\"></div>' +
          '<div class=\"form-group col-md-1 mb-2 d-flex align-items-end\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger kbaai-remove-level\">×</button></div>' +
          '</div></div>';
    }
    function render(){
        container.innerHTML = initial.criteria.map(criterionTemplate).join('');
        updateTotal();
    }
    function collect(){
        var criteria=[];
        container.querySelectorAll('.kbaai-rubric-item').forEach(function(item, idx){
            var levels=[];
            item.querySelectorAll('.kbaai-level').forEach(function(level){
                levels.push({score:parseFloat(level.querySelector('.kbaai-level-score').value || '0'), label:level.querySelector('.kbaai-level-label').value, description:level.querySelector('.kbaai-level-description').value});
            });
            criteria.push({
                id:'C'+(idx+1),
                title:item.querySelector('.kbaai-title').value,
                description:item.querySelector('.kbaai-description').value,
                maxscore:parseFloat(item.querySelector('.kbaai-maxscore').value || '0'),
                required:item.querySelector('.kbaai-required').checked,
                levels:levels
            });
        });
        return {criteria:criteria};
    }
    function updateTotal(){
        var p=collect(), total=0;
        p.criteria.forEach(function(c){ total += isNaN(c.maxscore) ? 0 : c.maxscore; });
        totalNode.textContent = total.toFixed(2) + ' / ' + Number(maxScore).toFixed(2);
        var ok = Math.abs(total - maxScore) <= 0.01;
        stateNode.textContent = ok ? " . json_encode(get_string('rubrictotalok', 'local_kbaai')) . " : " . json_encode(get_string('rubrictotalbad', 'local_kbaai')) . ";
        stateNode.className = ok ? 'text-success font-weight-bold' : 'text-danger font-weight-bold';
        return ok;
    }
    document.getElementById('kbaai-add-criterion').addEventListener('click', function(){
        initial = collect();
        initial.criteria.push({title:'',description:'',maxscore:0,required:true,levels:[{score:0,label:" . json_encode(get_string('rubriclevelnotmet', 'local_kbaai')) . ",description:''},{score:0,label:" . json_encode(get_string('rubriclevelfull', 'local_kbaai')) . ",description:''}]});
        render();
    });
    container.addEventListener('click', function(e){
        if(e.target.classList.contains('kbaai-remove-criterion')){ initial=collect(); var card=e.target.closest('.kbaai-rubric-item'); var idx=[].indexOf.call(container.children,card); initial.criteria.splice(idx,1); render(); }
        if(e.target.classList.contains('kbaai-add-level')){ var levels=e.target.parentNode.querySelector('.kbaai-levels'); levels.insertAdjacentHTML('beforeend', levelTemplate({score:0,label:'',description:''})); updateTotal(); }
        if(e.target.classList.contains('kbaai-remove-level')){ e.target.closest('.kbaai-level').remove(); updateTotal(); }
    });
    container.addEventListener('input', updateTotal);
    form.addEventListener('submit', function(e){
        var payload=collect();
        if(!payload.criteria.length){ e.preventDefault(); alert(" . json_encode(get_string('rubriccriterioncount', 'local_kbaai')) . "); return; }
        if(!updateTotal()){ e.preventDefault(); alert(" . json_encode(get_string('rubrictotalbadalert', 'local_kbaai')) . "); return; }
        payloadNode.value=JSON.stringify(payload);
    });
    render();
})();");

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('visualrubric', 'local_kbaai') . ' — ' . format_string($cm->name));

echo html_writer::start_div('mb-4');
echo html_writer::link($consoleurl, get_string('backtoconsole', 'local_kbaai'), array('class' => 'btn btn-outline-secondary mr-2'));
echo html_writer::link($workurl, get_string('navlearningelement', 'local_kbaai'), array('class' => 'btn btn-outline-secondary'));
echo html_writer::end_div();

echo html_writer::start_div('card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('rubricbuildertitle', 'local_kbaai'), array('class' => 'h5'));
echo html_writer::tag('p', get_string('rubricbuilderhelp', 'local_kbaai'), array('class' => 'text-muted'));
$summary = html_writer::tag('strong', get_string('kbaelementmaxgrade', 'local_kbaai') . ': ') . format_float($maxscore, 2) .
    ' &nbsp; | &nbsp; ' . html_writer::tag('strong', get_string('rubriccurrenttotal', 'local_kbaai') . ': ') .
    html_writer::span('', '', array('id' => 'kbaai-rubric-total')) . ' ' . html_writer::span('', '', array('id' => 'kbaai-rubric-state'));
echo html_writer::div($summary, 'alert alert-light border');
if ($rubric['source'] === 'legacy') {
    echo $OUTPUT->notification(get_string('rubriclegacynotice', 'local_kbaai'), 'info');
}
echo html_writer::start_tag('form', array('method' => 'post', 'action' => $PAGE->url, 'id' => 'kbaai-rubric-form'));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));
echo html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'rubricpayload', 'id' => 'kbaai-rubric-payload', 'value' => ''));
echo html_writer::div('', '', array('id' => 'kbaai-rubric-criteria'));
echo html_writer::tag('button', '+ ' . get_string('addcriterion', 'local_kbaai'), array('type' => 'button', 'id' => 'kbaai-add-criterion', 'class' => 'btn btn-outline-primary mr-2'));
echo html_writer::tag('button', get_string('saverubric', 'local_kbaai'), array('type' => 'submit', 'class' => 'btn btn-primary'));
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
