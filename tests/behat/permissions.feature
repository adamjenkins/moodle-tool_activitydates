@tool @tool_activitydates @tool_activitydates_permissions
Feature: Each tab is gated by its own capability
  In order to let admins separate date scheduling from grade locking
  As an administrator
  I need each tab to open only for users with its capability

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

  Scenario: A teacher with only managelocks lands on Grade locks
    Given the following "permission overrides" exist:
      | capability                | permission | role           | contextlevel | reference |
      | tool/activitydates:manage | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    When I am on the "C1" "tool_activitydates > dates" page
    Then I should see "Grade locks for C1"
    And I should not see "Activity dates for C1"
    And "Activity dates" "link" should not exist in the "region-main" "region"

  Scenario: A teacher without managelocks cannot open the Grade locks page
    Given the following "permission overrides" exist:
      | capability                     | permission | role           | contextlevel | reference |
      | tool/activitydates:managelocks | Prohibit   | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    Then I should be refused access to the "C1" "tool_activitydates > locks" page
