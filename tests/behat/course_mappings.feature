@local @local_completionhistory
Feature: Course Replacement Mappings page
  As a manager
  I want to manage course replacement mappings
  So that I can track which courses have been retired and replaced

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | manager1 | Test      | Manager  | manager1@example.com |
    And the following "system role assigns" exist:
      | user     | role    |
      | manager1 | manager |
    And the following "courses" exist:
      | fullname        | shortname | idnumber |
      | Old Course 101  | OC101     | OC101    |
      | New Course 201  | NC201     | NC201    |
    And the following config values are set as admin:
      | enabled | 1 | local_completionhistory |

  @javascript
  Scenario: Manager can access course mappings page
    Given I log in as "manager1"
    When I visit "/local/completionhistory/course_mappings.php"
    Then I should see "Course Replacement Mappings"
    And I should see "Add course mapping"

  @javascript
  Scenario: Manager can add a course mapping
    Given I log in as "manager1"
    And I visit "/local/completionhistory/course_mappings.php"
    When I click on "Add course mapping" "link"
    Then I should see "Old course"
    And I should see "New course"
