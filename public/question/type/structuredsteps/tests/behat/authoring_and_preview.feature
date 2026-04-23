@qtype @qtype_structuredsteps
Feature: Author and preview Structured Steps questions
  As a teacher
  In order to assess structured problem-solving
  I need to create and preview Structured Steps questions

  Background:
    Given the following "users" exist:
      | username |
      | teacher  |
      | student  |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | teacher | C1     | editingteacher |
      | student | C1     | student        |
    And the following "activities" exist:
      | activity | name   | intro            | course | idnumber | grade | navmethod |
      | quiz     | Quiz 1 | Quiz 1 for QSS23 | C1     | quiz1    | 2     | free      |

  Scenario: Create and re-open a Structured Steps question in the question bank
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-001                     |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    Then I should see "structuredsteps-001"
    And I choose "Edit question" action for "structuredsteps-001" in the question bank
    And the following fields match these values:
      | Question name | structuredsteps-001 |
      | Engine        | long_multiplication |

  Scenario: Preview runtime widgets and submit a correct response
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-preview-001             |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    And I am on the "structuredsteps-preview-001" "core_question > preview" page
    And I expand all fieldsets
    And I set the field "How questions behave" to "Immediate feedback"
    And I press "Save preview options and start again"
    Then "//div[contains(@class, 'ss-question')]" "xpath_element" should exist
    And "//input[@data-ss-field-id='s1_c1']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='s1_c1']" to "318"
    And I set the field with xpath "//input[@data-ss-field-id='s2_c1']" to "1060"
    And I press "Check"
    Then I should see "Correct (1/1)"

  @javascript
  Scenario: Student attempts structuredsteps question through quiz flow
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-attempt-001             |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    And quiz "Quiz 1" contains the following questions:
      | question                    | page | maxmark |
      | structuredsteps-attempt-001 | 1    | 2.0     |
    And I log out
    And I am on the "Quiz 1" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then I should see "Calculate 53 x 6 using structured steps"
    And "//input[@data-ss-field-id='s1_c1']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='s1_c1']" to "318"
    And I set the field with xpath "//input[@data-ss-field-id='s2_c1']" to "1060"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "2.00 out of 2.00"
