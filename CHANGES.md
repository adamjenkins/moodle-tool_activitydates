# Changes

## v2.2.0

Adds saved configurations and a name filter for the table.

- New: **saved configurations.** Save the whole page under a name: the settings,
  the ticked activities, every date in the table and the Hold and note ticks.
  Load fills the page from one without changing the course; Delete removes one
  after a confirmation. Configurations belong to the course. Activities deleted
  since saving are left out when it loads, and activities added since load
  unticked. A user with only one of the two capabilities saves and loads only
  their part, saving over a configuration keeps the other part, and only a user
  who may change every part of a configuration may delete it.
- New: a **Filter by name** box above the table. It shows only the activities
  whose name contains the text, and the select-all boxes act on those only.
- Fixed: the user documentation listed Lesson, Workshop and Chat as eligible
  activity types. None has `timeopen` and `timeclose` columns; in a standard
  Moodle the eligible types are Quiz, Choice, Feedback and SCORM package.
