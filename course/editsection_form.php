<?php

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/formslib.php');

class editsection_form extends moodleform {

    function definition() {
        global $CFG, $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', '', get_string('general'));

        $mform->addElement('checkbox', 'usedefaultname', get_string('sectionusedefaultname'));
        $mform->setDefault('usedefaultname', true);

        $mform->addElement('text', 'name', get_string('sectionname'), array('size'=>'30'));
        $mform->setType('name', PARAM_TEXT);
        $mform->disabledIf('name','usedefaultname','checked');

        /// Prepare course and the editor

        $mform->addElement('editor', 'summary_editor', get_string('summary'), null, $this->_customdata['editoroptions']);
        $mform->addHelpButton('summary_editor', 'summary');
        $mform->setType('summary_editor', PARAM_RAW);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        if (!empty($CFG->enableavailability)) {
            $section = new section_info($this->_customdata['section']);
            $ci      = new condition_info_controller($section->get_conditions());

            // Conditional availability
            $mform->addElement('header', '', get_string('availabilityconditions', 'condition'));
            $mform->addElement('date_selector', 'availablefrom', get_string('availablefrom', 'condition'), array('optional'=>true));
            $mform->addHelpButton('availablefrom', 'availablefrom', 'condition');
            $mform->addElement('date_selector', 'availableuntil', get_string('availableuntil', 'condition'), array('optional'=>true));

            // Conditions based on grades
            $gradeoptions = array();
            if ($items = grade_item::fetch_all(array('courseid'=>$COURSE->id))) {
                $modinfo     = get_fast_modinfo($COURSE);
                $sectionmods = $modinfo->get_sections();

                if (array_key_exists($section->section, $sectionmods)) {
                    $sectionmods = $sectionmods[$section->section];
                } else {
                    $sectionmods = array();
                }
                // Do not include grades from modules from within this section
                foreach($items as $id=>$item) {
                    if ($item->itemtype == 'mod') {
                        $instances = $modinfo->get_instances_of($item->itemmodule);

                        if (array_key_exists($item->iteminstance, $instances) and
                            in_array($instances[$item->iteminstance]->id, $sectionmods)) {
                            continue;
                        }
                    }
                    $gradeoptions[$id] = $item->get_name();
                }
            }
            asort($gradeoptions);
            $gradeoptions = array(0=>get_string('none','condition'))+$gradeoptions;

            $grouparray = array();
            $grouparray[] =& $mform->createElement('select','conditiongradeitemid','',$gradeoptions);
            $grouparray[] =& $mform->createElement('static', '', '',' '.get_string('grade_atleast','condition').' ');
            $grouparray[] =& $mform->createElement('text', 'conditiongrademin','',array('size'=>3));
            $grouparray[] =& $mform->createElement('static', '', '','% '.get_string('grade_upto','condition').' ');
            $grouparray[] =& $mform->createElement('text', 'conditiongrademax','',array('size'=>3));
            $grouparray[] =& $mform->createElement('static', '', '','%');
            $mform->setType('conditiongrademin',PARAM_FLOAT);
            $mform->setType('conditiongrademax',PARAM_FLOAT);
            $group = $mform->createElement('group','conditiongradegroup',
                get_string('gradecondition', 'condition'),$grouparray);

            // Get version with condition info and store it so we don't ask twice
            $count = count($ci->get_conditions('condition_grade'))+1;

            $this->repeat_elements(array($group), $count, array(), 'conditiongraderepeats', 'conditiongradeadds', 2,
                                   get_string('addgrades', 'condition'), true);
            $mform->addHelpButton('conditiongradegroup[0]', 'gradecondition', 'condition');

            // Conditions based on completion
            $completion = new completion_info($COURSE);
            if ($completion->is_enabled()) {
                $completionoptions = array();
                $modinfo = get_fast_modinfo($COURSE);
                foreach($modinfo->get_cms() as $id => $cm) {
                    // Add each course-module if it:
                    // (1) has completion turned on
                    // (2) Is not IN this current section
                    if ($cm->completion and $cm->sectionnum != $section->section) {
                        $completionoptions[$id]=$cm->name;
                    }
                }
                asort($completionoptions);
                $completionoptions = array(0=>get_string('none','condition'))+$completionoptions;

                $completionvalues=array(
                    COMPLETION_COMPLETE=>get_string('completion_complete','condition'),
                    COMPLETION_INCOMPLETE=>get_string('completion_incomplete','condition'),
                    COMPLETION_COMPLETE_PASS=>get_string('completion_pass','condition'),
                    COMPLETION_COMPLETE_FAIL=>get_string('completion_fail','condition'));

                $grouparray = array();
                $grouparray[] =& $mform->createElement('select','conditionsourcecmid','',$completionoptions);
                $grouparray[] =& $mform->createElement('select','conditionrequiredcompletion','',$completionvalues);
                $group = $mform->createElement('group','conditioncompletiongroup',
                    get_string('completioncondition', 'condition'),$grouparray);

                // @todo Probably need to retool this for sections
                $count = count($ci->get_conditions('condition_completion'))+1;
                $this->repeat_elements(array($group),$count,array(),
                    'conditioncompletionrepeats','conditioncompletionadds',2,
                    get_string('addcompletions','condition'),true);
                $mform->addHelpButton('conditioncompletiongroup[0]', 'completioncondition', 'condition');
            }

            // Do we display availability info to students?
            $mform->addElement('select', 'showavailability', get_string('showavailability', 'condition'),
                    array(CONDITION_STUDENTVIEW_SHOW=>get_string('showavailability_show', 'condition'),
                    CONDITION_STUDENTVIEW_HIDE=>get_string('showavailability_hide', 'condition')));
            $mform->setDefault('showavailability', CONDITION_STUDENTVIEW_SHOW);
        }
//--------------------------------------------------------------------------------
        $this->add_action_buttons();

    }

    function definition_after_data() {
        global $CFG, $COURSE;

        // Availability conditions
        if (!empty($CFG->enableavailability)) {
            $mform = $this->_form;
            $section = new section_info($this->_customdata['section']);
            $ci = new condition_info_controller($section->get_conditions());

            $num=0;
            foreach($ci->get_conditions('condition_grade') as $condition) {
                $groupelements=$mform->getElement('conditiongradegroup['.$num.']')->getElements();
                $groupelements[0]->setValue($condition->get_gradeitemid());
                // These numbers are always in the format 0.00000 - the rtrims remove any final zeros and,
                // if it is a whole number, the decimal place.
                $groupelements[2]->setValue(is_null($condition->get_min())?'':rtrim(rtrim($condition->get_min(),'0'),'.'));
                $groupelements[4]->setValue(is_null($condition->get_max())?'':rtrim(rtrim($condition->get_max(),'0'),'.'));
                $num++;
            }

            $completion = new completion_info($COURSE);
            if ($completion->is_enabled()) {
                $num=0;
                foreach($ci->get_conditions('condition_completion') as $condition) {
                    $groupelements=$mform->getElement('conditioncompletiongroup['.$num.']')->getElements();
                    $groupelements[0]->setValue($condition->get_cmid());
                    $groupelements[1]->setValue($condition->get_requiredcompletion());
                    $num++;
                }
            }
        }
    }
}
