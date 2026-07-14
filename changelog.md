# Changelog

All notable changes to `tool_activitydates` are documented in this file.

## [0.1.1] - 2026-07-14

### Fixed

- The Description column on the Activity dates page showed raw HTML tags from the activity intro; tags are now stripped so the description renders as plain text.

## [0.1.0] - 2026-07-12

### Added

- Initial release: version metadata, `tool/activitydates:manage` capability, site default settings (session length, activities per session, stay available, hide unselected), course-navigation link, GDPR null privacy provider, and language strings.
- Course page for bulk-scheduling activity dates: pick an eligible activity type (any module whose instance table has `timeopen`/`timeclose` columns), set a schedule window, session length and activities per session, and select activities in a preview table showing each session's window and every activity's current dates.
- Applying the schedule writes each selected activity's own `timeopen`/`timeclose`, refreshes its calendar events, and makes it visible; unselected activities can optionally be hidden and/or have their dates reset.
- Selections and settings persist per course and survive refreshes and activity-type switches.
- PHPUnit coverage of the scheduling core and Behat coverage of the course UI.
- The session-scheduling approach is adapted from [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green: activities of a course are split into fixed-length sessions and each session is given a start/finish window. Unlike Driprelease, which controls access with availability restrictions, Activity dates writes each activity's own open/close date fields directly.
