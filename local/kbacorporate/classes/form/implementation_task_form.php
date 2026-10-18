<?php
namespace local_kbacorporate\form;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class implementation_task_form extends \moodleform {
    protected function definition() {
        $mform = $this->_form;
        foreach (['id', 'companyid', 'auditid', 'itemid', 'routeid'] as $field) {
            $mform->addElement('hidden', $field, (int)($this->_customdata[$field] ?? 0));
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement('text', 'title', get_string('implementationtasktitle', 'local_kbacorporate'),
            ['size' => 70, 'maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT); $mform->addRule('title', null, 'required', null, 'client');
        $owners = [0 => get_string('notassigned', 'local_kbacorporate')];
        foreach ($this->_customdata['owners'] as $owner) { $owners[$owner->id] = fullname($owner) . ' — ' . $owner->email; }
        $mform->addElement('select', 'ownerid', get_string('implementationtaskowner', 'local_kbacorporate'), $owners);
        $mform->addElement('select', 'priority', get_string('routepriority', 'local_kbacorporate'), [
            'critical' => get_string('gapprioritycritical', 'local_kbacorporate'),
            'core' => get_string('gapprioritycore', 'local_kbacorporate'),
            'additional' => get_string('gappriorityadditional', 'local_kbacorporate'),
        ]);
        $mform->addElement('select', 'status', get_string('implementationtaskstatus', 'local_kbacorporate'), [
            'planned' => get_string('implementationtaskstatusplanned', 'local_kbacorporate'),
            'inprogress' => get_string('implementationtaskstatusinprogress', 'local_kbacorporate'),
            'review' => get_string('implementationtaskstatusreview', 'local_kbacorporate'),
            'completed' => get_string('implementationtaskstatuscompleted', 'local_kbacorporate'),
            'cancelled' => get_string('implementationtaskstatuscancelled', 'local_kbacorporate'),
        ]);
        if (!empty($this->_customdata['lockworkflowstatus'])) {
            $mform->setDefault('status', (string)$this->_customdata['currentstatus']);
            $mform->freeze('status');
            $mform->setConstant('status', (string)$this->_customdata['currentstatus']);
            $mform->addElement('static', 'workflowstatusnote', '',
                get_string('implevidencestatuslocked', 'local_kbacorporate'));
        }
        $mform->addElement('date_selector', 'startdate', get_string('implementationtaskstart', 'local_kbacorporate'), ['optional' => true]);
        $mform->addElement('date_selector', 'duedate', get_string('implementationtaskdue', 'local_kbacorporate'), ['optional' => true]);
        $mform->addElement('textarea', 'expectedresult', get_string('implementationtaskexpected', 'local_kbacorporate'), ['rows' => 4, 'cols' => 70]);
        $mform->setType('expectedresult', PARAM_TEXT);
        if (!empty($this->_customdata['versionedevidence'])) {
            $mform->addElement('static', 'versionedevidencenote',
                get_string('implevidencepage', 'local_kbacorporate'),
                get_string('implevidencemanagedseparately', 'local_kbacorporate'));
        } else {
            $mform->addElement('textarea', 'completioncomment',
                get_string('implementationtaskresult', 'local_kbacorporate'), ['rows' => 4, 'cols' => 70]);
            $mform->setType('completioncomment', PARAM_TEXT);
            $mform->addElement('url', 'resulturl',
                get_string('implementationtaskresulturl', 'local_kbacorporate'),
                ['size' => 70, 'maxlength' => 1333], ['usefilepicker' => false]);
            $mform->setType('resulturl', PARAM_URL);
            $mform->addElement('filemanager', 'evidencefiles',
                get_string('implementationtaskevidence', 'local_kbacorporate'), null,
                ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10]);
        }
        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['startdate']) && !empty($data['duedate']) && $data['duedate'] < $data['startdate']) {
            $errors['duedate'] = get_string('implementationtaskdateinvalid', 'local_kbacorporate');
        }
        return $errors;
    }
}
