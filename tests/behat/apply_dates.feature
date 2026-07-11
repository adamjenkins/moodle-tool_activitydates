@tool @tool_activitydates @javascript
Feature: Bulk-schedule activity dates
  In order to schedule a course's activities across a series of sessions
  As a teacher
  I need to choose an eligible activity type, a schedule window and session
  settings, select activities, and have their open/close dates applied

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | quiz     | Quiz1   | C1     | quiz1    |
      | quiz     | Quiz2   | C1     | quiz2    |
      | quiz     | Quiz3   | C1     | quiz3    |
      | quiz     | Quiz4   | C1     | quiz4    |
      | choice   | Choice1 | C1     | choice1  |
      | assign   | Assign1 | C1     | assign1  |

  Scenario: Teacher schedules quizzes across sessions
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I navigate to "Activity dates" in current page administration
    # Assign1 uses allowsubmissionsfromdate/duedate, not timeopen/timeclose,
    # so it must not be offered as an activity type.
    Then the "Activity type" select box should contain "Quizzes"
    And the "Activity type" select box should contain "Choices"
    And the "Activity type" select box should not contain "Assignments"

    # Choices sorts before Quizzes alphabetically and so may be the default
    # activity type; select Quizzes explicitly and refresh the table.
    And I set the field "Activity type" to "Quizzes"
    And I press "Refresh"

    And I set the field "schedulestart[day]" to "1"
    And I set the field "schedulestart[month]" to "January"
    And I set the field "schedulestart[year]" to "2030"
    And I set the field "schedulefinish[day]" to "15"
    And I set the field "schedulefinish[month]" to "January"
    And I set the field "schedulefinish[year]" to "2030"
    And I set the field "sessionlength" to "7"
    And I set the field "activitiespersession" to "2"

    And I click on "selectall" "checkbox"
    And I press "Save and display"

    # Session 1 = Quiz1 + Quiz2 starting 1 Jan 2030.
    # Session 2 = Quiz3 + Quiz4 starting 8 Jan 2030 (sessionlength=7).
    Then I should see "1 Jan 2030" in the "Quiz1" "table_row"
    And I should see "8 Jan 2030" in the "Quiz3" "table_row"

    # Confirm the write landed in the quiz's own settings, not just this
    # plugin's table.
    And I am on the "quiz1" "activity editing" page
    Then the field "timeopen[day]" matches value "1"

  Scenario: Validation errors when scheduling activities
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I navigate to "Activity dates" in current page administration
    And I set the field "Activity type" to "Quizzes"
    And I press "Refresh"

    # sessionlength must be >= 1: the client-side required rule blocks an
    # empty value before the form can even be submitted.
    And I set the field "sessionlength" to ""
    Then I should see "You must supply a value here"
    And I set the field "sessionlength" to "7"

    # activitiespersession must be <= count($modules): there are only 4
    # eligible quizzes, so 100 must be rejected by server-side validation.
    And I set the field "activitiespersession" to "100"
    And I press "Save and display"
    Then I should see "Activities per session is 100 but the course only has 4 eligible activities"
