<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class implementation_assessment_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        foreach (['itemid', 'auditid', 'companyid'] as $field) {
            $mform->addElement('hidden', $field, (int)$this->_customdata[$field]); $mform->setType($field, PARAM_INT);
        }
        $scores = [-1 => get_string('notassessed', 'local_kbacorporate')];
        $scalemax = max(1, (int)($this->_customdata['scalemax'] ?? 5));
        for ($i = 0; $i <= $scalemax; $i++) { $scores[$i] = (string)$i; }
        $mform->addElement('select', 'score', get_string('bmsmaturitylevel', 'local_kbacorporate'), $scores);
        $mform->addHelpButton('score', 'bmsmaturitylevel', 'local_kbacorporate');
        $mform->addElement('header', 'evidenceheader', get_string('bmsevidenceblock', 'local_kbacorporate'));
        $mform->addElement('url', 'evidenceurl', get_string('bmsevidenceurl', 'local_kbacorporate'), ['size' => 70]);
        $mform->setType('evidenceurl', PARAM_URL);
        $mform->addHelpButton('evidenceurl', 'bmsevidenceurl', 'local_kbacorporate');
        $mform->addElement('filemanager', 'evidencefiles', get_string('bmsevidencefiles', 'local_kbacorporate'), null, [
            'subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10,
        ]);
        $mform->addElement('textarea', 'comment', get_string('auditcomment', 'local_kbacorporate'),
            ['rows' => 5, 'cols' => 70]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addElement('select', 'evidencestatus', get_string('bmsevidencestatus', 'local_kbacorporate'), [
            'missing' => get_string('bmsevidencemissing', 'local_kbacorporate'),
            'submitted' => get_string('bmsevidencesubmitted', 'local_kbacorporate'),
            'verified' => get_string('bmsevidenceverified', 'local_kbacorporate'),
            'rejected' => get_string('bmsevidencerejected', 'local_kbacorporate'),
            'needsdata' => get_string('bmsneedsdata', 'local_kbacorporate'),
            'notapplicable' => get_string('bmsnotapplicable', 'local_kbacorporate'),
        ]);
        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
