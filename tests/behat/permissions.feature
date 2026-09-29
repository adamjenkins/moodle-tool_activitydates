@tool @tool_activitydates @tool_activitydates_permissions
Feature: The Activity dates page shows and saves only what the user may change
  In order to let admins separate date scheduling from grade locking
  As an administrator
  I need the page to offer the date settings only with the manage capability
  and the Grade locks section only with the managelocks capability

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
      | activity | name  | course | idnumber | grade |
      | quiz     | Quiz1 | C1     | quiz1    | 10    |

  Scenario: A teacher with only manage sees no Grade locks section and no Locked column
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | tool/activitydates:managelocks | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on the "C1" "tool_activitydates > dates" page
    Then I should see "Activity dates for C1"
    And I should see "Close dates"
    And I should not see "Grade locks" in the "region-main" "region"
    And "Lock grades" "field" should not exist
    And I should not see "Locked" in the "region-main" "region"
    And I should not see "Grade-lock note" in the "region-main" "region"
    And "//input[starts-with(@id, 'tool_activitydates_fix_timelock_')]" "xpath_element" should not exist

  @javascript
  Scenario: A teacher with only managelocks sees no date settings and can save a lock
    Given the following "permission overrides" exist:
      | capability                | permission | role           | contextlevel | reference |
      | tool/activitydates:manage | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on the "C1" "tool_activitydates > dates" page
    Then I should see "Activity dates for C1"
    And I should not see "Close dates"
    And "Close dates" "field" should not exist
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
    # Open and close are shown disabled, with no Hold box to tick: only the lock date is editable.
    And the "//tr[.//a[normalize-space(.)='Quiz1']]//input[@data-field='timeopen']" "xpath_element" should be disabled
    And the "//tr[.//a[normalize-space(.)='Quiz1']]//input[@data-field='timeclose']" "xpath_element" should be disabled
    And the "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timeopen_')]" "xpath_element" should be disabled
    And the "//tr[.//a[normalize-space(.)='Quiz1']]//input[starts-with(@id, 'tool_activitydates_fix_timelock_')]" "xpath_element" should be enabled
    And the "timelock" date input of "Quiz1" should be "2030-01-08T09:00"
    And I press "Save and display"
    Then I should see "Updated the gradebook lock date for 1 activities."
    And I should not see "Updated dates for"
    And the grade lock date of "quiz1" should be "2030-01-08T09:00"
    And the "timeopen" of "quiz1" should be "0"
    And the "timeclose" of "quiz1" should be "0"

  Scenario: A teacher with neither capability is refused
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | tool/activitydates:manage      | Prohibit   | editingteacher | Course       | C1        |
      | tool/activitydates:managelocks | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    Then I should be refused access to the "C1" "tool_activitydates > dates" page

  Scenario: The former Grade locks page redirects to the Activity dates page
    Given I log in as "teacher1"
    When I open the former Grade locks page of "C1"
    Then the url should match "/admin/tool/activitydates/view\.php\?courseid=[0-9]+$"
    And I should see "Activity dates for C1"
    And I should see "Grade locks"
