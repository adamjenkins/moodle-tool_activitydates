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
$string['activitydates:managelocks'] = 'Manage bulk gradebook lock scheduling';
$string['activitydatesforcourse'] = 'Activity dates for {$a}';
$string['activitytype'] = 'Activity type';
$string['dateformat'] = '%a %d %b %Y %H:%M';
$string['datesapplied'] = 'Updated dates for {$a->count} of {$a->modname}.';
$string['event_locks_updated'] = 'Gradebook lock dates updated';
$string['event_locks_viewed'] = 'Gradebook lock scheduling viewed';
$string['eventdatesupdated'] = 'Activity dates updated';
$string['eventdatesviewed'] = 'Activity dates viewed';
$string['from'] = 'Opens';
$string['gradelockednote'] = 'Grades were locked on {$a}';
$string['gradelocknote'] = 'Grades lock after {$a}';
$string['hideunselected'] = 'Hide unselected';
$string['hideunselected_help'] = 'Hide any activity that is not selected, including from the gradebook.';
$string['lockactivitiespersession'] = 'Activities per session';
$string['lockactivitiespersession_desc'] = 'How many activities have their gradebook lock date grouped into each session. For example, with a session length of 7 days and 5 activities per session, grades for 5 activities lock every week.';
$string['lockactivitiespersession_help'] = 'How many activities have their gradebook lock date grouped into each session. For example, with a session length of 7 days and 5 activities per session, grades for 5 activities lock every week.';
$string['lockresetunselected'] = 'Reset unselected';
$string['lockresetunselected_help'] = 'Clear the gradebook lock date of any activity of this type that is not selected below.';
$string['locksapplied'] = 'Updated the gradebook lock date for {$a} activities.';
$string['lockschedulestart'] = 'Schedule start';
$string['lockschedulestart_help'] = 'The date the first locking session starts counting from. Selected activities are grouped into sessions of "Activities per session", and each session\'s grade items lock at the end of its session window.';
$string['locksessionlength'] = 'Session length (days)';
$string['locksessionlength_desc'] = 'The default length, in days, of each locking session.';
$string['locksessionlength_help'] = 'The length, in days, of each locking session.';
$string['locksettings'] = 'Grade locks';
$string['locksettings_desc'] = 'Defaults for the Grade locks tab, which schedules gradebook lock dates.';
$string['locksforcourse'] = 'Grade locks for {$a}';
$string['locktimecolumn'] = 'Gradebook lock';
$string['noeligiblemodules'] = 'This course has no activities that support open and close dates.';
$string['nogradableactivities'] = 'This course has no activities with gradebook grade items to lock.';
$string['nolockscheduled'] = '—';
$string['nomodulesincourse'] = 'No activities of this type in course';
$string['pluginname'] = 'Activity dates';
$string['positiveintrequired'] = 'Must be a positive whole number';
$string['privacy:metadata'] = 'The Activity dates admin tool only stores course-level configuration (schedule and session settings for activity dates and grade locks) and does not store any personal user data.';
$string['questioncount'] = 'Question count';
$string['refresh'] = 'Refresh';
$string['resetunselected'] = 'Reset unselected';
$string['resetunselected_help'] = 'Clear the open and close dates of any activity that is not selected.';
$string['schedulefinish'] = 'Finish';
$string['schedulestart'] = 'Start';
$string['schedulestart_help'] = 'The start and finish dates set the overall window that activities are scheduled within. Sessions are distributed evenly across this window, session length permitting.';
$string['selectactivity'] = 'Select {$a}';
$string['session'] = 'Session';
$string['sessionlength'] = 'Session length (days)';
$string['sessionlength_help'] = 'The length, in days, of each session. A new set of activities becomes available at the start of each session and, unless "Stay available after session finish" is set, becomes unavailable again at the end of it.';
$string['sessionlengtherror'] = 'Session length must be more than zero.';
$string['sessionlengthislonger'] = 'Session length is longer than the time from start to finish. Shorten the session or choose a later finish date.';
$string['shownote'] = 'Show student note';
$string['shownote_desc'] = 'Default value for whether a student-facing note about the gradebook lock date is shown on the activity page.';
$string['shownote_help'] = 'Show a note on the activity page telling students when its grade will lock, or was locked.';
$string['shownotecoursepage'] = 'Also show notes on the course page';
$string['shownotecoursepage_desc'] = 'Default value for whether switched-on student notes are also shown next to their activities on the course page.';
$string['shownotecoursepage_help'] = 'Also show each switched-on student note next to its activity on the course page, not only on the activity page. The note appears only for activities the student can see, and only in course formats that use Moodle\'s standard activity layout.';
$string['shownoteforactivity'] = 'Show note for {$a}';
$string['starttofinishmustbe'] = 'Start to finish must be at least one day.';
$string['stayavailable'] = 'Stay available after session finish';
$string['stayavailable_help'] = 'Keep activities available after their session ends, instead of closing them at the end of the session.';
$string['tabdates'] = 'Activity dates';
$string['tablocks'] = 'Grade locks';
$string['to'] = 'Closes';
$string['togglenotes'] = 'Toggle the show-note setting of all activities';
$string['toggleselection'] = 'Toggle selection of all activities';
$string['willlockon'] = 'Locks {$a}';
