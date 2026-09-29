@tool @tool_activitydates @javascript
Feature: Preview and edit activity dates before saving
  In order to check and adjust a schedule before it is applied
  As a teacher
  I need Preview to fill an editable date table without saving anything,
  and Save to write exactly the dates in the table

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name  | course | idnumber |
      | quiz     | Quiz1 | C1     | quiz1    |
      | quiz     | Quiz2 | C1     | quiz2    |
      | quiz     | Quiz3 | C1     | quiz3    |
      | quiz     | Quiz4 | C1     | quiz4    |
    And I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > dates" page
    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "schedulestart[hour]" to "09"
    And I set the field "schedulestart[minute]" to "00"
    And I set the field "schedulefinish[enabled]" to "0"
    And I set the field "sessionlength" to "7"
    And I set the field "activitiespersession" to "2"
    And I set the field "Close dates" to "At the end of this session"
    And I click on "selectall" "checkbox"
    And I press "Preview"

  Scenario: Preview fills the table and writes nothing
    # Session 1 = Quiz1 + Quiz2 from 1 Jan, session 2 = Quiz3 + Quiz4 from 8 Jan;
    # a session's activities close when the next session opens.
    Then the "timeopen" date input of "Quiz1" should be "2030-01-01T09:00"
    And the "timeclose" date input of "Quiz1" should be "2030-01-08T09:00"
    And the "timeopen" date input of "Quiz3" should be "2030-01-08T09:00"
    And the "timeclose" date input of "Quiz3" should be "2030-01-15T09:00"
    And "Settings changed. Press Preview to update the dates." "text" should not be visible
    And the "timeopen" of "quiz1" should be "0"
    And the "timeclose" of "quiz1" should be "0"
    And the "timeopen" of "quiz3" should be "0"

  Scenario: An edited date is saved exactly
    When I set the "timeopen" date of "Quiz2" to "2030-01-03T10:30"
    And I press "Save and display"
    Then I should see "Updated dates for 4 of"
    And the "timeopen" of "quiz2" should be "2030-01-03T10:30"
    And the "timeclose" of "quiz2" should be "2030-01-08T09:00"
    And the "timeopen" of "quiz1" should be "2030-01-01T09:00"
    And the "timeclose" of "quiz1" should be "2030-01-08T09:00"
    And the "timeopen" of "quiz4" should be "2030-01-08T09:00"

  Scenario: A cleared open date is saved as not set and the close date is kept
    When I set the "timeopen" date of "Quiz1" to ""
    # The close date has no open date to be ordered against.
    Then I should not see "The close date must be after the open date." in the "Quiz1" "table_row"
    And I press "Save and display"
    Then I should see "Updated dates for 4 of"
    And I should not see "Nothing was saved"
    And the "timeopen" of "quiz1" should be "0"
    And the "timeclose" of "quiz1" should be "2030-01-08T09:00"
    And the "timeopen" of "quiz2" should be "2030-01-01T09:00"

  Scenario: Changing a setting after Preview marks the table stale and disables Save
    Given "Settings changed. Press Preview to update the dates." "text" should not be visible
    And the "Save and display" "button" should be enabled
    When I set the field "sessionlength" to "3"
    Then I should see "Settings changed. Press Preview to update the dates."
    And the "Save and display" "button" should be disabled
    And the "Save and return to course" "button" should be disabled
    # Going back to the previewed value makes the table current again.
    And I set the field "sessionlength" to "7"
    And "Settings changed. Press Preview to update the dates." "text" should not be visible
    And the "Save and display" "button" should be enabled

  Scenario: The server refuses a table built from other settings
    When I set the field "sessionlength" to "3"
    And I remove the disabled attribute from the "Save and display" button
    And I press "Save and display"
    Then I should see "Nothing was saved: the settings changed"
    And the "timeopen" of "quiz1" should be "0"
    And the "timeopen" of "quiz3" should be "0"
    # The table is recalculated from the submitted settings.
    And the "timeopen" date input of "Quiz3" should be "2030-01-04T09:00"

  Scenario: An invalid row blocks Save and keeps the edits
    When I set the "timeclose" date of "Quiz1" to "2029-12-31T09:00"
    Then I should see "The close date must be after the open date." in the "Quiz1" "table_row"
    And the "aria-invalid" attribute of "//tr[.//a[normalize-space(.)='Quiz1']]//input[@data-field='timeclose']" "xpath_element" should contain "true"
    And I press "Save and display"
    Then I should see "Nothing was saved: 1 date(s) need correcting"
    And I should see "The close date must be after the open date." in the "Quiz1" "table_row"
    And the "timeclose" date input of "Quiz1" should be "2029-12-31T09:00"
    And the "timeopen" of "quiz1" should be "0"
    And the "timeclose" of "quiz1" should be "0"
    And the "timeopen" of "quiz2" should be "0"

  Scenario: Save straight after loading saved settings succeeds without Preview
    Given I press "Save and display"
    And I should see "Updated dates for 4 of"
    When I am on the "C1" "tool_activitydates > dates" page
    And I press "Save and display"
    Then I should see "Updated dates for 4 of"
    And I should not see "Nothing was saved"
    And the "timeopen" of "quiz1" should be "2030-01-01T09:00"
    And the "timeopen" of "quiz3" should be "2030-01-08T09:00"

  Scenario: Due dates are not offered where the activity table has no due date column
    Given the "quiz" activity table has no due date column
    Then "Due dates" "field" should not exist
    And "//tr[.//a[normalize-space(.)='Quiz1']]//input[@data-field='duedate']" "xpath_element" should not exist
    And the "timeopen" date input of "Quiz1" should be "2030-01-01T09:00"

  Scenario: A due date after the close date blocks Save
    Given the "quiz" activity table has a due date column
    And I set the field "Due dates" to "After a number of days"
    And I set the field "Days until due" to "10"
    And I press "Preview"
    # Due = open + 10 days, after the session's close (open + 7 days).
    And the "duedate" date input of "Quiz1" should be "2030-01-11T09:00"
    # Preview alone flags the proposal.
    Then I should see "The due date cannot be after the close date." in the "Quiz1" "table_row"
    And I press "Save and display"
    And I should see "Nothing was saved: 4 date(s) need correcting"
    And I should see "The due date cannot be after the close date." in the "Quiz1" "table_row"
    And the "timeopen" of "quiz1" should be "0"
    And the "duedate" of "quiz1" should be "0"
    When I set the field "Days until due" to "3"
    And I press "Preview"
    And I press "Save and display"
    Then I should see "Updated dates for 4 of"
    And the "duedate" of "quiz1" should be "2030-01-04T09:00"
    And the "timeclose" of "quiz1" should be "2030-01-08T09:00"
    And the "duedate" of "quiz3" should be "2030-01-11T09:00"

  Scenario: Preview flags a proposed date that breaks a row rule
    # One close date for all: session 2 opens on 8 Jan, after it.
    When I set the field "Close dates" to "All on a date"
    And I set the field "closedate[day]" to "5"
    And I set the field "closedate[month]" to "January"
    And I set the field "closedate[year]" to "2030"
    And I set the field "closedate[hour]" to "09"
    And I set the field "closedate[minute]" to "00"
    And I press "Preview"
    Then the "timeclose" date input of "Quiz3" should be "2030-01-05T09:00"
    And I should see "The close date must be after the open date." in the "Quiz3" "table_row"
    And I should not see "The close date must be after the open date." in the "Quiz1" "table_row"

  Scenario: Enter in a table date keeps the edits and does not submit
    When I set the "timeopen" date of "Quiz2" to "2030-01-03T10:30"
    And I focus the "timeopen" date of "Quiz2"
    And I press the enter key
    Then the "timeopen" date input of "Quiz2" should be "2030-01-03T10:30"
    And I press "Save and display"
    And I should see "Updated dates for 4 of"
    And the "timeopen" of "quiz2" should be "2030-01-03T10:30"

  Scenario: A date only the server rejects keeps its hint while other dates are edited
    When I set the "timeopen" date of "Quiz1" to "1969-12-31T09:00"
    And I press "Save and display"
    Then I should see "Nothing was saved: 1 date(s) need correcting"
    And I should see "Enter a valid date and time." in the "Quiz1" "table_row"
    When I set the "timeclose" date of "Quiz1" to "2030-01-09T09:00"
    Then I should see "Enter a valid date and time." in the "Quiz1" "table_row"
    And the "timeopen" of "quiz1" should be "0"

  Scenario: Save straight after load succeeds when a saved activity was deleted
    Given I press "Save and display"
    And I should see "Updated dates for 4 of"
    And I am on "Course 1" course homepage with editing mode on
    And I delete "Quiz4" activity
    When I am on the "C1" "tool_activitydates > dates" page
    And I press "Save and display"
    Then I should see "Updated dates for 3 of"
    And I should not see "Nothing was saved"

  Scenario: The table loads with the current dates, and an unset date is empty
    Given I set the "timeclose" date of "Quiz2" to ""
    And I press "Save and display"
    And I should see "Updated dates for 4 of"
    And the "timeclose" of "quiz2" should be "0"
    When I am on the "C1" "tool_activitydates > dates" page
    # The proposal for Quiz2's close is 8 Jan; the table shows the current, unset value.
    Then the "timeclose" date input of "Quiz2" should be ""
    And the "timeopen" date input of "Quiz2" should be "2030-01-01T09:00"
    And the "timeopen" date input of "Quiz1" should be "2030-01-01T09:00"
    And the "timeclose" date input of "Quiz1" should be "2030-01-08T09:00"
    And the "timeopen" date input of "Quiz3" should be "2030-01-08T09:00"

  Scenario: A held date is kept through Preview and saved with its Hold flag
    # Quiz3 is in session 2, so its open date moves when the session length changes.
    When I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" to "1"
    # Ticking Hold does not make the table stale.
    Then "Settings changed. Press Preview to update the dates." "text" should not be visible
    And I set the field "sessionlength" to "3"
    And I press "Preview"
    And the "timeopen" date input of "Quiz3" should be "2030-01-04T09:00"
    And the "timeclose" date input of "Quiz3" should be "2030-01-15T09:00"
    And the "timeclose" date input of "Quiz1" should be "2030-01-04T09:00"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" matches value "1"
    And I press "Save and display"
    And I should see "Updated dates for 4 of"
    And the "timeopen" of "quiz3" should be "2030-01-04T09:00"
    And the "timeclose" of "quiz3" should be "2030-01-15T09:00"
    And the "timeclose" of "quiz1" should be "2030-01-04T09:00"
    When I am on the "C1" "tool_activitydates > dates" page
    Then the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[starts-with(@id, 'tool_activitydates_fix_timeopen_')]" matches value "0"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" matches value "0"
    And the "timeclose" date input of "Quiz3" should be "2030-01-15T09:00"
    # A later Preview with other settings still keeps the held close.
    And I set the field "sessionlength" to "5"
    And I press "Preview"
    And the "timeopen" date input of "Quiz3" should be "2030-01-06T09:00"
    And the "timeclose" date input of "Quiz3" should be "2030-01-15T09:00"
