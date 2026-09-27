# Changes

## Unreleased

- Declare Moodle 5.3 support.

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
