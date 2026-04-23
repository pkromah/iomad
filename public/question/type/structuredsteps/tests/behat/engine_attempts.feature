@qtype @qtype_structuredsteps
Feature: Engine-specific student attempt journeys
  As a student
  In order to solve domain-specific structured questions
  I need stoichiometry and ledger_poa quiz attempts to grade deterministically

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
      | activity | name                 | intro                          | course | idnumber    | grade | navmethod |
      | quiz     | Stoichiometry Quiz 1 | Stoichiometry quiz for QSS-030 | C1     | stoichquiz1 | 3     | free      |
      | quiz     | Ledger Quiz 1        | Ledger POA quiz for QSS-030    | C1     | ledgerquiz1 | 3     | free      |

  @javascript
  Scenario: Student completes a stoichiometry structuredsteps quiz question
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-stoich-attempt-001   |
      | Question text | Balance and solve stoichiometry steps |
      | Default mark  | 3                                     |
      | Engine        | stoichiometry                         |
      | Model JSON    | {"schema_version":"1.0","engine":"stoichiometry","engine_version":"1.0","params":{"mode":"mass_to_mass","equation":"2H2 + O2 -> 2H2O","tolerance":0.01,"expected_unit":"g","allow_fractional_coefficients":false},"steps":[{"id":"balance","label":"Balance equation","type":"conceptual","marks":1},{"id":"ratio","label":"Apply ratio","type":"conceptual","marks":1},{"id":"result","label":"Final mass","type":"computational","marks":1}],"fields":[{"id":"coef_h2","step_id":"balance","type":"digitbox","expected":"2","marks":1,"role":"coefficient"},{"id":"ratio_numerator","step_id":"ratio","type":"numberbox","expected":"2","marks":1,"role":"ratio"},{"id":"mass_target","step_id":"result","type":"numberbox","expected":"36","marks":1,"role":"conversion"}],"grading":{"mode":"field_sum","expected_response":""},"feedback_rules":[]} |
    And quiz "Stoichiometry Quiz 1" contains the following questions:
      | question                           | page | maxmark |
      | structuredsteps-stoich-attempt-001 | 1   | 3.0     |
    And I log out
    And I am on the "Stoichiometry Quiz 1" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//input[@data-ss-field-id='coef_h2']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='coef_h2']" to "2"
    And I set the field with xpath "//input[@data-ss-field-id='ratio_numerator']" to "2"
    And I set the field with xpath "//input[@data-ss-field-id='mass_target']" to "36"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "3.00 out of 3.00"

  @javascript
  Scenario: Student completes a ledger_poa structuredsteps quiz question
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-ledger-attempt-001   |
      | Question text | Post entries using ledger POA steps   |
      | Default mark  | 3                                     |
      | Engine        | ledger_poa                            |
      | Model JSON    | {"schema_version":"1.0","engine":"ledger_poa","engine_version":"1.0","params":{"mode":"t_account","decimal_places":2},"steps":[{"id":"poa_step1","label":"Entry","type":"conceptual","marks":3}],"fields":[{"id":"entry_side","step_id":"poa_step1","type":"choice","expected":"debit","marks":1},{"id":"entry_amount","step_id":"poa_step1","type":"numberbox","expected":"500","marks":1,"tolerance":0},{"id":"entry_narration","step_id":"poa_step1","type":"text","expected":"Cash received","marks":1}],"grading":{"mode":"field_sum","expected_response":""},"feedback_rules":[]} |
    And quiz "Ledger Quiz 1" contains the following questions:
      | question                           | page | maxmark |
      | structuredsteps-ledger-attempt-001 | 1   | 3.0     |
    And I log out
    And I am on the "Ledger Quiz 1" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//input[@data-ss-field-id='entry_side']" "xpath_element" should exist
    And I set the field with xpath "//input[@data-ss-field-id='entry_side']" to "debit"
    And I set the field with xpath "//input[@data-ss-field-id='entry_amount']" to "500"
    And I set the field with xpath "//textarea[@data-ss-field-id='entry_narration']" to "Cash received"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "3.00 out of 3.00"

  @javascript
  Scenario: Student completes an evidence_table structuredsteps quiz question
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-evidence-attempt-001 |
      | Question text | Build answer and evidence reasoning    |
      | Default mark  | 3                                     |
      | Engine        | evidence_table                        |
      | Model JSON    | {"schema_version":"1.0","engine":"evidence_table","engine_version":"1.0","params":{"mode":"answer_evidence","expected_answer_keywords":["lonely","isolated","alone"],"expected_evidence_keywords":["no friends","nobody spoke","empty room"],"require_explanation":true,"explanation_min_length":20,"max_length":150},"steps":[{"id":"answer_step","label":"Answer","type":"conceptual","marks":1},{"id":"evidence_step","label":"Evidence","type":"justification","marks":1},{"id":"explanation_step","label":"Explanation","type":"justification","marks":1}],"fields":[{"id":"answer","step_id":"answer_step","type":"structured_text","marks":1,"role":"answer"},{"id":"evidence","step_id":"evidence_step","type":"structured_text","marks":1,"role":"evidence"},{"id":"explanation","step_id":"explanation_step","type":"structured_text","marks":1,"role":"explanation"}],"grading":{"mode":"field_sum","expected_response":""},"feedback_rules":[]} |
    And quiz "Stoichiometry Quiz 1" contains the following questions:
      | question                              | page | maxmark |
      | structuredsteps-evidence-attempt-001 | 1    | 3.0     |
    And I log out
    And I am on the "Stoichiometry Quiz 1" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//textarea[@data-ss-field-id='answer']" "xpath_element" should exist
    And I set the field with xpath "//textarea[@data-ss-field-id='answer']" to "The character feels lonely."
    And I set the field with xpath "//textarea[@data-ss-field-id='evidence']" to "In the scene, the empty room shows isolation."
    And I set the field with xpath "//textarea[@data-ss-field-id='explanation']" to "This shows loneliness because no one offers support in that moment."
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "3.00 out of 3.00"

  @javascript
  Scenario: Student completes a graphing structuredsteps quiz question
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-graphing-attempt-001 |
      | Question text | Plot points and calculate gradient     |
      | Default mark  | 3                                     |
      | Engine        | graphing                              |
      | Model JSON    | {"schema_version":"1.0","engine":"graphing","engine_version":"1.0","params":{"mode":"straight_line","tolerance":0.1,"require_gradient":true},"steps":[{"id":"plot_step","label":"Plot points","type":"procedural","marks":2},{"id":"grad_step","label":"Gradient","type":"computational","marks":1}],"fields":[{"id":"p1","step_id":"plot_step","type":"graphplot","marks":1,"role":"point","expected_x":1,"expected_y":2},{"id":"p2","step_id":"plot_step","type":"graphplot","marks":1,"role":"point","expected_x":2,"expected_y":4},{"id":"gradient","step_id":"grad_step","type":"numberbox","marks":1,"role":"gradient","expected":"2","tolerance":0.1}],"grading":{"mode":"field_sum","expected_response":""},"feedback_rules":[]} |
    And quiz "Ledger Quiz 1" contains the following questions:
      | question                              | page | maxmark |
      | structuredsteps-graphing-attempt-001 | 1    | 3.0     |
    And I log out
    And I am on the "Ledger Quiz 1" "mod_quiz > View" page logged in as "student"
    And I press "Attempt quiz"
    Then "//textarea[@data-ss-field-id='p1']" "xpath_element" should exist
    And I set the field with xpath "//textarea[@data-ss-field-id='p1']" to "{\"points\":[{\"x\":1,\"y\":2},{\"x\":2,\"y\":4}]}"
    And I set the field with xpath "//textarea[@data-ss-field-id='p2']" to "{\"points\":[{\"x\":1,\"y\":2},{\"x\":2,\"y\":4}]}"
    And I set the field with xpath "//input[@data-ss-field-id='gradient']" to "2"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Submit all your answers and finish?" "dialogue"
    Then I should see "3.00 out of 3.00"
