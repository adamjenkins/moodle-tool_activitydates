# Changelog

All notable changes to `tool_activitydates` are documented in this file.

## [Unreleased]

### Added

- Saved configurations: named per-course snapshots of the page (settings,
  ticked activities, table dates, Hold and note ticks) in the new table
  `tool_activitydates_saved`, with Save configuration, Load and Delete in a
  Saved configurations section. Loading writes nothing; it keeps only the
  parts the user may edit and the activities the course still has. Saving over
  an existing configuration keeps the parts the saver may not edit, and only a
  user who may edit every part a configuration holds may delete it. Table
  values are saved only for the ticked activities. Deleted with the course.
- A Filter by name box above the table (client side). The select-all and the
  note select-alls act on the rows it shows only.

### Fixed

- `docs/userdocs.md` no longer lists Lesson, Workshop and Chat as eligible
  activity types; in a standard Moodle only Quiz, Choice, Feedback and SCORM
  package have both `timeopen` and `timeclose`.

## [2.1.0] - 2026-09-29

### Added

- An Enable checkbox for the session finish date, off by default, with a new
  site default `finishenabled`. Enabled, it caps the schedule: sessions that
  start after it are not scheduled. Disabled, every selected activity is
  scheduled. Saved course configurations keep their setting.
- Close-date options: after a number of days, at the end of this session, all
  on a date, or no date, with site defaults.
- Due-date options, the same four, for activity types whose table has a
  `duedate` column (the quiz on Moodle 5.3 and later; on earlier versions the
  due settings and column do not appear). The feature is gated on the column,
  not on the Moodle version. Due dates must be after the open date and no later
  than the close date.
- Lock-date options for grade locks, on the same schedule: after a number of
  days from opening, at the end of this session, all on a date, or No lock
  (the default), which leaves existing grade locks untouched. Site defaults
  `lockmode` and `lockdays`.
- A **Preview** button and an editable date table: one **Dates** column
  stacks each activity's Open, Due, Close and Locked fields. On load and after
  a save the fields show the current dates (empty where not set); Preview fills
  them with the proposals. Save writes exactly the table's dates, so a save
  straight after loading changes nothing. Fields the user cannot change are
  disabled.
- A **Hold** checkbox for each date. A held date keeps its value through
  Preview, even when the settings change, and "after a number of days" counts
  from a held open date. The flags are stored per activity and field in the
  new table `tool_activitydates_fixed` (`:manage` for open, due and close,
  `:managelocks` for the lock date) and deleted with the activity or the course.
- A **Grade-lock note** column with per-activity **Activity page** and **Course
  page** checkboxes, each with a select-all, stored in the new
  `tool_activitydates_lockitem.shownotecoursepage` field.
- Row validation on Save, only between dates that are set: close after open,
  due after open and no later than close. Any date may be left empty; an empty
  lock date clears the lock. If any row fails, nothing is written and the
  edited values are kept with an error on each wrong field.
- Stale-table protection: changing a date-affecting setting or the selection
  after a Preview shows a "Settings changed" bar and disables the Save buttons;
  the server refuses a save whose settings no longer match the table. The Hold
  and note checkboxes are not part of this check.

### Changed

- Declare Moodle 5.3 support.
- For quizzes, the table's Question count column is replaced by **Marks**, the
  quiz's maximum grade.
- The Grade locks tab is merged into the Activity dates page as a collapsible
  Grade locks section, collapsed while the lock option is No lock. `locks.php`
  redirects to `view.php`. The page needs `:manage` or `:managelocks` and shows
  and saves only what the user's capabilities allow. Graded types without
  open/close dates (e.g. assignments) are offered for their lock dates only.
- Preview replaces Refresh and saves nothing; Refresh saved the settings and
  selection.
- The form's sections Grade locks and Advanced are collapsible; the buttons
  sit below them, so they stay visible while those are collapsed.
- The site settings `lockshownote` and `lockshownotecoursepage` now only set
  the starting state of the note checkboxes for activities without a saved
  note, applied when such an activity is selected; unselected, its boxes show
  unticked.
- With both capabilities the ticks come from the dates selection
  (`tool_activitydates_cmids`). Save keeps the lock item, notes and lock date
  of an unticked activity that only the lock selection holds (e.g. after the
  upgrade from 2.0, whose two tabs were selected separately); unticking an
  activity the page showed ticked removes it from both.
- A lock date posted unchanged is not written, so the grade items of one
  activity keep their different lock dates; it still counts as updated.
- A table date in the hour a daylight-saving fall-back repeats is parsed as the
  row's current or proposed date when that shows the same, so it is saved as
  shown.
- The upgrade sets `shownotecoursepage` on each lock item whose note was on in
  a course that showed notes on the course page, then drops `shownote` and
  `shownotecoursepage` from `tool_activitydates_lock`.
- "At the end of this session" now closes an activity when the next session
  opens. 2.0.0 closed it the day before, at the finish date's time of day. The
  finish date no longer sets any time of day.
- Date arithmetic is done in the user's timezone, so "+N days" keeps the local
  time of day across a daylight-saving change.
- The upgrade converts `stayavailable` into the new close option, per course
  (1 becomes `none`, 0 becomes `session`) and in the site defaults, then drops
  the column and the setting.
- The upgrade gives `tool_activitydates_lock` the `lockmode` (`none`),
  `lockdays` and `lockdate` fields and drops its `modtype`, `schedulestart`,
  `sessionlength` and `activitiespersession`. Lock dates already in the
  gradebook and the note settings are kept.

### Removed

- The course-level "Show student note" and "Also show notes on the course
  page" options of the Grade locks section (now per activity).
- The "Stay available after session finish" option and its site default.
- The Grade locks tab and its own schedule settings (start, session length,
  activities per session) with the `locksessionlength` and
  `lockactivitiespersession` site defaults.

### Fixed

- Deleting an activity deletes its rows in `tool_activitydates_cmids`,
  `tool_activitydates_lockitem` and `tool_activitydates_fixed` (a
  `course_module_deleted` observer); before, they stayed until the course was
  deleted.
- On Moodle 5.3, the quiz's due date is written together with its open and
  close dates and kept between them, and "Reset unselected" clears it
  (MDL-82521 made `quiz.duedate` a column the tool left stale).

## [2.0.0] - 2026-09-27

Adds the Grade locks tab, which takes over from `tool_timelocker`.

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
