# Activity dates (`tool_activitydates`)

A Moodle admin tool that bulk-schedules activity open and close dates across a course on a timed-session basis. Pick a set of activities of the same type (quizzes, choices, feedback, SCORM packages, and any other module whose instance table has `timeopen`/`timeclose` columns), split them into sessions, and write each session's window straight into the module's own dates — giving students native "Opens:/Closes:" display and calendar events, no availability restrictions involved. On the same page it can also schedule gradebook lock dates, with optional notes that tell students when their grades lock.

## Requirements

- Moodle 5.0 or later

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/activitydates/`
2. Visit Site administration → Notifications to run the upgrade

## How it works

From a course's administration menu, a teacher or manager opens **Activity dates**, picks an activity type, and sets a schedule start date, an optional finish date (off by default), session length (in days), and how many activities go in each session. The tool splits the course's activities of that type into sessions in course order (the order they appear on the course page, so an activity inside a subsection counts at the subsection's position), and proposes for each selected activity an open date at the start of its session. When the finish date is enabled, sessions that start after it are not scheduled; when it is disabled, every selected activity is scheduled.

The close date follows one of four options: a number of days after opening, the end of the session (when the next session opens), one common date, or no close date. Activity types whose table also has a `duedate` column get the same four options for due dates: in a standard Moodle that is only the quiz on Moodle 5.3 and later, so on earlier versions no due settings or column appear. The tool checks the column, not the Moodle version. Due dates must fall after the open date and no later than the close date, the quiz's own rule.

The table's **Dates** column stacks each activity's open, due, close and locked dates as editable fields. When the page loads (and after a save) they show the activity's current dates, empty where a date is not set; **Preview** replaces them with the proposed dates and saves nothing. Each date has a **Hold** checkbox: a held date keeps its value through Preview, even when the settings change, and "after a number of days" counts from a held open date. Hold flags are saved per activity and date. Any date can be edited or left empty (not set; an empty lock date clears the lock), and **Save** writes exactly what the table shows, so a save straight after loading changes nothing. Fields the user cannot change (a missing capability, an unselected row) are shown disabled with their current values. Before writing, every row is validated (close after open, due after open and no later than close, each only when both dates are set); if any row fails, nothing is written and the edits are kept for correction. Changing a setting or a selection after previewing marks the table stale (the Hold and note checkboxes do not): a bar asks for a new Preview and the Save buttons are disabled, and the server refuses a save whose settings no longer match the ones the table was built from. The Save buttons sit below every collapsible section, so they are always visible.

Activities left unselected are untouched by default, but can optionally be hidden and/or have their dates (including the due date) reset.

## Grade locks

A collapsible **Grade locks** section on the same page schedules gradebook lock dates. The lock date uses the same schedule and options as the close date (a number of days after opening, the end of the session, or one common date), with **No lock** in place of "no date". No lock is the default: it leaves existing grade locks alone, and the section stays collapsed while it is selected. The **Locked** date in the table's Dates column shows each activity's current lock date, read live from the gradebook, and after Preview the proposed one. Moodle's own gradebook lock task (`\core\task\grade_cron_task`) locks each grade item when its date passes; the plugin stores no lock dates of its own, only the course's settings and selections. The activity type list also offers graded types without open/close dates, such as assignments, for their lock dates only. An option clears the lock date of every unticked activity of the type.

**Capabilities.** Dates need `tool/activitydates:manage`; grade locks need `tool/activitydates:managelocks` (cloned from `moodle/grade:manage`). Both default to editing teachers and managers. The page opens with either, and shows and saves only the parts the user may change. The course navigation entry "Activity dates" appears for either capability, and the former Grade locks page address redirects to the Activity dates page.

**Student notes.** A **Grade-lock note** column in the table has two checkboxes per activity, **Activity page** and **Course page**, each with a select-all in the header. An activity with the Activity page box ticked shows students a note: "Grades lock after {date}" before its lock date, or "Grades were locked on {date}" once it is locked. On Moodle 5.2 and later the note sits in the activity header, next to the activity's dates (`moodle_page::add_header_extras()`); on Moodle 5.0 and 5.1, which lack that method, it appears at the top of the page instead. With the Course page box ticked as well, the note also shows next to that activity on the course page, only for activities the student can see listed there, and only in course formats that use Moodle's standard activity layout.

**Running alongside `tool_timelocker`.** The Grade locks section replaces `tool_timelocker`, but both plugins can be installed on the same site. They write the same core grade-item lock date, so for an activity scheduled in both, whichever was saved last is in force; both tables read the date live from the gradebook, so each shows the date that actually applies. `tool_timelocker` 0.1.1 and later calls `\tool_activitydates\locks\local\locknote::shows_note()` and stays quiet for an activity where this plugin already shows a note, so students see one note, not two. Nothing is migrated from `tool_timelocker`; its existing lock dates stay in the gradebook.

## Settings

Site administration → Plugins → Admin tools → Activity dates provides site-wide defaults for the scheduling form: session length, activities per session, whether the finish date is enabled, the due-date and close-date options (with their number of days), and whether unselected activities are hidden by default. A "Grade locks" heading on the same page holds the grade-lock defaults: the lock option (No lock) with its number of days, and the starting state of the Activity page and Course page note checkboxes for activities without a saved note.

## Privacy

This plugin stores only course-level configuration (which activity type, session settings, which activities are selected for both dates and grade locks, their note settings, and which of their dates are held) — it does not store or process any personal user data, and implements Moodle's privacy `null_provider`. Deleting a course deletes its configuration.

## Acknowledgements

This plugin was inspired by, and adapts the session-scheduling approach of, [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green. Where Driprelease controls access with availability restrictions, Activity dates writes each activity's own open/close dates instead.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
