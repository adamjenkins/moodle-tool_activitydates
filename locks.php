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
 * Course-level bulk gradebook lock scheduling page (Grade locks tab).
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use tool_activitydates\locks\manager;
use tool_activitydates\locks\modtypes;
use tool_activitydates\locks\form\lock_form;
use tool_activitydates\event\locks_updated;
use tool_activitydates\event\locks_viewed;
use tool_activitydates\local\datefields;
use tool_activitydates\local\fingerprint;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('tool/activitydates:managelocks', $context);

locks_viewed::create(['context' => $context])->trigger();

$url = new moodle_url('/admin/tool/activitydates/locks.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('tablocks', 'tool_activitydates'));
$PAGE->set_heading($course->fullname);
\tool_activitydates\local\tabs::highlight_navigation($courseid);

$modules = modtypes::eligible_course_modtypes($courseid);

if (empty($modules)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('tablocks', 'tool_activitydates'));
    echo \tool_activitydates\local\tabs::render($courseid, 'locks');
    echo $OUTPUT->notification(
        get_string('nogradableactivities', 'tool_activitydates'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

$config = $DB->get_record('tool_activitydates_lock', ['courseid' => $courseid]) ?: null;

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
if ($config) {
    $settings = clone $config;
    $settings->modtype = $modtype;
} else {
    $now = time();
    $settings = (object) [
        'id' => 0,
        'courseid' => $courseid,
        'modtype' => $modtype,
        // Floored to the minute, the precision the date selector submits, so a
        // Save straight after page load matches the table's fingerprint.
        'schedulestart' => $now - $now % MINSECS,
        'sessionlength' => (int) get_config('tool_activitydates', 'locksessionlength'),
        'activitiespersession' => (int) get_config('tool_activitydates', 'lockactivitiespersession'),
        'shownote' => (int) get_config('tool_activitydates', 'lockshownote'),
        'shownotecoursepage' => (int) get_config('tool_activitydates', 'lockshownotecoursepage'),
        'resetunselected' => 0,
    ];
}

$manager = new manager();

$mform = new lock_form($url->out(false), [
    'courseid' => $courseid,
    'modules' => $modules,
    'modtype' => $modtype,
    'settings' => $settings,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

$tz = core_date::get_user_timezone_object();
// The cmids of this type in the course: only these may be selected.
$validcmids = [];
foreach (get_fast_modinfo($courseid)->get_instances_of($modtype) as $cm) {
    if (!$cm->deletioninprogress) {
        $validcmids[] = (int) $cm->id;
    }
}

// Null uses the saved selection / note ticks; arrays use the posted ones.
$selected = null;
$notecmids = null;
// Null renders the proposed lock dates; an array re-renders the teacher's own values.
$rowinputs = null;
$rowerrors = [];

if ($fromform = $mform->get_data()) {
    // Preview (and a modtype change) builds the table from the submitted settings
    // and selection, and persists nothing. The table's inputs sit outside the
    // moodleform (see lock_form's docblock), so they are read here.
    $settings = $manager->settings_from_form($fromform, $courseid);
    $settings->id = (int) ($config->id ?? 0);
    $selected = array_values(array_unique(array_intersect(optional_param_array('cmids', [], PARAM_INT), $validcmids)));
    $notecmids = array_values(array_unique(array_intersect(optional_param_array('shownote_cmids', [], PARAM_INT), $validcmids)));

    if (isset($fromform->submitbutton) || isset($fromform->submitbutton2)) {
        $posted = optional_param('tablefingerprint', '', PARAM_ALPHANUM);
        if ($posted !== fingerprint::locks($settings, $selected)) {
            // The table was built from other settings: re-render fresh proposals.
            \core\notification::error(get_string('errortablestale', 'tool_activitydates'));
        } else {
            // Only the selected rows of this table may be written.
            $allowed = array_fill_keys($selected, true);
            $inputs = ['locktime' => optional_param_array('locktime_rows', [], PARAM_RAW_TRIMMED)];
            [$values, $rowerrors] = datefields::validate_locks($inputs, $allowed, $tz);
            if ($rowerrors) {
                \core\notification::error(get_string('errorrows', 'tool_activitydates', count($rowerrors)));
                $rowinputs = $inputs;
            } else {
                // The manager's update() re-validates every cmid against the course and type.
                $fromform->cmids = $selected;
                $fromform->shownote_cmids = $notecmids;
                $settings = $manager->update($fromform, $courseid);
                $lockdates = array_map(fn($value) => $value['locktime'], $values);
                $count = $manager->apply_locks($lockdates, $settings->modtype, $courseid, (bool) $settings->resetunselected);
                locks_updated::create(['context' => $context])->trigger();
                \core\notification::success(get_string('locksapplied', 'tool_activitydates', $count));

                if (isset($fromform->submitbutton2)) {
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
                }
                // Re-render from the saved selection and notes, with fresh proposals.
                $selected = null;
                $notecmids = null;
            }
        }
    }
} else if ($mform->is_submitted()) {
    // Settings that failed validation: keep the teacher's ticks, and show the
    // saved settings' table until the settings are corrected.
    $selected = array_values(array_unique(array_intersect(optional_param_array('cmids', [], PARAM_INT), $validcmids)));
    $notecmids = array_values(array_unique(array_intersect(optional_param_array('shownote_cmids', [], PARAM_INT), $validcmids)));
}

$tabledata = $manager->get_table_data($settings, $selected);
$selected = array_map('intval', array_column(array_filter($tabledata, fn($row) => $row['selected']), 'cmid'));

// Decorate rows for the template: format the pending lock date, expose a
// dedicated flag rather than relying on a truthy/zero timestamp in mustache,
// and fill each selected row's lock date input.
$dateformat = get_string('strftimedatetimeshort', 'langconfig');
foreach ($tabledata as &$row) {
    $cmid = (int) $row['cmid'];
    $row['haslocktime'] = $row['locktime'] > 0;
    if ($row['haslocktime']) {
        $row['lockdateattr'] = userdate($row['locktime'], '%Y-%m-%dT%H:%M', 99, false, false);
        $row['lockmessage'] = get_string('willlockon', 'tool_activitydates', userdate($row['locktime'], $dateformat));
    } else {
        $row['lockdateattr'] = '';
        $row['lockmessage'] = '';
    }
    if ($notecmids !== null) {
        $row['shownote'] = in_array($cmid, $notecmids, true);
    }
    $value = '';
    if ($row['selected']) {
        if ($rowinputs !== null) {
            $posted = $rowinputs['locktime'][$cmid] ?? '';
            $value = is_string($posted) ? $posted : '';
        } else {
            $value = datefields::to_input((int) $row['proposed'], $tz);
        }
    }
    $errorkey = $rowerrors[$cmid]['locktime'] ?? null;
    $row['locktimevalue'] = $value;
    $row['locktimeinvalid'] = $errorkey !== null;
    $row['locktimeerror'] = $errorkey === null ? '' : get_string($errorkey, 'tool_activitydates');
}
unset($row);

$mform->set_data($settings);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tablocks', 'tool_activitydates'));
echo \tool_activitydates\local\tabs::render($courseid, 'locks');
$mform->display();
echo $OUTPUT->render_from_template('tool_activitydates/lockmodtable', [
    'formid' => lock_form::FORM_ID,
    'fingerprint' => fingerprint::locks($settings, $selected),
    'modname' => $modtype,
    'tabledata' => $tabledata,
]);
$PAGE->requires->js_call_amd('tool_activitydates/lockform', 'init');
$PAGE->requires->js_call_amd('tool_activitydates/previewtable', 'init', [
    lock_form::FORM_ID,
    ['modtype', 'schedulestart[', 'sessionlength', 'activitiespersession', 'cmids[]'],
]);
echo $OUTPUT->footer();
