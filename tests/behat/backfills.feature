@local @local_completionhistory
Feature: Backfills page
  As a site administrator with no command line
  I want to run the backfills from the browser
  So that a hosted site can be backfilled at all

  Background:
    Given the following "courses" exist:
      | fullname        | shortname | idnumber |
      | Course CL601    | CL601     | CL601    |
    And the following config values are set as admin:
      | enabled        | 1 | local_completionhistory |
      | capturegrades  | 1 | local_completionhistory |

  Scenario: A preview is queued straight away and cron finishes it
    Given I log in as "admin"
    And I visit "/local/completionhistory/backfill.php"
    And I should see "No runs yet."
    When I set the field "Refresh grades and pass flags" to "1"
    And I set the field "Course" to "CL601"
    And I press "Preview"
    Then I should see "Queued. It runs on the next cron run"
    And I should see "CL601"
    And I run all adhoc tasks
    And I press "Refresh"
    And I should see "Done"
    And I should see "scanned: 0"

  Scenario: A real run asks for confirmation first
    Given I log in as "admin"
    And I visit "/local/completionhistory/backfill.php"
    When I set the field "Missing completions" to "1"
    And I press "Run"
    Then I should see "for real?"
    And I press "Cancel"
    And I should see "No runs yet."

  Scenario: An unknown course is refused
    Given I log in as "admin"
    And I visit "/local/completionhistory/backfill.php"
    When I set the field "Course" to "NOPE999"
    And I press "Preview"
    Then I should see "No course has the ID number or short name"
