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

use tool_activitydates\activitydates;
use tool_activitydates\local\schedule;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Lets a teacher choose an activity type, a schedule window and per-session
 * settings, and pick which activities of that type to schedule.
 *
 * The editable date table is rendered outside this form; its inputs carry
 * form="self::FORM_ID" so they post with it.
 */
class activitydates_form extends \moodleform {
    use \tool_activitydates\local\action_buttons;

    /** @var string the form's HTML id, referenced by the table's inputs. */
    const FORM_ID = 'tool_activitydates_dates_form';

    /** @var \cm_info[] the course modules of the current module type, keyed by cmid. */
    protected $modules = [];

    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->updateAttributes(['id' => self::FORM_ID]);

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
            get_string(
                'activitydatesforcourse',
                'tool_activitydates',
                format_string($course->shortname, true, ['context' => \context_course::instance($courseid)])
            )
        );
        $mform->setExpanded('activitydatesheader');

        // Module type selector plus a Preview button, which re-displays the form
        // and the table for the chosen type and settings without saving anything.
        $group = [];
        $group[] = $mform->createElement('select', 'modtype', get_string('activitytype', 'tool_activitydates'), $modules);
        $group[] = $mform->createElement('submit', 'preview', get_string('preview', 'tool_activitydates'));
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

        $defaults = self::form_defaults($settings);

        $mform->addElement(
            'date_time_selector',
            'schedulestart',
            get_string('schedulestart', 'tool_activitydates')
        );
        $mform->setDefault('schedulestart', $defaults->schedulestart);
        $mform->addHelpButton('schedulestart', 'schedulestart', 'tool_activitydates');

        // Optional: disabled, it submits 0 and every selected activity is scheduled.
        $mform->addElement(
            'date_time_selector',
            'schedulefinish',
            get_string('schedulefinish', 'tool_activitydates'),
            ['optional' => true]
        );
        $mform->setDefault('schedulefinish', $defaults->schedulefinish);
        $mform->addHelpButton('schedulefinish', 'schedulefinish', 'tool_activitydates');

        $mform->addElement('text', 'sessionlength', get_string('sessionlength', 'tool_activitydates'), ['size' => 3]);
        $mform->setType('sessionlength', PARAM_INT);
        $mform->addRule('sessionlength', null, 'required', null, 'client');
        $mform->setDefault('sessionlength', $defaults->sessionlength);
        $mform->addHelpButton('sessionlength', 'sessionlength', 'tool_activitydates');

        $mform->addElement(
            'text',
            'activitiespersession',
            get_string('activitiespersession', 'tool_activitydates'),
            ['size' => 3]
        );
        $mform->setType('activitiespersession', PARAM_INT);
        $mform->addRule('activitiespersession', null, 'required', null, 'client');
        $mform->setDefault('activitiespersession', $defaults->activitiespersession);
        $mform->addHelpButton('activitiespersession', 'activitiespersession', 'tool_activitydates');

        $this->add_mode_elements('close', $defaults);
        // Due dates only for types whose table has a duedate column (quiz from 5.3).
        if (activitydates::has_duedate($modtype)) {
            $this->add_mode_elements('due', $defaults);
        }

        $mform->addElement('advcheckbox', 'hideunselected', get_string('hideunselected', 'tool_activitydates'));
        $mform->addHelpButton('hideunselected', 'hideunselected', 'tool_activitydates');
        $mform->setDefault('hideunselected', $defaults->hideunselected);
        $mform->setAdvanced('hideunselected');

        $mform->addElement('advcheckbox', 'resetunselected', get_string('resetunselected', 'tool_activitydates'));
        $mform->addHelpButton('resetunselected', 'resetunselected', 'tool_activitydates');
        $mform->setDefault('resetunselected', $defaults->resetunselected);
        $mform->setAdvanced('resetunselected');

        $this->add_action_buttons();
    }

    /**
     * Add the mode select and its days / date controls for close or due dates.
     *
     * The days field shows only in days mode and the date only in date mode.
     *
     * @param string $prefix 'close' or 'due'.
     * @param \stdClass $defaults the form defaults (see form_defaults()).
     */
    protected function add_mode_elements(string $prefix, \stdClass $defaults): void {
        $mform = $this->_form;
        $options = [];
        foreach (schedule::MODES as $mode) {
            $options[$mode] = get_string('mode_' . $mode, 'tool_activitydates');
        }

        $mform->addElement('select', $prefix . 'mode', get_string($prefix . 'mode', 'tool_activitydates'), $options);
        $mform->setDefault($prefix . 'mode', $defaults->{$prefix . 'mode'});
        $mform->addHelpButton($prefix . 'mode', $prefix . 'mode', 'tool_activitydates');

        $mform->addElement('text', $prefix . 'days', get_string($prefix . 'days', 'tool_activitydates'), ['size' => 3]);
        $mform->setType($prefix . 'days', PARAM_INT);
        $mform->setDefault($prefix . 'days', $defaults->{$prefix . 'days'});
        $mform->hideIf($prefix . 'days', $prefix . 'mode', 'neq', schedule::MODE_DAYS);

        $mform->addElement('date_time_selector', $prefix . 'date', get_string($prefix . 'date', 'tool_activitydates'));
        $mform->setDefault($prefix . 'date', $defaults->{$prefix . 'date'});
        $mform->hideIf($prefix . 'date', $prefix . 'mode', 'neq', schedule::MODE_DATE);
    }

    /**
     * The form values for a settings object, with site-config fallbacks.
     *
     * A disabled finish date is 0 (so the optional selector renders disabled),
     * an unset close/due date defaults to the finish date (or two weeks after
     * the start), and every timestamp is floored to the minute, which is the
     * precision the date selectors submit.
     *
     * @param \stdClass $settings settings object (tool_activitydates row shape); fields may be missing.
     * @return \stdClass the form values.
     */
    public static function form_defaults(\stdClass $settings): \stdClass {
        $minute = fn($timestamp): int => (int) $timestamp - (int) $timestamp % 60;
        $config = fn(string $name, $default) => get_config('tool_activitydates', $name) !== false
            ? get_config('tool_activitydates', $name) : $default;

        $defaults = clone $settings;
        $defaults->schedulestart = $minute($settings->schedulestart ?? time());
        $finishenabled = !empty($settings->finishenabled ?? 1) && !empty($settings->schedulefinish);
        $defaults->schedulefinish = $finishenabled ? $minute($settings->schedulefinish) : 0;
        $defaults->sessionlength = (int) ($settings->sessionlength ?? $config('sessionlength', 7));
        $defaults->activitiespersession = (int) ($settings->activitiespersession ?? $config('activitiespersession', 2));
        $fallbackdate = $defaults->schedulefinish ?: $minute($defaults->schedulestart + 14 * DAYSECS);
        foreach (['close' => schedule::MODE_SESSION, 'due' => schedule::MODE_NONE] as $prefix => $defaultmode) {
            $defaults->{$prefix . 'mode'} = (string) ($settings->{$prefix . 'mode'} ?? $config($prefix . 'mode', $defaultmode));
            $defaults->{$prefix . 'days'} = (int) ($settings->{$prefix . 'days'} ?? $config($prefix . 'days', 7));
            $date = (int) ($settings->{$prefix . 'date'} ?? 0);
            $defaults->{$prefix . 'date'} = $date ? $minute($date) : $fallbackdate;
        }
        $defaults->hideunselected = (int) ($settings->hideunselected ?? $config('hideunselected', 0));
        $defaults->resetunselected = (int) ($settings->resetunselected ?? $config('resetunselected', 0));
        return $defaults;
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
     * Load the form values from a settings object.
     *
     * The values go through form_defaults(), so a disabled finish date shows
     * as disabled. Submitted values still win over these, as for any moodleform.
     *
     * @param \stdClass|array $data the settings object.
     */
    public function set_data($data) {
        parent::set_data(self::form_defaults((object) $data));
    }

    /**
     * Tick exactly the given activities' hidden checkboxes.
     *
     * Called after set_data() with the selection the table is rendered from:
     * the saved selection on page load and after a save, the submitted one on
     * Preview, so the checkboxes and the table always agree.
     *
     * @param int[] $cmids the selected cm ids.
     */
    public function set_selection(array $cmids): void {
        $mform = $this->_form;
        if (empty($this->modules) || !$mform->elementExists('activitygroup')) {
            return;
        }
        $selected = array_fill_keys(array_map('intval', $cmids), true);
        foreach ($mform->getElement('activitygroup')->getElements() as $checkbox) {
            $name = $checkbox->getAttributes()['name'] ?? '';
            if (preg_match('/^activity_(\d+)$/', $name, $matches)) {
                $checkbox->setValue(isset($selected[(int) $matches[1]]) ? 1 : 0);
            }
        }
    }

    /**
     * Validate the submitted schedule and session settings.
     *
     * Only the settings themselves are checked here; the per-row date rules
     * are checked on Save by local\datefields.
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
        if ($data['activitiespersession'] < 1 || $data['activitiespersession'] > $modulecount) {
            $a = (object) [
                'activitiespersession' => $data['activitiespersession'],
                'modulecount' => $modulecount,
            ];
            $errors['activitiespersession'] = get_string('activitiespersessionerror', 'tool_activitydates', $a);
        }

        // The finish-date checks apply only when the optional finish date is enabled.
        $finishenabled = !empty($data['schedulefinish']);
        $duration = $finishenabled ? round(($data['schedulefinish'] - $data['schedulestart']) / DAYSECS) : null;
        if ($finishenabled && $duration < 1) {
            $errors['schedulefinish'] = get_string('starttofinishmustbe', 'tool_activitydates');
        }

        if ($data['sessionlength'] < 1) {
            $errors['sessionlength'] = get_string('sessionlengtherror', 'tool_activitydates');
        } else if ($finishenabled && $data['sessionlength'] > $duration) {
            $errors['sessionlength'] = get_string('sessionlengthislonger', 'tool_activitydates');
        }

        foreach (['close', 'due'] as $prefix) {
            if (!isset($data[$prefix . 'mode'])) {
                continue;
            }
            $mode = $data[$prefix . 'mode'];
            if ($mode === schedule::MODE_DAYS && (int) ($data[$prefix . 'days'] ?? 0) < 1) {
                $errors[$prefix . 'days'] = get_string('positiveintrequired', 'tool_activitydates');
            }
            if ($mode === schedule::MODE_DATE && (int) ($data[$prefix . 'date'] ?? 0) <= (int) $data['schedulestart']) {
                $errors[$prefix . 'date'] = get_string('error' . $prefix . 'datebeforestart', 'tool_activitydates');
            }
        }

        return $errors;
    }
}
