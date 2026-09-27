# Activity dates (`tool_activitydates`)

A Moodle admin tool that bulk-schedules activity open and close dates across a course on a timed-session basis. Pick a set of activities of the same type (quizzes, choices, feedback, SCORM packages, and any other module whose instance table has `timeopen`/`timeclose` columns), split them into sessions, and write each session's window straight into the module's own dates — giving students native "Opens:/Closes:" display and calendar events, no availability restrictions involved.

## Requirements

- Moodle 5.0 or later

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/activitydates/`
2. Visit Site administration → Notifications to run the upgrade

## How it works

From a course's administration menu, a teacher or manager opens **Activity dates**, picks an activity type, and sets a schedule start date, finish date, session length (in days), and how many activities go in each session. The tool splits the course's activities of that type into sessions in course order (the order they appear on the course page, so an activity inside a subsection counts at the subsection's position), and for each selected activity writes an open date at the start of its session and a close date at the end of it (unless "Stay available after session finish" is set, in which case no close date is written).

Activities left unselected are untouched by default, but can optionally be hidden and/or have their dates reset.

## Grade locks

The same page has a second tab, **Grade locks**, which schedules gradebook lock dates instead of open/close dates. Pick a gradable activity type, a schedule start, a session length and how many activities go in each session, then tick the activities to lock. The selected activities are grouped into sessions in course order, and each session's grade items get a lock date at the end of its session. Moodle's own gradebook lock task (`\core\task\grade_cron_task`) locks each grade item when its date passes; the plugin stores no lock dates of its own, only the course's settings and selections. An activity of a gradable type that has no grade item is skipped. "Reset unselected" clears the lock date of every unticked activity of that type.

**Capability.** The tab needs `tool/activitydates:managelocks` (editing teachers and managers by default, cloned from `moodle/grade:manage`), separate from `tool/activitydates:manage` for the dates tab. The tab row shows only the tabs the user can open; a user with only `managelocks` who opens the dates page is sent to the Grade locks page, and the course navigation entry "Activity dates" links to the first page the user can open.

**Student notes.** Each activity can show students a note: "Grades lock after {date}" before its lock date, or "Grades were locked on {date}" once it is locked. On Moodle 5.2 and later the note sits in the activity header, next to the activity's dates (`moodle_page::add_header_extras()`); on Moodle 5.0 and 5.1, which lack that method, it appears at the top of the page instead. A course-level option also shows the notes next to their activities on the course page, only for activities the student can see listed there, and only in course formats that use Moodle's standard activity layout.

**Running alongside `tool_timelocker`.** This tab replaces `tool_timelocker`, but both plugins can be installed on the same site. They write the same core grade-item lock date, so for an activity scheduled in both, whichever was saved last is in force; both tables read the date live from the gradebook, so each shows the date that actually applies. `tool_timelocker` 0.1.1 and later calls `\tool_activitydates\locks\local\locknote::shows_note()` and stays quiet for an activity where this plugin already shows a note, so students see one note, not two. Nothing is migrated from `tool_timelocker`; its existing lock dates stay in the gradebook.

## Settings

Site administration → Plugins → Admin tools → Activity dates provides site-wide defaults for the scheduling form: session length, activities per session, and whether activities stay available after their session finishes / unselected activities are hidden by default. A "Grade locks" heading on the same page holds separate defaults for the Grade locks tab: session length, activities per session, whether the student note is on, and whether notes also show on the course page.

## Privacy

This plugin stores only course-level configuration (which activity type, session settings, and which activities are selected, for both dates and grade locks) — it does not store or process any personal user data, and implements Moodle's privacy `null_provider`. Deleting a course deletes its configuration.

## Acknowledgements

This plugin was inspired by, and adapts the session-scheduling approach of, [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green. Where Driprelease controls access with availability restrictions, Activity dates writes each activity's own open/close dates instead.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
