# Changelog

All notable changes to `tool_activitydates` are documented in this file.

## [Unreleased]

### Added

- A **Grade locks** tab, next to Activity dates, that schedules gradebook lock
  dates on a session basis: the selected activities of one gradable type are
  grouped into sessions in course order, and each session's grade items get a
  lock date at the end of its session. Ported from `tool_timelocker`, which it
  replaces; both plugins can run on the same site.
- The `tool/activitydates:managelocks` capability for the Grade locks tab
  (editing teachers and managers by default, cloned from `moodle/grade:manage`).
  The tab row shows only the tabs a user can open, and the course navigation
  entry links to the first of them.
- Optional student notes on the activity page ("Grades lock after {date}" /
  "Grades were locked on {date}"), in the activity header on Moodle 5.2+ and at
  the top of the page on 5.0/5.1, and optionally next to each activity on the
  course page.
- Site defaults for the Grade locks tab under a "Grade locks" heading.
- `\tool_activitydates\locks\local\locknote::shows_note()`, a public contract
  that `tool_timelocker` 0.1.1+ calls so that students see one note per
  activity, not two.

### Fixed

- Deleting a course now deletes the plugin's rows for that course.
- The upgrade removes rows left behind by courses deleted under earlier
  versions.

## [1.0.0] - 2026-09-24

First stable release.

### Changed

- Maturity is now stable.
- CI tests only Moodle 5.2 on PHP 8.4 (pgsql and mariadb). The plugin still
  declares support for Moodle 5.0-5.2.

### Security

- The form header now shows the course short name through `format_string()`
  instead of raw, so any markup in it is cleaned before output.

### Added

- A GitHub Actions workflow that submits each pushed `v*` tag to the Moodle
  Marketplace via `moodlehq/moodle-plugin-release` (needs the
  `MOODLE_MARKETPLACE_TOKEN` repository secret).

### Fixed

- The activity table, and the sessions dates are assigned from, now follow the
  course page: activities inside a subsection are listed where the subsection
  sits instead of after every other section.

## [0.1.2] - 2026-08-04

### Added

- The full GPL-3.0 licence text is now included as `LICENSE` in the repository
  root. The plugin's licence is unchanged (GPL-3.0-or-later, as declared in
  `composer.json`); the file was simply missing.

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
