# Activity dates — User documentation

`tool_activitydates` is a Moodle admin tool that bulk-schedules the **open and close dates** of a course's activities on a timed-session basis. Instead of setting each quiz, choice or feedback's dates by hand, you pick a group of activities of one type, split them into evenly-spaced sessions, and let the tool write each session's window straight into the activities' own date fields. On the same page it can also schedule each activity's **gradebook lock date**, with an optional note that tells students when their grades lock.

## Background

### The problem it solves

A common teaching pattern is *drip release*: make a batch of activities available for a week, then the next batch the week after, and so on. Doing this by hand across a course full of quizzes means opening every quiz's settings, typing an open date and a close date, saving, and repeating — tedious and easy to get wrong.

### How it differs from Driprelease

The scheduling idea is adapted from [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green. The important difference:

- **Driprelease** controls access with *availability restrictions* (the "Restrict access" mechanism). The activity's own dates are untouched; access is gated by a condition.
- **Activity dates** writes each activity's **own `timeopen` / `timeclose` fields**. Students get the native "Opens:" / "Closes:" display, real calendar events, and the module's built-in date behaviour — with no restriction rules involved.

### What counts as an eligible activity

A module type is eligible only if its instance table has **both** a `timeopen` and a `timeclose` column. In a standard Moodle that includes **Quiz, Choice, Feedback, SCORM package, Lesson, Workshop, Chat** and similar. Types without those columns (e.g. Page, Label, URL) never appear. The tool checks the columns live, so third-party modules with the same columns are picked up automatically.

For grade locks, a type is eligible if its activities have gradebook grade items (e.g. Quiz, Assignment). A graded type without the date columns, such as Assignment, is offered for its lock dates only.

## Requirements

- Moodle 5.0 or later.

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/activitydates/`.
2. Visit **Site administration → Notifications** and complete the upgrade.

## Who can use it

The page needs one of two capabilities in the course, and it shows and saves only what the user may change:

| Capability | Lets the user | Default holders |
|------------|---------------|-----------------|
| `tool/activitydates:manage` | Schedule open, due and close dates; the hide and reset options for unselected activities. | Editing teachers and managers |
| `tool/activitydates:managelocks` | Schedule gradebook lock dates and the student notes (the **Grade locks** section and the **Locked** and **Show student note** columns). Cloned from `moodle/grade:manage`. | Editing teachers and managers |

Both carry a `RISK_DATALOSS` warning, because a save bulk-overwrites the activities' dates or the gradebook's lock dates. A user with neither capability gets the standard "no permission" error.

## Using the tool

![The Activity dates tool in a course: activity-type selector, schedule window, session settings, and the activities grouped into sessions.](screenshots/table.png)

### 1. Open it

From within a course, go to the course administration menu and click **Activity dates**. The entry is shown to users with either capability. (There is no site-wide entry point — the tool always runs in the context of one course. Hitting the plugin's `index.php` directly just redirects to the admin index.) Everything is on this one page; the old Grade locks page address (`locks.php`) now redirects to it.

If the course has no activity the user can schedule, you get a notice and nothing else to do.

### 2. Choose the activity type

Pick the **Activity type** (e.g. Quizzes) from the dropdown. Changing the type previews that type's activities in the table below straight away; the **Preview** button next to the dropdown does the same. Only one type is scheduled at a time; each type keeps its own selection.

The dropdown offers two kinds of type, and only those the user can act on:

- **Types with dates** — their instance table has both `timeopen` and `timeclose` (Quiz, Choice, Feedback, SCORM package, Lesson, Workshop, Chat…). They need `:manage`. If they also have gradebook grade items (e.g. Quiz), a user with `:managelocks` can schedule their lock dates too.
- **Lock-only types** — graded types without those date columns, for example **Assignments**. They need `:managelocks`, and for them only the **Locked** date is scheduled.

### 3. Set the schedule

The form shows, in this order:

1. **Activity type** and **Preview**.
2. **Schedule** — Start, Finish, Session length, Activities per session.
3. **Due dates** — only for activity types with a due date: in a standard Moodle that is the **quiz on Moodle 5.3 and later**. On Moodle 5.0 to 5.2 the quiz has no due date, so these settings and the Due column do not appear. (The tool checks for the `duedate` column, not the Moodle version.) Needs `:manage`.
4. **Close dates**. Needs `:manage`.
5. **Grade locks** section. Needs `:managelocks`. It is collapsed while its lock option is **No lock**.
6. **Advanced** — Hide unselected, Reset unselected. Needs `:manage`.
7. The buttons.

The open date has no settings of its own: each activity opens at the start of its session.

| Field | Meaning |
|-------|---------|
| **Start** | When the first session opens. |
| **Finish** (tick **Enable** to use it) | Optional cap, **off by default**. When enabled, no session that starts after it is scheduled, so any activities left over are not scheduled. It does not move any close, due or lock date. When disabled, every selected activity is scheduled. |
| **Session length (days)** | Length of each session. A new batch of activities opens at the start of each session. |
| **Activities per session** | How many activities go in each session. E.g. 7-day sessions + 5 per session = 5 new activities each week. |
| **Due dates** / **Days until due** / **Due all on** | How each activity's due date is set (see below). The days and date fields show only for the *After a number of days* / *All on a date* options. |
| **Close dates** / **Days until close** / **Close all on** | How each activity's close date is set, likewise. |

Close, due and lock dates each offer four options:

| Option | Close, due or lock date |
|--------|-------------------------|
| **After a number of days** | That many days after the activity opens. Days are counted in your own timezone, so "+7 days" keeps the same time of day across a daylight-saving change. |
| **At the end of this session** | When the next session opens (the open date plus the session length). The last session ends the same way. |
| **All on a date** | The same date and time for every activity. |
| **No date** (for locks: **No lock**) | No close or due date: with no close date, activities stay available once opened. For locks, **No lock** means the tool leaves every existing grade lock as it is. |

Due dates must fall after the open date and no later than the close date, as in the quiz's own settings. A fixed date can suit early sessions but not later ones, so this is checked for each row of the table when you save (see step 5).

**Grade locks section:**

| Field | Meaning |
|-------|---------|
| **Lock grades** / **Days until locked** / **Lock all on** | How each selected activity's gradebook lock date is set, with the four options above. Days count from the activity's open date (its session start). |
| **Show student note** | Whether the student note is switched on for activities that you tick for the first time. Each row's own **Show student note** box is what counts once saved. |
| **Also show notes on the course page** | Show each switched-on note next to its activity on the course page as well as on the activity page. |
| **Clear the locks of unselected activities** | Clear the gradebook lock date of every activity of this type that is not ticked. This applies whatever the lock option, including **No lock**. |

**Advanced:**

| Option | Effect |
|--------|--------|
| **Hide unselected** | Any activity of this type left unticked is hidden (including from the gradebook). |
| **Reset unselected** | Clear the open, close and (where the type has one) due dates of any unticked activity and delete its calendar events. |

### 4. Select activities and preview the dates

Tick the activities you want scheduled in the table, then press **Preview**. The header rows show the session number and its **Opens – Closes** window. The table's columns are:

- the selection tick (the header checkbox selects or clears them all), the name, the description, and for quizzes the question count;
- **Current** — the activity's dates now: open, due, close and locked (the lock date read live from the gradebook);
- **Open**, **Due** (only for types with a due date, i.e. quizzes on Moodle 5.3 and later), **Close** and **Locked** — for each selected, scheduled activity, editable fields filled with the proposed dates;
- **Show student note** (with `:managelocks`; the header checkbox switches them all).

Which date fields are editable:

- Open, Due and Close need `:manage` and a type with dates.
- Locked needs `:managelocks`, a lock option other than **No lock**, and an activity with a grade item. With **No lock** the Current column still shows each lock in force.
- A field you cannot edit is not shown as a field; the Current column shows its value.

Every other row shows its status instead: **Not scheduled** (unticked, or past the finish date), **Will be hidden** (with *Hide unselected*) or **Dates will be reset** (with *Reset unselected*).

Activities are split into sessions **in course order** — the order they appear on the course page — in chunks of *Activities per session*.

**Preview saves nothing.** It recalculates the table from the settings and ticks on the page, and replaces any dates you had edited.

**Editing the dates.** You can change any date in the table before saving; Save writes exactly what the table shows. **Every date may be left empty**, which means "not set": an empty Open or Close saves no open or close date, an empty Due saves no due date, and an empty Locked clears that activity's grade lock.

**Stale table.** If you change a setting or a selection tick after previewing, a bar above the table says "Settings changed. Press Preview to update the dates." and the Save buttons are disabled until you press **Preview**. The note ticks and the *unselected* options do not do this, because they change no date. The server checks this as well: if the settings saved do not match the ones the table was built from, nothing is saved, the dates are recalculated, and you are asked to check them and save again.

### 5. Save

- **Preview** (next to the type dropdown) — recalculates the table, saves nothing.
- **Save and display** — saves the settings and selection, **writes the table's dates**, and stays on the page.
- **Save and return to course** — same, then returns to the course.
- **Cancel** — discards and returns to the course.

Before anything is written, every editable row is checked, and only between dates that are set: Close must be after Open, and Due must be after Open and no later than Close. A lock date has no ordering rule, and may be in the past (the gradebook then locks the item on its next scheduled run). If any row fails, or a field is not a valid date, **nothing is saved**: the table keeps your edits, each wrong field is marked with its error, and a notice says how many dates need correcting.

A save that writes dates confirms "Updated dates for N of *type*"; one that writes lock dates confirms "Updated the gradebook lock date for N activities."

## What Save actually does

With `:manage`, for a type with dates, for every **selected, scheduled** activity:

- Sets `timeopen`, `timeclose` and, where the type has one, `duedate` to the values in its table row (an empty field writes 0, no date).
- Makes the activity visible.
- Recreates its open/close **calendar events**.
- Triggers a `course_module_updated` event.

For **unselected** activities: hidden if *Hide unselected* is on (otherwise shown); dates (including the due date) cleared and calendar events deleted if *Reset unselected* is on.

With `:managelocks`:

- The lock settings, the selection and each row's **Show student note** tick are saved.
- Unless the lock option is **No lock**, the lock date (`locktime`) of every grade item of each selected, scheduled activity is set to its **Locked** value; an empty value clears it. Moodle's own scheduled task (`\core\task\grade_cron_task`) then locks the items once that time has passed; nothing is locked at the moment you save. A selected activity without a grade item is skipped and not counted.
- With **No lock**, no lock date is written, so the locks already in the gradebook stay as they are.
- With **Clear the locks of unselected activities** on, the lock date of every unselected activity of the type is cleared (an item that is already locked stays locked).
- The plugin does not store lock dates itself: the gradebook's lock date is the only record, so a date changed in the gradebook's own settings shows up here too.

With the **Finish** date enabled, anything scheduled to open after it is skipped — both in the preview and on save — so you never see a window the tool would refuse to write. Only rows the table offers for editing can be written: a date sent for any other activity, or a field the user may not edit, is ignored.

## Grade lock notes: what students see

With the note switched on for an activity, students see on the activity page:

- before the lock date: **"Grades lock after {date}"**;
- once locked: **"Grades were locked on {date}"**.

If the activity has several grade items, the earliest date applies. On Moodle 5.2 and later the note sits in the activity header next to the activity's dates; on Moodle 5.0 and 5.1 it appears at the top of the page. With **Also show notes on the course page** on, a compact copy of the note also appears under each activity on the course page — only for activities the student can see listed there, and only in course formats that use Moodle's standard activity layout.

### Using it alongside Timelocker

The Grade locks section replaces the `tool_timelocker` plugin, but both can be installed on one site. Both write the same gradebook lock date, so for an activity scheduled in both, the last save wins, and both show the date actually in force. `tool_timelocker` 0.1.1 and later stays quiet on an activity where this plugin already shows a note, so students see one note. Nothing is migrated from Timelocker; its lock dates stay in the gradebook.

## Site-wide defaults

**Site administration → Plugins → Admin tools → Activity dates** sets the defaults new courses start with:

![Site-wide default settings for Activity dates under Site administration → Plugins → Admin tools.](screenshots/settings.png)

- Session length (default **7**).
- Activities per session (default **2**).
- Enable the session finish date by default (default **off**).
- Close dates (default **At the end of this session**) and Days until close (default **7**).
- Due dates (default **No date**) and Days until due (default **7**). These apply only to activity types with a due date.
- Hide unselected (default off).

A **Grade locks** heading on the same page holds the Grade locks section's defaults:

- Lock grades (default **No lock**) and Days until locked (default **7**).
- Show student note (default on).
- Also show notes on the course page (default off).

These only seed the form; each course then stores its own configuration.

## Validation rules

The form rejects a Preview or a save when:

- The finish date is enabled and start-to-finish is less than one day.
- The finish date is enabled and the session length is longer than the start-to-finish window.
- Session length is below 1.
- Activities per session is below 1 or larger than the number of activities of the type.
- *After a number of days* is chosen for close, due or lock dates and the number of days is below 1.
- *All on a date* is chosen for close, due or lock dates and the date is not after the start.

On save, each row of the table is also checked (see *Save* above): among the dates that are set, close after open, and due after open and no later than close.

## Upgrading from 2.0.0

- **One page.** The Grade locks tab is now the **Grade locks** section of the Activity dates page, and its table columns (**Locked**, **Show student note**) join the date columns. Old links to the Grade locks page redirect.
- **Grade locks use the shared schedule.** The Grade locks tab's own schedule start, session length and activities per session (and their two site defaults) are gone; lock dates follow the page's schedule, with the four lock options. Every course starts with **No lock**, so the section is collapsed and the upgrade changes no lock date: locks already in the gradebook stay in force and show in the Current column, and the note settings and per-activity notes are kept.
- The **Stay available after session finish** option is gone. The upgrade converts it: a course (or the site default) that had it on gets the close option **No date**, and every other course gets **At the end of this session**.
- **Behaviour change:** *At the end of this session* now closes an activity when the next session opens. In 2.0.0 it closed the day before, at the finish date's time of day.
- The finish date no longer sets the time of day at which sessions close; it is now only an optional cap, and it is off by default for courses without a saved configuration. Saved configurations keep their setting.
- On Moodle 5.3 and later, the quiz's due date is written with its open and close dates and kept between them. Existing course schedules start with **No date** for due dates.

## Privacy

The tool stores only **course-level scheduling configuration** — the chosen activity type, session and lock settings, and which course modules are selected. It stores **no personal user data** and implements Moodle's `null_provider`.

## Data stored

- `tool_activitydates` — one configuration row per course.
- `tool_activitydates_cmids` — the selected course-module IDs for that configuration.
- `tool_activitydates_lock` — one grade-lock configuration row per course (lock option, note options, clear-unselected).
- `tool_activitydates_lockitem` — the course-module IDs selected for grade locks, each with its note switch.

Deleting a course deletes its rows from all four tables.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
