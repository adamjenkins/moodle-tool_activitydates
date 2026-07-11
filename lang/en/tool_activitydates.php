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
 * English language strings for tool_activitydates.
 *
 * @package    tool_activitydates
 * @category   string
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitiespersession'] = 'Activities per session';
$string['activitiespersession_help'] = 'How many activities are made available in each session. For example, with a session length of 7 days and 5 activities per session, students see 5 new activities every week.';
$string['activitiespersessionerror'] = 'Activities per session is {$a->activitiespersession} but the course only has {$a->modulecount} eligible activities.';
$string['activitydates:manage'] = 'Manage bulk activity dates';
$string['activitydatesforcourse'] = 'Activity dates for {$a}';
$string['activitytype'] = 'Activity type';
$string['eventdatesupdated'] = 'Activity dates updated';
$string['eventdatesviewed'] = 'Activity dates viewed';
$string['from'] = 'Opens';
$string['hideunselected'] = 'Hide unselected';
$string['hideunselected_help'] = 'Hide any activity that is not selected, including from the gradebook.';
$string['noeligiblemodules'] = 'This course has no activities that support open and close dates.';
$string['nomodulesincourse'] = 'No activities of this type in course';
$string['pluginname'] = 'Activity dates';
$string['privacy:metadata'] = 'The Activity dates admin tool only stores course-level configuration (schedule and session settings) and does not store any personal user data.';
$string['questioncount'] = 'Question count';
$string['refresh'] = 'Refresh';
$string['resetunselected'] = 'Reset unselected';
$string['resetunselected_help'] = 'Clear the open and close dates of any activity that is not selected.';
$string['schedulefinish'] = 'Finish';
$string['schedulestart'] = 'Start';
$string['schedulestart_help'] = 'The start and finish dates set the overall window that activities are scheduled within. Sessions are distributed evenly across this window, session length permitting.';
$string['session'] = 'Session';
$string['sessionlength'] = 'Session length (days)';
$string['sessionlength_help'] = 'The length, in days, of each session. A new set of activities becomes available at the start of each session and, unless "Stay available after session finish" is set, becomes unavailable again at the end of it.';
$string['sessionlengtherror'] = 'Session length must be more than zero.';
$string['sessionlengthislonger'] = 'Session length is longer than the time from start to finish. Shorten the session or choose a later finish date.';
$string['starttofinishmustbe'] = 'Start to finish must be at least one day.';
$string['stayavailable'] = 'Stay available after session finish';
$string['stayavailable_help'] = 'Keep activities available after their session ends, instead of closing them at the end of the session.';
$string['to'] = 'Closes';
$string['toggleselection'] = 'Toggle selection of all activities';
