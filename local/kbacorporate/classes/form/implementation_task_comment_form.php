<?php
namespace local_kbacorporate\form;

defined('MOODLE_INTERNAL') || die();

/** Rich-text message form for an implementation task discussion. */
final class implementation_task_comment_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('editor', 'message_editor',
            get_string('taskdiscussionnewmessage', 'local_kbacorporate'), null, [
                'maxfiles' => 0,
                'trusttext' => false,
                'context' => \context_system::instance(),
            ]);
        $mform->setType('message_editor', PARAM_RAW);
        $mform->addRule('message_editor', get_string('required'), 'required', null, 'client');
        $this->add_action_buttons(false, get_string('taskdiscussionsend', 'local_kbacorporate'));
    }
}
