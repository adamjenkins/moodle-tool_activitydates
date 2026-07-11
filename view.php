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

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
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
if ($config) {
    $settings = clone $config;
    $settings->modtype = $modtype;
} else {
    $settings = (object) [
        'id' => 0,
        'courseid' => $courseid,
        'modtype' => $modtype,
        'schedulestart' => time(),
        'schedulefinish' => time() + 14 * DAYSECS,
        'sessionlength' => (int) get_config('tool_activitydates', 'sessionlength'),
        'activitiespersession' => (int) get_config('tool_activitydates', 'activitiespersession'),
        'stayavailable' => (int) get_config('tool_activitydates', 'stayavailable'),
        'hideunselected' => (int) get_config('tool_activitydates', 'hideunselected'),
        'resetunselected' => 0,
    ];
}

$manager = new activitydates();

$mform = new activitydates_form($url->out(false), [
    'courseid' => $courseid,
    'modules' => $modules,
    'modtype' => $modtype,
    'settings' => $settings,
]);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($fromform = $mform->get_data()) {
    [$selections, $settings] = $manager->update($fromform, $courseid);
    $modtype = $settings->modtype;

    // Refresh only persists settings and selections; Save also applies dates.
    if (isset($fromform->submitbutton) || isset($fromform->submitbutton2)) {
        $tabledata = $manager->get_table_data($settings);
        $count = $manager->apply_dates($tabledata, $settings);
        $modname = $modules[$modtype] ?? $modtype;
        \core\notification::success(
            get_string('datesapplied', 'tool_activitydates', (object) ['count' => $count, 'modname' => $modname])
        );
        dates_updated::create(['context' => $context])->trigger();

        if (isset($fromform->submitbutton2)) {
            redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
        }
    }
}

$tabledata = $manager->get_table_data($settings);

dates_viewed::create(['context' => $context])->trigger();

$mform->set_data($settings);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_activitydates'));
$mform->display();
echo $OUTPUT->render_from_template('tool_activitydates/modtable', [
    'tabledata' => $tabledata,
    'modname' => $modtype,
    'showquestioncount' => $modtype === 'quiz',
    'colcount' => $modtype === 'quiz' ? 6 : 5,
]);
$PAGE->requires->js_call_amd('tool_activitydates/modform', 'init');
echo $OUTPUT->footer();
