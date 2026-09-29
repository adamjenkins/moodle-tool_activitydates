# Activity dates — User documentation

`tool_activitydates` is a Moodle admin tool that bulk-schedules the **open and close dates** of a course's activities on a timed-session basis. Instead of setting each quiz, choice or feedback's dates by hand, you pick a group of activities of one type, split them into evenly-spaced sessions, and let the tool write each session's window straight into the activities' own date fields.

## Background

### The problem it solves

A common teaching pattern is *drip release*: make a batch of activities available for a week, then the next batch the week after, and so on. Doing this by hand across a course full of quizzes means opening every quiz's settings, typing an open date and a close date, saving, and repeating — tedious and easy to get wrong.

### How it differs from Driprelease

The scheduling idea is adapted from [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green. The important difference:

- **Driprelease** controls access with *availability restrictions* (the "Restrict access" mechanism). The activity's own dates are untouched; access is gated by a condition.
- **Activity dates** writes each activity's **own `timeopen` / `timeclose` fields**. Students get the native "Opens:" / "Closes:" display, real calendar events, and the module's built-in date behaviour — with no restriction rules involved.

### What counts as an eligible activity

A module type is eligible only if its instance table has **both** a `timeopen` and a `timeclose` column. In a standard Moodle that includes **Quiz, Choice, Feedback, SCORM package, Lesson, Workshop, Chat** and similar. Types without those columns (e.g. Page, Label, URL) never appear. The tool checks the columns live, so third-party modules with the same columns are picked up automatically.

## Requirements

- Moodle 5.0 or later.

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/activitydates/`.
2. Visit **Site administration → Notifications** and complete the upgrade.

## Who can use it

Users with the `tool/activitydates:manage` capability in the course. By default that is **editing teachers** and **managers**. The capability carries a `RISK_DATALOSS` warning because applying dates bulk-overwrites the activities' open/close dates.

## Using the tool

![The Activity dates tool in a course: activity-type selector, schedule window, session settings, and the activities grouped into sessions.](screenshots/table.png)

### 1. Open it

From within a course, go to the course administration menu and click **Activity dates**. (There is no site-wide entry point — the tool always runs in the context of one course. Hitting the plugin's `index.php` directly just redirects to the admin index.)

If the course has no activities that support open/close dates, you get a notice and nothing else to do.

### 2. Choose the activity type

Pick the **Activity type** (e.g. Quizzes) from the dropdown. Changing the type previews that type's activities in the table below straight away; the **Preview** button next to the dropdown does the same. Only one type is scheduled at a time; each type keeps its own selection.

### 3. Set the schedule window and session settings

| Field | Meaning |
|-------|---------|
| **Start** | When the first session opens. |
| **Finish** (tick **Enable** to use it) | Optional cap. When enabled, no session that starts after it is scheduled, so any activities left over are not scheduled. It does not move any close or due date. When disabled, every selected activity is scheduled. |
| **Session length (days)** | Length of each session. A new batch of activities opens at the start of each session. |
| **Activities per session** | How many activities go in each session. E.g. 7-day sessions + 5 per session = 5 new activities each week. |
| **Close dates** | How each activity's close date is set (see below). |
| **Days until close** / **Close all on** | Shown only for the *After a number of days* / *All on a date* options. |
| **Due dates** | How each activity's due date is set. Shown only for activity types that have a due date: in a standard Moodle that is **Quiz on Moodle 5.3 and later**. |
| **Days until due** / **Due all on** | Shown only for the *After a number of days* / *All on a date* options. |

Close dates and due dates each offer four options:

| Option | Close (or due) date |
|--------|---------------------|
| **After a number of days** | That many days after the activity opens. Days are counted in your own timezone, so "+7 days" keeps the same time of day across a daylight-saving change. |
| **At the end of this session** | When the next session opens (the open date plus the session length). The last session ends the same way. |
| **All on a date** | The same date and time for every activity. |
| **No date** | No close (or due) date. With no close date, activities stay available once opened. |

Due dates must fall after the open date and no later than the close date, as in the quiz's own settings. A fixed date can suit early sessions but not later ones, so this is checked for each row of the table when you save (see step 5).

Advanced options:

| Option | Effect |
|--------|--------|
| **Hide unselected** | Any activity of this type left unticked is hidden (including from the gradebook). |
| **Reset unselected** | Clear the open, close and (where the type has one) due dates of any unticked activity and delete its calendar events. |

### 4. Select activities and preview the dates

Tick the activities you want scheduled in the table, then press **Preview**. The header rows show the session number and its **Opens – Closes** window. Each activity row shows its current dates as text so you can see what will change, and for each selected, scheduled activity, editable **Open**, **Due** (when the type has due dates) and **Close** fields filled with the proposed dates. Use the header checkbox to select/deselect all.

Every other row shows its status instead: **Not scheduled** (unticked, or past the finish date), **Will be hidden** (with *Hide unselected*) or **Dates will be reset** (with *Reset unselected*).

Activities are split into sessions **in course order** — the order they appear on the course page — in chunks of *Activities per session*.

**Preview saves nothing.** It recalculates the table from the settings and ticks on the page, and replaces any dates you had edited.

**Editing the dates.** You can change any date in the table before saving; Save writes exactly what the table shows. Open is required. Leave Close or Due empty for no date.

**Stale table.** If you change a setting or a tick after previewing, a bar above the table says "Settings changed. Press Preview to update the dates." and the Save buttons are disabled until you press **Preview**. The server checks this as well: if the settings saved do not match the ones the table was built from, nothing is saved, the dates are recalculated, and you are asked to check them and save again.

### 5. Save

- **Preview** (next to the type dropdown) — recalculates the table, saves nothing.
- **Save and display** — saves the settings and selection, **writes the table's dates**, and stays on the page.
- **Save and return to course** — same, then returns to the course.
- **Cancel** — discards and returns to the course.

Before anything is written, every editable row is checked: Open must be set, Close must be after Open, and Due must be after Open and no later than Close. If any row fails, **nothing is saved**: the table keeps your edits, each wrong field is marked with its error, and a notice says how many dates need correcting.

On a save that applies dates you get a "Updated dates for N of *type*" confirmation.

## What "apply" actually does

For every **selected, scheduled** activity:

- Sets `timeopen`, `timeclose` and, where the type has one, `duedate` to the values in its table row (an empty field writes 0, no date).
- Makes the activity visible.
- Recreates its open/close **calendar events**.
- Triggers a `course_module_updated` event.

For **unselected** activities: hidden if *Hide unselected* is on (otherwise shown); dates (including the due date) cleared and calendar events deleted if *Reset unselected* is on.

With the **Finish** date enabled, anything scheduled to open after it is skipped — both in the preview and on apply — so you never see a window the tool would refuse to write. Only rows the table offers for editing can be written: a date sent for any other activity is ignored.

## Grade locks tab

The **Grade locks** tab, next to **Activity dates** at the top of the page, schedules **gradebook lock dates** for a course's graded activities on the same session basis. Once a grade item is locked, its grades can no longer be changed by the activity (a late submission or a regrade no longer reaches the gradebook) until a teacher unlocks it.

Who can use it: users with the `tool/activitydates:managelocks` capability in the course — by default **editing teachers** and **managers** (it is cloned from `moodle/grade:manage` and carries a `RISK_DATALOSS` warning). The two tabs are separate: a user who holds only one of the two capabilities sees only that page and no tab row, and a user with only `managelocks` who opens the dates page is taken to the Grade locks page.

### 1. Open it

From the course administration menu, click **Activity dates**, then the **Grade locks** tab. If the course has no activities with gradebook grade items, you get a notice and nothing else to do.

### 2. Choose the activity type

Pick a gradable **Activity type** (e.g. Quizzes, Assignments). Changing the type previews it straight away; the **Preview** button does the same. Only one type is scheduled at a time.

### 3. Set the schedule

| Field | Meaning |
|-------|---------|
| **Schedule start** | When the first session starts counting from. |
| **Session length (days)** | Length of each session. |
| **Activities per session** | How many of the *selected* activities go in each session. |
| **Show student note** | Whether the student note is switched on for activities that you tick for the first time. Each row's own **Show student note** box is what counts once saved. |
| **Also show notes on the course page** | Show each switched-on note next to its activity on the course page as well as on the activity page. |
| **Reset unselected** (advanced) | Clear the gradebook lock date of every activity of this type that is not ticked. |

Unlike the dates tab, which splits *all* the type's activities into sessions, the Grade locks tab splits only the **selected** activities, in course order, and each session's grade items lock at the **end** of its session. With a start of 1 March, 7-day sessions and 2 activities per session, the first two ticked activities lock on 8 March, the next two on 15 March, and so on.

### 4. Select activities and notes

Tick the activities to lock in the first column, then press **Preview**. The **Gradebook lock** column shows each activity's current lock date, read live from the gradebook. Each selected activity also gets an editable **Lock date** field, filled with the end of its session. Tick **Show student note** in the last column for each activity whose students should see the note. The header checkboxes select or clear a whole column.

As on the dates tab, **Preview saves nothing** and replaces any lock dates you had edited, and Save writes exactly the lock dates in the table. Changing a setting or a selection tick after previewing shows the "Settings changed" bar and disables the Save buttons until you press **Preview** again; the note ticks and *Reset unselected* do not, because they change no lock date.

### 5. Save

The buttons work as on the dates tab: **Preview** recalculates the table and saves nothing; **Save and display** and **Save and return to course** save the settings and selection and apply the table's lock dates; **Cancel** discards. Every selected row needs a lock date; a date in the past is allowed, and the gradebook locks the item on its next scheduled run. If a row is empty or not a valid date, nothing is saved and the row is marked. After applying you get an "Updated the gradebook lock date for N activities." confirmation.

### What Apply does on this tab

- Sets the lock date (`locktime`) of every grade item of each selected activity to the date in its table row (the end of its session unless you edited it). Moodle's own scheduled task (`\core\task\grade_cron_task`) then locks the items once that time has passed; nothing is locked at the moment you save.
- Skips a selected activity that has no grade item (for example an ungraded activity of a gradable type); it is not counted.
- With **Reset unselected** on, clears the lock date of every unselected activity of the type (an item that is already locked stays locked).
- The plugin does not store lock dates itself: the gradebook's lock date is the only record, so a date changed in the gradebook's own settings shows up here too.

### What students see

With the note switched on for an activity, students see on the activity page:

- before the lock date: **"Grades lock after {date}"**;
- once locked: **"Grades were locked on {date}"**.

If the activity has several grade items, the earliest date applies. On Moodle 5.2 and later the note sits in the activity header next to the activity's dates; on Moodle 5.0 and 5.1 it appears at the top of the page. With **Also show notes on the course page** on, a compact copy of the note also appears under each activity on the course page — only for activities the student can see listed there, and only in course formats that use Moodle's standard activity layout.

### Using it alongside Timelocker

This tab replaces the `tool_timelocker` plugin, but both can be installed on one site. Both write the same gradebook lock date, so for an activity scheduled in both, the last save wins, and both show the date actually in force. `tool_timelocker` 0.1.1 and later stays quiet on an activity where this plugin already shows a note, so students see one note. Nothing is migrated from Timelocker; its lock dates stay in the gradebook.

## Site-wide defaults

**Site administration → Plugins → Admin tools → Activity dates** sets the defaults new courses start with:

![Site-wide default settings for Activity dates under Site administration → Plugins → Admin tools.](screenshots/settings.png)


- Session length (default **7**).
- Activities per session (default **2**).
- Close dates (default **At the end of this session**) and Days until close (default **7**).
- Due dates (default **No date**) and Days until due (default **7**). These apply only to activity types with a due date.
- Hide unselected (default off).

A **Grade locks** heading on the same page holds the Grade locks tab's own defaults:

- Session length (default **7**).
- Activities per session (default **5**).
- Show student note (default on).
- Also show notes on the course page (default off).

These only seed the form; each course then stores its own configuration.

## Validation rules

The form rejects a Preview or a save when:

- The finish date is enabled and start-to-finish is less than one day.
- The finish date is enabled and the session length is longer than the start-to-finish window.
- Session length is below 1.
- Activities per session is below 1 or larger than the number of eligible activities.
- *After a number of days* is chosen for close or due dates and the number of days is below 1.
- *All on a date* is chosen for close or due dates and the date is not after the start.

On save, each row of the table is also checked (see *Save* above): open set, close after open, and due after open and no later than close.

## Upgrading from 2.0.0

- The **Stay available after session finish** option is gone. The upgrade converts it: a course (or the site default) that had it on gets the close option **No date**, and every other course gets **At the end of this session**.
- **Behaviour change:** *At the end of this session* now closes an activity when the next session opens. In 2.0.0 it closed the day before, at the finish date's time of day.
- The finish date no longer sets the time of day at which sessions close; it is now only an optional cap.
- On Moodle 5.3 and later, the quiz's due date is written with its open and close dates and kept between them. Existing course schedules start with **No date** for due dates.

## Privacy

The tool stores only **course-level scheduling configuration** — the chosen activity type, session settings, and which course modules are selected, for both dates and grade locks. It stores **no personal user data** and implements Moodle's `null_provider`.

## Data stored

- `tool_activitydates` — one configuration row per course.
- `tool_activitydates_cmids` — the selected course-module IDs for that configuration.
- `tool_activitydates_lock` — one Grade locks configuration row per course.
- `tool_activitydates_lockitem` — the selected course-module IDs for that configuration, each with its note switch.

Deleting a course deletes its rows from all four tables.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
