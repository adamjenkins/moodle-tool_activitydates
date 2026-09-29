# Changes

## Unreleased

Brings dates and grade locks onto one page, with close/due options, an
optional finish date and an editable preview table.

- Declare Moodle 5.3 support.
- Changed: **one page.** The Grade locks tab is now a collapsible **Grade
  locks** section of the Activity dates page, and the table shows the open,
  due, close and locked dates side by side. The former Grade locks page address
  redirects there. The page opens with either capability and shows and saves
  only what the user may change. Graded types without open/close dates, such as
  assignments, are offered for their lock dates only.
- Changed: grade locks use the page's schedule. The lock date has the same
  options as the close date (after a number of days, at the end of this
  session, all on a date) or **No lock**, which leaves existing grade locks as
  they are. The lock page's own start, session length and activities per
  session are gone. After the upgrade every course is on No lock, so no lock
  date changes; notes are kept.
- New: the session **Finish** date has an Enable checkbox, off by default (a new
  site default). Enabled, it only caps the schedule (sessions that start after
  it are not scheduled); disabled, every selected activity is scheduled. It no
  longer sets any time of day.
- New: **Close dates** offers four options: after a number of days, at the end
  of this session, all on a date, or no date. They replace the "Stay available
  after session finish" checkbox, which the upgrade converts (on becomes "No
  date", off becomes "At the end of this session"), in each course and in the
  site defaults.
- New: **Due dates**, with the same four options, for activity types whose
  table has a `duedate` column: the quiz on Moodle 5.3 and later (earlier
  versions show no due settings or column). Due dates must be after the open
  date and no later than the close date, as in the quiz's own settings, so the
  quiz's due date now stays consistent with the open and close dates the tool
  writes. "Reset unselected" clears it too.
- New: **Preview** replaces Refresh. It fills an editable table and saves
  nothing, where Refresh used to save the settings and selection. Save writes
  exactly the dates in the table after checking every row; if a row is wrong,
  nothing is written and the edits are kept.
- New: any date in the table can be left empty, meaning "not set"; an empty
  lock date clears the lock. The ordering checks apply only between dates that
  are set.
- New: a changed setting or selection marks the table stale. A bar asks for a
  new Preview and the Save buttons are disabled; the server also refuses a save
  whose settings no longer match the table.
- Changed: "At the end of this session" now closes an activity when the next
  session opens. 2.0.0 closed it the day before, at the finish date's time.
- Changed: day counts use the teacher's timezone, so "+7 days" keeps the time
  of day across a daylight-saving change.

## v2.0.0

Adds a **Grade locks** tab next to Activity dates. It takes over from
`tool_timelocker`, which is discontinued.

- New: the Grade locks tab schedules gradebook lock dates on a session basis.
  The selected activities of one gradable type are grouped into sessions in
  course order, and each session's grade items lock at the end of its session.
  Core's grade cron task applies the lock.
- New: optional student notes. "Grades lock after {date}" or "Grades were
  locked on {date}" appears on the activity page and, optionally, next to each
  activity on the course page.
- New capability `tool/activitydates:managelocks` (editing teachers and
  managers by default). The tab row shows only the tabs a user can open.
- New site defaults for the Grade locks tab.
- Running alongside `tool_timelocker`: both can be installed. Version 0.1.1
  and later of it step aside, so students see one note per activity. There is
  no data migration; lock dates already in the gradebook stay in force.
- Fixed: deleting a course now deletes this plugin's rows for it. The upgrade
  also removes rows left behind by earlier deletions.
