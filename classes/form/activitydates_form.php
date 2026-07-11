<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The course activity dates scheduling form.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_activitydates\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Lets a teacher choose an activity type, a schedule window and per-session
 * settings, and pick which activities of that type to schedule.
 */
class activitydates_form extends \moodleform {
    /** @var \cm_info[] the course modules of the current module type, keyed by cmid. */
    protected $modules = [];

    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;

        $courseid = (int) $this->_customdata['courseid'];
        $modules = $this->_customdata['modules'];
        $modtype = $this->_customdata['modtype'];
        $settings = (object) $this->_customdata['settings'];

        $course = get_course($courseid);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement(
            'header',
            'activitydatesheader',
            get_string('activitydatesforcourse', 'tool_activitydates', $course->shortname)
        );
        $mform->setExpanded('activitydatesheader');

        // Module type selector plus a refresh button to re-display the form for
        // the chosen type.
        $group = [];
        $group[] = $mform->createElement('select', 'modtype', get_string('activitytype', 'tool_activitydates'), $modules);
        $group[] = $mform->createElement('submit', 'refresh', get_string('refresh', 'tool_activitydates'));
        $mform->addGroup($group, 'modtypegroup', get_string('activitytype', 'tool_activitydates'), [' '], false);
        $mform->setDefault('modtype', $modtype);

        // One hidden advcheckbox per course module of the current type. The
        // rendered element ids are id_activitygroup_activity_<cmid>, which the
        // modform AMD module mirrors from the visible table checkboxes.
        $this->modules = $this->load_modules($courseid, $modtype);
        if ($this->modules) {
            $activitycbx = [];
            foreach ($this->modules as $module) {
                $activitycbx[] = $mform->createElement(
                    'advcheckbox',
                    'activity_' . $module->id,
                    null,
                    null,
                    ['hidden' => true]
                );
            }
            // Default appendName=true is required so the checkboxes submit as
            // activitygroup[activity_<cmid>] and render with the element id
            // id_activitygroup_activity_<cmid> that the modform AMD mirrors.
            $mform->addGroup($activitycbx, 'activitygroup');
        }

        $mform->addElement(
            'date_time_selector',
            'schedulestart',
            get_string('schedulestart', 'tool_activitydates')
        );
        $mform->setDefault('schedulestart', $settings->schedulestart ?? time());
        $mform->addHelpButton('schedulestart', 'schedulestart', 'tool_activitydates');

        $mform->addElement(
            'date_time_selector',
            'schedulefinish',
            get_string('schedulefinish', 'tool_activitydates')
        );
        $mform->setDefault('schedulefinish', $settings->schedulefinish ?? (time() + 14 * DAYSECS));

        $mform->addElement('text', 'sessionlength', get_string('sessionlength', 'tool_activitydates'), ['size' => 3]);
        $mform->setType('sessionlength', PARAM_INT);
        $mform->addRule('sessionlength', null, 'required', null, 'client');
        $mform->setDefault('sessionlength', $settings->sessionlength ?? get_config('tool_activitydates', 'sessionlength'));
        $mform->addHelpButton('sessionlength', 'sessionlength', 'tool_activitydates');

        $mform->addElement(
            'text',
            'activitiespersession',
            get_string('activitiespersession', 'tool_activitydates'),
            ['size' => 3]
        );
        $mform->setType('activitiespersession', PARAM_INT);
        $mform->addRule('activitiespersession', null, 'required', null, 'client');
        $mform->setDefault(
            'activitiespersession',
            $settings->activitiespersession ?? get_config('tool_activitydates', 'activitiespersession')
        );
        $mform->addHelpButton('activitiespersession', 'activitiespersession', 'tool_activitydates');

        $mform->addElement('advcheckbox', 'stayavailable', get_string('stayavailable', 'tool_activitydates'));
        $mform->addHelpButton('stayavailable', 'stayavailable', 'tool_activitydates');
        $mform->setDefault('stayavailable', $settings->stayavailable ?? get_config('tool_activitydates', 'stayavailable'));
        $mform->setAdvanced('stayavailable');

        $mform->addElement('advcheckbox', 'hideunselected', get_string('hideunselected', 'tool_activitydates'));
        $mform->addHelpButton('hideunselected', 'hideunselected', 'tool_activitydates');
        $mform->setDefault(
            'hideunselected',
            $settings->hideunselected ?? get_config('tool_activitydates', 'hideunselected')
        );
        $mform->setAdvanced('hideunselected');

        $mform->addElement('advcheckbox', 'resetunselected', get_string('resetunselected', 'tool_activitydates'));
        $mform->addHelpButton('resetunselected', 'resetunselected', 'tool_activitydates');
        $mform->setDefault(
            'resetunselected',
            $settings->resetunselected ?? get_config('tool_activitydates', 'resetunselected')
        );
        $mform->setAdvanced('resetunselected');

        $this->add_action_buttons();
    }

    /**
     * Add "Save and return to course", "Save and display" and "Cancel"
     * buttons, mirroring the standard activity module forms.
     *
     * @param bool $cancel whether to show a cancel button.
     * @param string|null $submitlabel label for the save-and-display button.
     * @param string|null $submit2label label for the save-and-return button.
     */
    public function add_action_buttons($cancel = true, $submitlabel = null, $submit2label = null) {
        if (is_null($submitlabel)) {
            $submitlabel = get_string('savechangesanddisplay');
        }
        if (is_null($submit2label)) {
            $submit2label = get_string('savechangesandreturntocourse');
        }
        $mform = $this->_form;

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton2', $submit2label);
        if ($submitlabel !== false) {
            $buttonarray[] = $mform->createElement('submit', 'submitbutton', $submitlabel);
        }
        if ($cancel) {
            $buttonarray[] = $mform->createElement('cancel');
        }
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->setType('buttonar', PARAM_RAW);
    }

    /**
     * Load the course modules of the given type, keyed by course module id.
     *
     * @param int $courseid the course id.
     * @param string $modtype the module type (e.g. 'quiz').
     * @return array cm_info objects keyed by course module id.
     */
    protected function load_modules(int $courseid, string $modtype): array {
        $modinfo = get_fast_modinfo($courseid);
        $modules = [];
        foreach ($modinfo->cms as $cm) {
            if ($cm->modname !== $modtype || $cm->deletioninprogress) {
                continue;
            }
            $modules[$cm->id] = $cm;
        }
        return $modules;
    }

    /**
     * Re-tick the activity checkboxes from the saved selection.
     *
     * The selection is read fresh from the database using the config record's
     * id, so it stays correct even after a save has changed it within the same
     * request.
     *
     * @param \stdClass|array $data the config record used as defaults.
     */
    public function set_data($data) {
        global $DB;
        parent::set_data($data);

        if (empty($this->modules)) {
            return;
        }
        $activitydatesid = is_object($data) ? (int) ($data->id ?? 0) : 0;
        if (!$activitydatesid) {
            return;
        }
        $mform = $this->_form;
        if (!$mform->elementExists('activitygroup')) {
            return;
        }
        $selected = $DB->get_records_menu(
            'tool_activitydates_cmids',
            ['activitydates' => $activitydatesid],
            '',
            'coursemoduleid, coursemoduleid'
        );
        $group = $mform->getElement('activitygroup');
        foreach ($group->getElements() as $checkbox) {
            $name = $checkbox->getAttributes()['name'] ?? '';
            if (preg_match('/^activity_(\d+)$/', $name, $matches)) {
                if (array_key_exists((int) $matches[1], $selected)) {
                    $checkbox->setValue(true);
                }
            }
        }
    }

    /**
     * Validate the submitted schedule and session settings.
     *
     * @param array $data submitted form data.
     * @param array $files submitted files.
     * @return array field => error message.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $modules = $this->_customdata['modules'];
        if (!array_key_exists($data['modtype'], $modules)) {
            $errors['modtypegroup'] = get_string('activitytype', 'tool_activitydates');
        }

        $modulecount = count($this->modules);
        $duration = round(($data['schedulefinish'] - $data['schedulestart']) / DAYSECS);

        if ($duration < 1) {
            $errors['schedulefinish'] = get_string('starttofinishmustbe', 'tool_activitydates');
        }

        if ($data['activitiespersession'] < 1 || $data['activitiespersession'] > $modulecount) {
            $a = (object) [
                'activitiespersession' => $data['activitiespersession'],
                'modulecount' => $modulecount,
            ];
            $errors['activitiespersession'] = get_string('activitiespersessionerror', 'tool_activitydates', $a);
        }

        if ($data['sessionlength'] < 1) {
            $errors['sessionlength'] = get_string('sessionlengtherror', 'tool_activitydates');
        } else if ($data['sessionlength'] > $duration) {
            $errors['sessionlength'] = get_string('sessionlengthislonger', 'tool_activitydates');
        }

        return $errors;
    }
}
