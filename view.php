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
 * Course-level bulk activity dates and grade lock scheduling page.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use tool_activitydates\activitydates;
use tool_activitydates\event\dates_viewed;
use tool_activitydates\event\locks_viewed;
use tool_activitydates\form\activitydates_form;
use tool_activitydates\local\datefields;
use tool_activitydates\local\fingerprint;
use tool_activitydates\local\pagetypes;
use tool_activitydates\local\schedule;
use tool_activitydates\output\preview_rows;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
if (!has_any_capability(['tool/activitydates:manage', 'tool/activitydates:managelocks'], $context)) {
    // The standard "no permission" error.
    require_capability('tool/activitydates:manage', $context);
}
$canmanage = has_capability('tool/activitydates:manage', $context);
$canlocks = has_capability('tool/activitydates:managelocks', $context);

$url = new moodle_url('/admin/tool/activitydates/view.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'tool_activitydates'));
$PAGE->set_heading($course->fullname);
navigation_node::override_active_url($url);

// The types with dates (for :manage) and the graded types (for :managelocks).
$types = pagetypes::for_course($courseid, $canmanage, $canlocks);

if (empty($types)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_activitydates'));
    echo $OUTPUT->notification(
        get_string($canmanage ? 'noeligiblemodules' : 'nogradableactivities', 'tool_activitydates'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

// Resolve the module type to display: an explicit valid request wins, then the
// course's saved type if still offered, otherwise the first offered type.
$savedtype = (string) $DB->get_field('tool_activitydates', 'modtype', ['courseid' => $courseid]);
$requested = optional_param('modtype', '', PARAM_ALPHANUMEXT);
if ($requested !== '' && array_key_exists($requested, $types)) {
    $modtype = $requested;
} else if ($savedtype !== '' && array_key_exists($savedtype, $types)) {
    $modtype = $savedtype;
} else {
    $modtype = array_key_first($types);
}
$hasdates = $types[$modtype]['hasdates'];
$hasgrades = $types[$modtype]['hasgrades'];
// Open, due and close are edited only with :manage, and only for a type with dates.
$editdates = $canmanage && $hasdates;
$hasdue = $hasdates && activitydates::has_duedate($modtype);
// The Locked and Show note columns.
$showlocks = $canlocks && $hasgrades;

// The saved dates and lock configuration merged with the site defaults, floored
// to the minute so a Save straight after page load matches the table's fingerprint.
$loaded = activitydates::load_settings($courseid, $modtype);
$settings = $loaded;

$manager = new activitydates();
$tz = core_date::get_user_timezone_object();
$validcmids = array_map('intval', array_keys(activitydates::get_modules($settings)));

$mform = new activitydates_form($url->out(false), [
    'courseid' => $courseid,
    'modules' => array_map(fn(array $type): string => $type['label'], $types),
    'modtype' => $modtype,
    'settings' => $settings,
    'canmanage' => $canmanage,
    'canlocks' => $canlocks,
    'hasdates' => $hasdates,
    'hasdue' => $hasdue,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// Whether the table edits lock dates for these settings.
$haslocks = fn(stdClass $settings): bool => $showlocks && $settings->lockmode !== schedule::MODE_NONE;

// Null renders the engine's proposals; an array re-renders the teacher's own values.
$rowinputs = null;
$rowerrors = [];
// Null renders the saved note ticks; an array re-renders the posted ones.
$notecmids = null;

if ($fromform = $mform->get_data()) {
    // Preview (and a modtype change) builds the table from the submitted settings
    // and selection, and persists nothing. The controls this user was not shown
    // keep their loaded values, so a Save changes only what the page offered.
    $submitted = (object) ((array) $fromform + (array) $loaded);
    $settings = activitydates::settings_from_form($submitted, $courseid, (int) $loaded->id);
    $selected = activitydates::selected_from_form($submitted, $validcmids);
    if ($canlocks) {
        $notecmids = array_values(array_intersect(
            $validcmids,
            array_map('intval', optional_param_array('shownote_cmids', [], PARAM_INT))
        ));
    }

    if (isset($fromform->submitbutton) || isset($fromform->submitbutton2)) {
        $posted = optional_param('tablefingerprint', '', PARAM_ALPHANUM);
        if ($posted !== fingerprint::dates($settings, $selected, $hasdue, $haslocks($settings))) {
            // The table was built from other settings: re-render fresh proposals.
            \core\notification::error(get_string('errortablestale', 'tool_activitydates'));
        } else {
            $tabledata = $manager->get_table_data($settings, $selected, $hasdates);
            // Only selected, scheduled rows of this table may be written.
            $allowed = [];
            foreach ($tabledata as $row) {
                if (!$row['isheader'] && $row['selected'] === 'checked' && $row['scheduled']) {
                    $allowed[(int) $row['id']] = true;
                }
            }
            // Each input is read only when this user may edit it.
            $readrows = fn(bool $read, string $name): array =>
                $read ? optional_param_array($name, [], PARAM_RAW_TRIMMED) : [];
            $inputs = [
                'timeopen' => $readrows($editdates, 'timeopen_rows'),
                'duedate' => $readrows($editdates && $hasdue, 'duedate_rows'),
                'timeclose' => $readrows($editdates, 'timeclose_rows'),
                'timelock' => $readrows($haslocks($settings), 'timelock_rows'),
            ];
            [$values, $rowerrors] = datefields::validate_dates(
                $inputs,
                $allowed,
                $editdates && $hasdue,
                $haslocks($settings),
                $tz
            );
            if ($rowerrors) {
                \core\notification::error(get_string('errorrows', 'tool_activitydates', count($rowerrors)));
                $rowinputs = $inputs;
            } else {
                $result = $manager->save(
                    $submitted,
                    $courseid,
                    $tabledata,
                    $values,
                    $notecmids ?? [],
                    $canmanage,
                    $canlocks,
                    $hasdates
                );
                $notified = false;
                if ($editdates) {
                    \core\notification::success(get_string(
                        'datesapplied',
                        'tool_activitydates',
                        (object) ['count' => $result['dates'], 'modname' => $types[$modtype]['label']]
                    ));
                    $notified = true;
                }
                if ($haslocks($settings) || $result['locks'] > 0) {
                    \core\notification::success(get_string('locksapplied', 'tool_activitydates', $result['locks']));
                    $notified = true;
                }
                if (!$notified) {
                    \core\notification::success(get_string('changessaved'));
                }

                if (isset($fromform->submitbutton2)) {
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
                }
                // Re-render with the saved note ticks and fresh current dates.
                $notecmids = null;
            }
        }
    }
} else if ($mform->is_submitted() && ($submitted = $mform->get_submitted_data())) {
    // Settings that failed validation: keep the teacher's ticks, and show the
    // loaded settings' table until the settings are corrected.
    $selected = activitydates::selected_from_form($submitted, $validcmids);
    if ($canlocks) {
        $notecmids = array_values(array_intersect(
            $validcmids,
            array_map('intval', optional_param_array('shownote_cmids', [], PARAM_INT))
        ));
    }
} else if ($canmanage) {
    $selected = activitydates::saved_selection((int) $settings->id, $validcmids);
} else {
    // Without :manage the ticks come from the lock selection.
    $lockcmids = [];
    if (!empty($settings->lockid)) {
        $lockcmids = array_map('intval', $DB->get_fieldset_select(
            'tool_activitydates_lockitem',
            'cmid',
            'lockid = ?',
            [$settings->lockid]
        ));
    }
    $selected = array_values(array_intersect($validcmids, $lockcmids));
}

$tabledata = $manager->get_table_data($settings, $selected, $hasdates);

dates_viewed::create(['context' => $context])->trigger();
if ($canlocks) {
    locks_viewed::create(['context' => $context])->trigger();
}

$mform->set_data($settings);
$mform->set_selection($selected);

$showquestioncount = $modtype === 'quiz';
// Open and close (and due) for a type with dates; Locked for a graded type the user may lock.
$editcolspan = ($hasdates ? 2 + (int) $hasdue : 0) + (int) $showlocks;
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_activitydates'));
$mform->display();
echo $OUTPUT->render_from_template('tool_activitydates/modtable', [
    'formid' => activitydates_form::FORM_ID,
    'fingerprint' => fingerprint::dates($settings, $selected, $hasdue, $haslocks($settings)),
    'hasdates' => $hasdates,
    'hasdue' => $hasdue,
    'showlocks' => $showlocks,
    'tabledata' => preview_rows::dates(
        $tabledata,
        $rowinputs,
        $rowerrors,
        $tz,
        $editdates,
        $haslocks($settings),
        $notecmids
    ),
    'modname' => $modtype,
    'showquestioncount' => $showquestioncount,
    // The select, name, description and current columns, the date columns, plus
    // questions and show note when shown.
    'colcount' => 4 + (int) $showquestioncount + $editcolspan + (int) $showlocks,
    'editcolspan' => $editcolspan,
]);
$PAGE->requires->js_call_amd('tool_activitydates/modform', 'init');
$PAGE->requires->js_call_amd('tool_activitydates/previewtable', 'init', [
    activitydates_form::FORM_ID,
    ['modtype', 'schedulestart[', 'schedulefinish[', 'sessionlength', 'activitiespersession', 'closemode', 'closedays',
        'closedate[', 'duemode', 'duedays', 'duedate[', 'lockmode', 'lockdays', 'lockdate[', 'activitygroup['],
]);
echo $OUTPUT->footer();
