@qtype @qtype_structuredsteps @qtype_structuredsteps_psqc026
Feature: Student attempts a converted primary school Math question (SEA marking)
  As a student
  In order to practise SEA-style mathematics questions
  I need converted CLOZE Math questions to grade correctly under SEA marking rules

  # SEA marking: partial_credit_enabled=false, method_marks_enabled=false.
  # With a single numberbox field: correct answer = full marks (1/1), wrong = 0/1.
  # Engine: AlgorithmicWorkingEngine routed through step_calculation_engine runtime.

  Background:
    Given the following "users" exist:
      | username |
      | teacher  |
      | student  |
    And the following "courses" exist:
      | fullname         | shortname | category |
      | Primary Math SEA | PRIMMATH  | 0        |
    And the following "course enrolments" exist:
      | user    | course   | role           |
      | teacher | PRIMMATH | editingteacher |
      | student | PRIMMATH | student        |
    And the following "activities" exist:
      | activity | name              | intro                               | course   | idnumber   | grade | navmethod |
      | quiz     | SEA Math Quiz 001 | Primary school SEA Math test PSQC026 | PRIMMATH | seamathq1  | 1     | free      |

  @javascript
  Scenario: Correct answer scores full marks on a converted AlgorithmicWorkingEngine question
    # Mirrors output of cloze_generator for "Time 20103 Jr1" type question.
    # single NUMERICAL sub-part: {1:NUMERICAL:=60:0#Correct~*#Incorrect}
    # AlgorithmicWorkingEngine, 1 step, 1 numberbox field, tolerance=0, SEA marking.
    When I am on the "Primary Math SEA" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | psqc026-algo-time-001                                                                                                                                                                                                                                                                                                                       |
      | Question text | How many minutes are in 1 hour?                                                                                                                                                                                                                                                                                                             |
      | Default mark  | 1                                                                                                                                                                                                                                                                                                                                           |
      | Engine        | AlgorithmicWorkingEngine                                                                                                                                                                                                                                                                                                                    |
      | Model JSON    | {"schema_version":"1.0","engine":"AlgorithmicWorkingEngine","engine_version":"1.0","metadata":{"source":"cloze_conversion","sea_marking":true},"params":{"partial_credit_enabled":false,"method_marks_enabled":false},"grading":{"mode":"field_sum","partial_credit_enabled":false},"steps":[{"id":"step1","label":"Calculate","type":"computational","marks":1}],"fields":[{"id":"f1","step_id":"step1","type":"numberbox","expected":"60","tolerance":0,"marks":1.0}]} |
    And quiz "SEA Math Quiz 001" contains the following questions:
      | question               | page | maxmark |
      | psqc026-algo-time-001  | 1    | 1.0     |
    And I log out
    And I am on the "SEA Math Quiz 001" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//input[@data-ss-field-id='f1']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='f1']" to "60"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "1.00 out of 1.00"

  @javascript
  Scenario: Wrong answer scores 0 on a converted AlgorithmicWorkingEngine question
    # Same question structure as above. Wrong answer → 0/1.
    # Verifies SEA all-or-nothing marking at field level.
    When I am on the "Primary Math SEA" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | psqc026-algo-time-002                                                                                                                                                                                                                                                                                                                       |
      | Question text | How many minutes are in 1 hour? (wrong answer test)                                                                                                                                                                                                                                                                                         |
      | Default mark  | 1                                                                                                                                                                                                                                                                                                                                           |
      | Engine        | AlgorithmicWorkingEngine                                                                                                                                                                                                                                                                                                                    |
      | Model JSON    | {"schema_version":"1.0","engine":"AlgorithmicWorkingEngine","engine_version":"1.0","metadata":{"source":"cloze_conversion","sea_marking":true},"params":{"partial_credit_enabled":false,"method_marks_enabled":false},"grading":{"mode":"field_sum","partial_credit_enabled":false},"steps":[{"id":"step1","label":"Calculate","type":"computational","marks":1}],"fields":[{"id":"f1","step_id":"step1","type":"numberbox","expected":"60","tolerance":0,"marks":1.0}]} |
    And quiz "SEA Math Quiz 001" contains the following questions:
      | question               | page | maxmark |
      | psqc026-algo-time-002  | 1    | 1.0     |
    And I log out
    And I am on the "SEA Math Quiz 001" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//input[@data-ss-field-id='f1']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='f1']" to "45"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "0.00 out of 1.00"

  @javascript
  Scenario: Correct answer scores full marks on a converted StepCalculationEngine question
    # Mirrors output of cloze_generator for "AandP21502 dr" type question.
    # 2-step StepCalculationEngine question: length (8) then area (40).
    When I am on the "Primary Math SEA" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | psqc026-stepcalc-area-001                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
      | Question text | Calculate the area of a rectangle with length 8 m and width 5 m.                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
      | Default mark  | 2                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
      | Engine        | StepCalculationEngine                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
      | Model JSON    | {"schema_version":"1.0","engine":"StepCalculationEngine","engine_version":"1.0","metadata":{"source":"cloze_conversion","sea_marking":true},"params":{"partial_credit_enabled":false,"method_marks_enabled":false},"grading":{"mode":"field_sum","partial_credit_enabled":false},"steps":[{"id":"step1","label":"Step 1: Find the length","type":"computational","marks":1},{"id":"step2","label":"Step 2: Calculate the area","type":"computational","marks":1}],"fields":[{"id":"f1","step_id":"step1","type":"numberbox","expected":"8","tolerance":0,"marks":1.0},{"id":"f2","step_id":"step2","type":"numberbox","expected":"40","tolerance":0,"marks":1.0}]} |
    And quiz "SEA Math Quiz 001" contains the following questions:
      | question                  | page | maxmark |
      | psqc026-stepcalc-area-001 | 1    | 2.0     |
    And I log out
    And I am on the "SEA Math Quiz 001" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//input[@data-ss-field-id='f1']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='f1']" to "8"
    And I set the field with xpath "//input[@data-ss-field-id='f2']" to "40"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "2.00 out of 2.00"
