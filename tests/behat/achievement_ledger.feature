@local_completionhistory
Feature: Achievement Ledger page
  As a manager
  I want to view and filter the achievement ledger
  So that I can review completion history across all users

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | manager1 | Test      | Manager  | manager1@example.com |
    And the following "system role assigns" exist:
      | user     | role    |
      | manager1 | manager |
    And the following config values are set as admin:
      | enabled | 1 | local_completionhistory |

  @javascript
  Scenario: Manager can access the achievement ledger
    Given I log in as "manager1"
    When I navigate to "Plugins > Local plugins > Achievement Ledger" in site administration
    Then I should see "Achievement Ledger"
