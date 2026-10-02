@tool @tool_activitydates @javascript
Feature: Save, load and delete configurations, and filter the table by name
  In order to reuse a schedule and work on some activities at a time
  As a teacher
  I need to save the page under a name, load it back later, delete it,
  and filter the table by activity name

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

  Scenario: A saved configuration loads the settings, ticks, dates and Hold ticks back
    Given I set the field "sessionlength" to "3"
    And I press "Preview"
    And I set the "timeopen" date of "Quiz1" to "2030-01-01T10:30"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" to "1"
    And I click on "//tr[.//a[normalize-space(.)='Quiz4']]//input[@name='select']" "xpath_element"
    And I press "Preview"
    And I set the "timeopen" date of "Quiz1" to "2030-01-01T10:30"
    And I expand all fieldsets
    And I set the field "Configuration name" to "Week plan"
    When I press "Save configuration"
    Then I should see "Configuration \"Week plan\" saved."
    # The list shows the configuration just saved, on the same page.
    And I expand all fieldsets
    And "Week plan" "table_row" should exist
    # Saving a configuration re-shows the page as it was and writes nothing.
    And the "timeopen" date input of "Quiz1" should be "2030-01-01T10:30"
    And the "timeopen" of "quiz1" should be "0"
    When I am on the "C1" "tool_activitydates > dates" page
    And I set the field "sessionlength" to "5"
    And I expand all fieldsets
    And I click on "Load" "link" in the "Week plan" "table_row"
    Then I should see "Configuration \"Week plan\" loaded."
    And the field "sessionlength" matches value "3"
    And the "timeopen" date input of "Quiz1" should be "2030-01-01T10:30"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timeclose_')]" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[@name='select']" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz4']]//input[@name='select']" matches value "0"
    And I expand all fieldsets
    And the field "Configuration name" matches value "Week plan"
    # Loading wrote nothing; Save applies it.
    And the "timeopen" of "quiz1" should be "0"
    And "Settings changed. Press Preview to update the dates." "text" should not be visible
    When I press "Save and display"
    Then I should see "Updated dates for 3 of"
    And the "timeopen" of "quiz1" should be "2030-01-01T10:30"
    And the "timeopen" of "quiz4" should be "0"

  Scenario: Saving under an existing name replaces it, and a configuration can be deleted
    Given I expand all fieldsets
    And I set the field "Configuration name" to "Old plan"
    And I press "Save configuration"
    And I expand all fieldsets
    And I set the field "Configuration name" to "Old plan"
    When I press "Save configuration"
    Then I should see "Configuration \"Old plan\" replaced."
    And I expand all fieldsets
    When I click on "Delete" "link" in the "Old plan" "table_row"
    Then I should see "Delete the configuration \"Old plan\"?"
    And I press "Continue"
    And I should see "Configuration \"Old plan\" deleted."
    And I expand all fieldsets
    And I should see "No saved configurations"

  Scenario: A configuration loads after its activities were deleted or added
    Given I set the "timeopen" date of "Quiz1" to "2030-01-01T10:30"
    And I set the field with xpath "//tr[.//a[normalize-space(.)='Quiz2']]//input[starts-with(@id, 'tool_activitydates_fix_timeopen_')]" to "1"
    And I expand all fieldsets
    And I set the field "Configuration name" to "Before changes"
    And I press "Save configuration"
    And the activity "quiz2" is deleted
    And the following "activities" exist:
      | activity | name  | course | idnumber |
      | quiz     | Quiz5 | C1     | quiz5    |
    When I am on the "C1" "tool_activitydates > dates" page
    And I expand all fieldsets
    And I click on "Load" "link" in the "Before changes" "table_row"
    Then I should see "1 activities in this configuration no longer exist and were left out."
    And "Quiz2" "link" should not exist
    And the "timeopen" date input of "Quiz1" should be "2030-01-01T10:30"
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz3']]//input[@name='select']" matches value "1"
    # The activity added after saving loads unticked.
    And the field with xpath "//tr[.//a[normalize-space(.)='Quiz5']]//input[@name='select']" matches value "0"
    When I press "Save and display"
    Then I should see "Updated dates for 3 of"
    And the "timeopen" of "quiz1" should be "2030-01-01T10:30"
    And the "timeopen" of "quiz5" should be "0"

  Scenario: The name filter shows matching rows only, and select-all ticks only those
    Given the following "activities" exist:
      | activity | name           | course | idnumber |
      | quiz     | Reading quiz A | C1     | reada    |
      | quiz     | Reading quiz B | C1     | readb    |
      | quiz     | Listening quiz | C1     | listen   |
    And I am on the "C1" "tool_activitydates > dates" page
    When I set the field "Filter by name" to "reading"
    Then "Reading quiz A" "table_row" should be visible
    And "Reading quiz B" "table_row" should be visible
    And "Listening quiz" "table_row" should not be visible
    And "Quiz1" "table_row" should not be visible
    When I click on "selectall" "checkbox"
    Then the field with xpath "//tr[.//a[normalize-space(.)='Reading quiz A']]//input[@name='select']" matches value "1"
    And the field with xpath "//tr[.//a[normalize-space(.)='Reading quiz B']]//input[@name='select']" matches value "1"
    When I set the field "Filter by name" to ""
    Then "Listening quiz" "table_row" should be visible
    And the field with xpath "//tr[.//a[normalize-space(.)='Listening quiz']]//input[@name='select']" matches value "0"
    And the field "selectall" matches value "0"
    When I set the field "Filter by name" to "zzz"
    Then I should see "No activities match the filter."
