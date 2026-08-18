@local @local_completionhistory
Feature: My Achievements page
  As a student
  I want to view my course completion history
  So that I can see a permanent record of my achievements

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Test      | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname    | shortname | idnumber |
      | Test Course | TC101     | TC101    |
    And the following config values are set as admin:
      | enabled              | 1 | local_completionhistory |
      | enableuserachievements | 1 | local_completionhistory |

  @javascript
  Scenario: Student sees their achievements page
    Given I log in as "student1"
    When I visit "/local/completionhistory/my_achievements.php"
    Then I should see "My Achievements"

  @javascript
  Scenario: Student sees no achievements message when empty
    Given I log in as "student1"
    When I visit "/local/completionhistory/my_achievements.php"
    Then I should see "Nothing to display"
