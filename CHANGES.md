# Changes

## Unreleased

- New: **saved configurations.** Save the whole page under a name: the settings,
  the ticked activities, every date in the table and the Hold and note ticks.
  Load fills the page from one without changing the course; Delete removes one
  after a confirmation. Configurations belong to the course. Activities deleted
  since saving are left out when it loads, and activities added since load
  unticked.
- New: a **Filter by name** box above the table. It shows only the activities
  whose name contains the text, and the select-all boxes act on those only.
- Fixed: the user documentation listed Lesson, Workshop and Chat as eligible
  activity types. None has `timeopen` and `timeclose` columns; in a standard
  Moodle the eligible types are Quiz, Choice, Feedback and SCORM package.

## v2.1.0

Brings dates and grade locks onto one page, with close/due options, an
optional finish date and an editable preview table.

- Declare Moodle 5.3 support.
- Changed: **one page.** The Grade locks tab is now a collapsible **Grade
  locks** section of the Activity dates page, and the table's **Dates** column
  stacks the open, due, close and locked dates of each activity. The former
  Grade locks page address redirects there. The page opens with either
  capability and shows and saves only what the user may change. Graded types
  without open/close dates, such as assignments, are offered for their lock
  dates only.
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
- New: an editable table. On load and after a save its date fields show each
  activity's current dates (empty where not set), so a save straight after
  loading changes nothing. **Preview** replaces Refresh: it fills the table
  with the proposed dates and saves nothing, where Refresh used to save the
  settings and selection. Save writes exactly the dates in the table after
  checking every row; if a row is wrong, nothing is written and the edits are
  kept. Fields the user cannot change are shown disabled.
- New: a **Hold** checkbox on each date keeps it through Preview, even when the
  settings change; "after a number of days" counts from a held open date. The
  flags are saved per activity and date. Holding open, due and close needs
  `tool/activitydates:manage`; holding the lock date needs
  `tool/activitydates:managelocks`.
- Changed: the student notes are chosen per activity, in a **Grade-lock note**
  column with **Activity page** and **Course page** checkboxes (each with a
  select-all). They replace the Grade locks settings "Show student note" and
  "Also show notes on the course page", which now only set the starting state
  for activities without a saved note. The upgrade keeps what students see.
- Changed: with both capabilities, the page's ticks come from the dates
  selection. An activity that only the grade-lock selection holds (possible
  after the upgrade, because the two tabs were selected separately) shows
  unticked, and saving leaves its grade-lock selection, notes and lock date
  alone.
- Changed: the form's new **Grade locks** and **Advanced** sections collapse;
  the Save buttons sit below them, so they stay visible.
- New: any date in the table can be left empty, meaning "not set"; an empty
  lock date clears the lock. The ordering checks apply only between dates that
  are set.
- New: a changed setting or selection marks the table stale. A bar asks for a
  new Preview and the Save buttons are disabled; the server also refuses a save
  whose settings no longer match the table. The Hold and note checkboxes do not
  mark it stale.
- Changed: "At the end of this session" now closes an activity when the next
  session opens. 2.0.0 closed it the day before, at the finish date's time.
- Changed: day counts use the teacher's timezone, so "+7 days" keeps the time
  of day across a daylight-saving change. A date in the hour that a
  daylight-saving change repeats is saved as the table showed it.
- Changed: a lock date saved unchanged is not rewritten, so an activity whose
  grade items have different lock dates keeps them.
- Changed: an activity without a grade item has no Hold tick on Locked and no
  note ticks, since it has no lock date to hold and no note to show.
- Fixed: deleting an activity now deletes its selections, notes and held dates;
  before, they stayed until the course was deleted.
- Changed: for quizzes, the table's **Question count** column is replaced by
  **Marks**, the quiz's maximum grade.
