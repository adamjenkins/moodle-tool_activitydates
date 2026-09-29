@tool @tool_activitydates @javascript
Feature: Schedule gradebook lock dates on the Activity dates page
  In order to lock gradebook grade items on the same schedule as the activity dates
  As a teacher
  I need to choose a lock mode in the Grade locks section, select activities,
  and have their gradebook lock dates applied

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                 |
      | teacher1 | Teacher   | 1        | teacher1@example.com  |
      | student1 | Student   | 1        | student1@example.com  |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Course 1 | C1        | topics | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name  | course | idnumber | completion |
      | quiz     | Quiz1 | C1     | quiz1    | 1          |

  Scenario: Teacher schedules a gradebook lock date for a quiz
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Activity dates" in current page administration
    Then I should see "Activity dates for C1"
    And I should see "Quiz1"
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And the grade lock date of "quiz1" should be "2030-01-08T09:00"

  Scenario: Student sees the grade-lock note on an activity with the note enabled
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownote_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on the "Quiz1" "quiz activity" page
    Then I should see "Grades lock after"

  Scenario: Student sees the grade-lock note on the course page when its Course page box is ticked
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]   | 1                          |
      | schedulestart[month] | January                    |
      | schedulestart[year]  | 2030                       |
      | activitiespersession | 1                          |
      | Lock grades          | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownote_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownotecourse_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Grades lock after" in the "Quiz1" "activity"
    # At the bottom of the card, not squeezed into the completion column.
    And "[data-region='activity-card'] > .tool_activitydates-coursenote" "css_element" should exist in the "Quiz1" "activity"

  Scenario: The course-page note survives the course editor re-rendering its activity
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]   | 1                          |
      | schedulestart[month] | January                    |
      | schedulestart[year]  | 2030                       |
      | activitiespersession | 1                          |
      | Lock grades          | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownote_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownotecourse_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    And I am on "Course 1" course homepage with editing mode on
    And I should see "Grades lock after" in the "Quiz1" "activity"
    When I indent right "Quiz1" activity
    Then I should see "Grades lock after" in the "Quiz1" "activity"

  Scenario: The course page shows no grade-lock note when the Course page box is not ticked
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]   | 1                          |
      | schedulestart[month] | January                    |
      | schedulestart[year]  | 2030                       |
      | activitiespersession | 1                          |
      | Lock grades          | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownote_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownotecourse_')]" to "0"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Quiz1"
    And I should not see "Grades lock after"
    And I am on the "Quiz1" "quiz activity" page
    And I should see "Grades lock after"

  Scenario: Changing the activity type refreshes the table without pressing Preview
    Given the following "activities" exist:
      | activity | name    | course | idnumber |
      | assign   | Assign1 | C1     | assign1  |
    And I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I should see "Assign1" in the "table" "css_element"
    When I set the field "modtype" to "Quizzes"
    Then I should see "Quiz1" in the "table" "css_element"
    And I should not see "Assign1" in the "table" "css_element"

  Scenario: Preview fills the lock dates and writes nothing
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    Then I should see "Settings changed. Press Preview to update the dates."
    And the "Save and display" "button" should be disabled
    When I press "Preview"
    Then the "timelock" date input of "Quiz1" should be "2030-01-08T09:00"
    And "Settings changed. Press Preview to update the dates." "text" should not be visible
    And the grade lock date of "quiz1" should be "0"
    And the "timeopen" of "quiz1" should be "0"

  Scenario: An edited lock date is saved exactly, and a cleared one clears the lock
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    When I set the "timelock" date of "Quiz1" to "2030-02-01T12:15"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And the grade lock date of "quiz1" should be "2030-02-01T12:15"
    When I set the "timelock" date of "Quiz1" to ""
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I should not see "Nothing was saved"
    And the grade lock date of "quiz1" should be "0"

  Scenario: The server refuses a lock table built from other settings
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    When I set the field "sessionlength" to "3"
    Then I should see "Settings changed. Press Preview to update the dates."
    And the "Save and display" "button" should be disabled
    And I remove the disabled attribute from the "Save and display" button
    And I press "Save and display"
    Then I should see "Nothing was saved: the settings changed"
    And the grade lock date of "quiz1" should be "0"
    # The table is recalculated from the submitted settings.
    And the "timelock" date input of "Quiz1" should be "2030-01-04T09:00"

  Scenario: No lock leaves the existing grade locks as they are
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Close dates           | At the end of this session |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    And the grade lock date of "quiz1" should be "2030-01-08T09:00"
    And I expand all fieldsets
    When I set the field "Lock grades" to "No lock"
    And I set the field "sessionlength" to "3"
    And I press "Preview"
    # The Locked input is disabled while the lock mode is No lock, and shows the current lock.
    Then the "//tr[.//a[normalize-space(.)='Quiz1']]//input[@data-field='timelock']" "xpath_element" should be disabled
    And the "timelock" date input of "Quiz1" should be "2030-01-08T09:00"
    And I press "Save and display"
    And I should see "Updated dates for 1 of"
    And I should not see "Updated the gradebook lock date"
    # The dates were saved from the new session length; the lock was left alone.
    And the "timeclose" of "quiz1" should be "2030-01-04T09:00"
    And the grade lock date of "quiz1" should be "2030-01-08T09:00"

  Scenario: The Grade locks section is collapsed while no lock mode is saved
    Given I log in as "teacher1"
    When I am on the "C1" "tool_activitydates > dates" page
    Then "Lock grades" "field" should not be visible
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    And I press "Save and display"
    And I should see "Updated the gradebook lock date for 1 activities."
    When I am on the "C1" "tool_activitydates > dates" page
    Then "Lock grades" "field" should be visible
    And the field "Lock grades" matches value "At the end of this session"

  Scenario: An activity type without open and close dates offers only the lock date
    Given the following "activities" exist:
      | activity | name    | course | idnumber |
      | assign   | Assign1 | C1     | assign1  |
    And I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I set the field "modtype" to "Assignments"
    And I should see "Assign1" in the "table" "css_element"
    And I should not see "Close dates"
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Assign1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    When I press "Preview"
    Then the "timelock" date input of "Assign1" should be "2030-01-08T09:00"
    And "//tr[.//a[normalize-space(.)='Assign1']]//input[@data-field='timeopen']" "xpath_element" should not exist
    And "//tr[.//a[normalize-space(.)='Assign1']]//input[@data-field='timeclose']" "xpath_element" should not exist
    And I press "Save and display"
    And I should see "Updated the gradebook lock date for 1 activities."
    And I should not see "Updated dates for"
    And the grade lock date of "assign1" should be "2030-01-08T09:00"

  Scenario: The course-page note shows only on the activities ticked for it
    Given the following "activities" exist:
      | activity | name  | course | idnumber | completion |
      | quiz     | Quiz2 | C1     | quiz2    | 1          |
    And I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]   | 1                          |
      | schedulestart[month] | January                    |
      | schedulestart[year]  | 2030                       |
      | activitiespersession | 1                          |
      | Lock grades          | At the end of this session |
    # An unselected row without a saved note shows none; selecting it applies the site default (on).
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownote_')]" matches value "0"
    And I click on "selectall" "checkbox"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownote_')]" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownotecourse_')]" matches value "0"
    # The header box ticks the activity-page note of every selected row.
    And the "aria-label" attribute of "#id_togglenotes" "css_element" should contain "Activity page note of all selected activities"
    And the "aria-label" attribute of "#id_togglecoursenotes" "css_element" should contain "Course page note of all selected activities"
    And I click on "#id_togglenotes" "css_element"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownote_')]" matches value "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownotecourse_')]" to "1"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownotecourse_')]" to "0"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 2 activities."
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_shownotecourse_')]" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'id_shownotecourse_')]" matches value "0"
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Grades lock after" in the "Quiz1" "activity"
    And I should not see "Grades lock after" in the "Quiz2" "activity"
    # Quiz2's activity-page note is still on.
    And I am on the "Quiz2" "quiz activity" page
    And I should see "Grades lock after"

  Scenario: Save straight after load changes no date, lock or Fix flag
    Given I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I set the following fields to these values:
      | schedulestart[day]    | 1                          |
      | schedulestart[month]  | January                    |
      | schedulestart[year]   | 2030                       |
      | schedulestart[hour]   | 09                         |
      | schedulestart[minute] | 00                         |
      | sessionlength         | 7                          |
      | activitiespersession  | 1                          |
      | Close dates           | At the end of this session |
      | Lock grades           | At the end of this session |
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'id_cmid_')]" to "1"
    And I press "Preview"
    # Values that differ from the proposals, so a Save of the proposals would change them.
    And I set the "timeopen" date of "Quiz1" to "2030-01-03T10:30"
    And I set the "timelock" date of "Quiz1" to "2030-02-01T12:15"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timelock_')]" to "1"
    And I press "Save and display"
    And the "timeopen" of "quiz1" should be "2030-01-03T10:30"
    And the "timeclose" of "quiz1" should be "2030-01-08T09:00"
    And the grade lock date of "quiz1" should be "2030-02-01T12:15"
    When I am on the "C1" "tool_activitydates > dates" page
    # The table shows the current values, not the proposals.
    Then the "timeopen" date input of "Quiz1" should be "2030-01-03T10:30"
    And the "timelock" date input of "Quiz1" should be "2030-02-01T12:15"
    And I press "Save and display"
    And I should see "Updated the gradebook lock date for 1 activities."
    And I should not see "Nothing was saved"
    And the "timeopen" of "quiz1" should be "2030-01-03T10:30"
    And the "timeclose" of "quiz1" should be "2030-01-08T09:00"
    And the grade lock date of "quiz1" should be "2030-02-01T12:15"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timelock_')]" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timeopen_')]" matches value "0"
