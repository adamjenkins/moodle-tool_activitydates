@tool @tool_activitydates
Feature: Bulk-schedule gradebook lock dates
  In order to lock gradebook grade items on a rolling schedule
  As a teacher
  I need to choose a schedule start, session length and activities per
  session, select activities, and have their gradebook lock dates applied

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
    And I follow "Grade locks"
    Then I should see "Grade locks for C1"
    And I should see "Quiz1"

    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "sessionlength" to "7"
    And I set the field "activitiespersession" to "5"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[1]/input[@type='checkbox']" to "1"
    And I press "Save and display"

    Then I should see "Updated the gradebook lock date for 1 activities."
    And I should see "8/01/30" in the "Quiz1" "table_row"

  @javascript
  Scenario: Student sees the grade-lock note on an activity with the note enabled
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Activity dates" in current page administration
    And I follow "Grade locks"
    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "sessionlength" to "7"
    And I set the field "activitiespersession" to "5"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[1]/input[@type='checkbox']" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[last()]/input[@type='checkbox']" to "1"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on the "Quiz1" "quiz activity" page
    Then I should see "Grades lock after"

  @javascript
  Scenario: Student sees the grade-lock note on the course page when the course opts in
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Activity dates" in current page administration
    And I follow "Grade locks"
    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "Also show notes on the course page" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[1]/input[@type='checkbox']" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[last()]/input[@type='checkbox']" to "1"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Grades lock after" in the "Quiz1" "activity"
    # At the bottom of the card, not squeezed into the completion column.
    And "[data-region='activity-card'] > .tool_activitydates-coursenote" "css_element" should exist in the "Quiz1" "activity"

  @javascript
  Scenario: The course-page note survives the course editor re-rendering its activity
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Activity dates" in current page administration
    And I follow "Grade locks"
    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "Also show notes on the course page" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[1]/input[@type='checkbox']" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[last()]/input[@type='checkbox']" to "1"
    And I press "Save and display"
    And I am on "Course 1" course homepage with editing mode on
    And I should see "Grades lock after" in the "Quiz1" "activity"
    When I indent right "Quiz1" activity
    Then I should see "Grades lock after" in the "Quiz1" "activity"

  @javascript
  Scenario: The course page shows no grade-lock note when the course has not opted in
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage
    And I navigate to "Activity dates" in current page administration
    And I follow "Grade locks"
    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "Also show notes on the course page" to "0"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[1]/input[@type='checkbox']" to "1"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'Quiz1')][1]/td[last()]/input[@type='checkbox']" to "1"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I log out

    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Quiz1"
    And I should not see "Grades lock after"
    And I am on the "Quiz1" "quiz activity" page
    And I should see "Grades lock after"

  @javascript
  Scenario: Changing the activity type refreshes the Grade locks table without pressing Refresh
    Given the following "activities" exist:
      | activity | name    | course | idnumber |
      | assign   | Assign1 | C1     | assign1  |
    And I log in as "teacher1"
    And I am on the "C1" "tool_activitydates > locks" page
    And I should see "Assign1" in the "table" "css_element"
    When I set the field "modtype" to "Quiz"
    Then I should see "Quiz1" in the "table" "css_element"
    And I should not see "Assign1" in the "table" "css_element"
