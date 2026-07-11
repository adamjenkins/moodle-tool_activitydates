# Activity dates (`tool_activitydates`)

A Moodle admin tool that bulk-schedules activity open and close dates across a course on a timed-session basis. Pick a set of activities of the same type (quizzes, choices, feedback, SCORM packages, and any other module whose instance table has `timeopen`/`timeclose` columns), split them into sessions, and write each session's window straight into the module's own dates — giving students native "Opens:/Closes:" display and calendar events, no availability restrictions involved.

## Requirements

- Moodle 5.0 or later

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/activitydates/`
2. Visit Site administration → Notifications to run the upgrade

## How it works

From a course's administration menu, a teacher or manager opens **Activity dates**, picks an activity type, and sets a schedule start date, finish date, session length (in days), and how many activities go in each session. The tool splits the course's activities of that type into sessions in course order, and for each selected activity writes an open date at the start of its session and a close date at the end of it (unless "Stay available after session finish" is set, in which case no close date is written).

Activities left unselected are untouched by default, but can optionally be hidden and/or have their dates reset.

## Settings

Site administration → Plugins → Admin tools → Activity dates provides site-wide defaults for the scheduling form: session length, activities per session, and whether activities stay available after their session finishes / unselected activities are hidden by default.

## Privacy

This plugin stores only course-level scheduling configuration (which activity type, session settings, and which activities are selected) — it does not store or process any personal user data, and implements Moodle's privacy `null_provider`.

## Acknowledgements

This plugin was inspired by, and adapts the session-scheduling approach of, [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green. Where Driprelease controls access with availability restrictions, Activity dates writes each activity's own open/close dates instead.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
