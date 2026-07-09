@tiny @tiny_authory_tech @tiny_authory_tech_dashboard
Feature: Teacher writing analytics dashboard
  As a teacher
  I need to view the Authory writing analytics dashboard for an assignment
  So that I can monitor student writing behaviour

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name            | course | idnumber |
      | assign   | Test Assignment | C1     | assign1  |
    And authory_tech is enabled for course "C1"

  @javascript
  Scenario: Teacher can open the writing analytics dashboard
    Given I log in as "teacher1"
    When I am on the writing dashboard for "assign1"
    Then the writing dashboard should show "Test Assignment"
    And I should see "Total Students"
    And I should see "Avg Typing Speed"

  @javascript
  Scenario: Dashboard displays the overview stats section
    Given I log in as "teacher1"
    When I am on the writing dashboard for "assign1"
    Then I should see "Overview"
    And I should see "Avg Typing Speed"

  @javascript
  Scenario: Student is denied access to the writing dashboard
    Given I log in as "student1"
    When I am on the writing dashboard for "assign1"
    Then I should see "Access denied"

  @javascript
  Scenario: Guest user cannot access the writing dashboard
    Given I am on the writing dashboard for "assign1"
    Then I should see "You are not logged in"

  @javascript
  Scenario: Teacher sees the dashboard button on the assignment view page
    Given I log in as "teacher1"
    And I am on the "Test Assignment" "assign activity" page
    Then I should see "View Authory Writing Dashboard"

  @javascript
  Scenario: Clicking the dashboard button navigates to the dashboard
    Given I log in as "teacher1"
    And I am on the "Test Assignment" "assign activity" page
    When I follow "View Authory Writing Dashboard"
    Then the writing dashboard should show "Test Assignment"
