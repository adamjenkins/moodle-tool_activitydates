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
 * Course-level bulk activity dates scheduling page.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use tool_activitydates\activitydates;
use tool_activitydates\modtypes;
use tool_activitydates\event\dates_updated;
use tool_activitydates\event\dates_viewed;
use tool_activitydates\form\activitydates_form;
use tool_activitydates\local\datefields;
use tool_activitydates\local\fingerprint;
use tool_activitydates\output\preview_rows;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
if (
    !has_capability('tool/activitydates:manage', $context)
    && has_capability('tool/activitydates:managelocks', $context)
) {
    redirect(new moodle_url('/admin/tool/activitydates/locks.php', ['courseid' => $courseid]));
}
require_capability('tool/activitydates:manage', $context);

$url = new moodle_url('/admin/tool/activitydates/view.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'tool_activitydates'));
$PAGE->set_heading($course->fullname);
navigation_node::override_active_url($url);

$modules = modtypes::eligible_course_modtypes($courseid);

if (empty($modules)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_activitydates'));
    echo \tool_activitydates\local\tabs::render($courseid, 'dates');
    echo $OUTPUT->notification(get_string('noeligiblemodules', 'tool_activitydates'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$config = $DB->get_record('tool_activitydates', ['courseid' => $courseid]) ?: null;

// Resolve the module type to display: an explicit valid request wins, then the
// course's saved type if still eligible, otherwise the first eligible type.
$requested = optional_param('modtype', '', PARAM_ALPHANUMEXT);
if ($requested !== '' && array_key_exists($requested, $modules)) {
    $modtype = $requested;
} else if ($config && array_key_exists($config->modtype, $modules)) {
    $modtype = $config->modtype;
} else {
    $modtype = array_key_first($modules);
}

// A settings-shaped object so the table renders even before anything is saved.
// Timestamps are floored to the minute, the precision the date selectors
// submit, so a Save straight after page load matches the table's fingerprint.
if ($config) {
    $settings = activitydates_form::form_defaults($config);
    $settings->finishenabled = (int) !empty($settings->schedulefinish);
    $settings->schedulefinish = $settings->finishenabled ? $settings->schedulefinish : (int) $config->schedulefinish;
} else {
    $settings = activitydates_form::form_defaults((object) [
        'id' => 0,
        'courseid' => $courseid,
        'schedulestart' => time(),
        'finishenabled' => 1,
        'schedulefinish' => time() + 14 * DAYSECS,
    ]);
    $settings->finishenabled = 1;
}
$settings->modtype = $modtype;

$manager = new activitydates();
$tz = core_date::get_user_timezone_object();
$hasdue = activitydates::has_duedate($modtype);
$validcmids = array_keys(activitydates::get_modules($settings));

$mform = new activitydates_form($url->out(false), [
    'courseid' => $courseid,
    'modules' => $modules,
    'modtype' => $modtype,
    'settings' => $settings,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// Null renders the engine's proposals; an array re-renders the teacher's own values.
$rowinputs = null;
$rowerrors = [];

if ($fromform = $mform->get_data()) {
    // Preview (and a modtype change) builds the table from the submitted settings
    // and selection, and persists nothing.
    $settings = activitydates::settings_from_form($fromform, $courseid, (int) ($config->id ?? 0));
    $selected = activitydates::selected_from_form($fromform, $validcmids);

    if (isset($fromform->submitbutton) || isset($fromform->submitbutton2)) {
        $posted = optional_param('tablefingerprint', '', PARAM_ALPHANUM);
        if ($posted !== fingerprint::dates($settings, $selected, $hasdue)) {
            // The table was built from other settings: re-render fresh proposals.
            \core\notification::error(get_string('errortablestale', 'tool_activitydates'));
        } else {
            $tabledata = $manager->get_table_data($settings, $selected);
            // Only selected, scheduled rows of this table may be written.
            $allowed = [];
            foreach ($tabledata as $row) {
                if (!$row['isheader'] && $row['selected'] === 'checked' && $row['scheduled']) {
                    $allowed[(int) $row['id']] = true;
                }
            }
            $inputs = [
                'timeopen' => optional_param_array('timeopen_rows', [], PARAM_RAW_TRIMMED),
                'duedate' => optional_param_array('duedate_rows', [], PARAM_RAW_TRIMMED),
                'timeclose' => optional_param_array('timeclose_rows', [], PARAM_RAW_TRIMMED),
            ];
            [$values, $rowerrors] = datefields::validate_dates($inputs, $allowed, $hasdue, $tz);
            if ($rowerrors) {
                \core\notification::error(get_string('errorrows', 'tool_activitydates', count($rowerrors)));
                $rowinputs = $inputs;
            } else {
                [$selections, $settings] = $manager->update($fromform, $courseid);
                $count = $manager->apply_dates($tabledata, $settings, $values);
                \core\notification::success(get_string(
                    'datesapplied',
                    'tool_activitydates',
                    (object) ['count' => $count, 'modname' => $modules[$modtype] ?? $modtype]
                ));
                dates_updated::create(['context' => $context])->trigger();

                if (isset($fromform->submitbutton2)) {
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
                }
                $selected = array_map(fn($record) => (int) $record->coursemoduleid, $selections);
            }
        }
    }
} else if ($mform->is_submitted() && ($submitted = $mform->get_submitted_data())) {
    // Settings that failed validation: keep the teacher's ticks, and show the
    // saved settings' table until the settings are corrected.
    $selected = activitydates::selected_from_form($submitted, $validcmids);
} else {
    $selected = activitydates::saved_selection((int) $settings->id, $validcmids);
}

$tabledata = $manager->get_table_data($settings, $selected);

dates_viewed::create(['context' => $context])->trigger();

$mform->set_data($settings);
$mform->set_selection($selected);

$showquestioncount = $modtype === 'quiz';
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_activitydates'));
echo \tool_activitydates\local\tabs::render($courseid, 'dates');
$mform->display();
echo $OUTPUT->render_from_template('tool_activitydates/modtable', [
    'formid' => activitydates_form::FORM_ID,
    'fingerprint' => fingerprint::dates($settings, $selected, $hasdue),
    'hasdue' => $hasdue,
    'tabledata' => preview_rows::dates($tabledata, $rowinputs, $rowerrors, $tz),
    'modname' => $modtype,
    'showquestioncount' => $showquestioncount,
    // The select, name, description, current, open and close columns, plus questions and due when shown.
    'colcount' => 6 + (int) $showquestioncount + (int) $hasdue,
    'editcolspan' => $hasdue ? 3 : 2,
]);
$PAGE->requires->js_call_amd('tool_activitydates/modform', 'init');
echo $OUTPUT->footer();
