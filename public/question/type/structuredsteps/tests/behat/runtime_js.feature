@qtype @qtype_structuredsteps
Feature: Structured Steps runtime JavaScript behaviour
  As a teacher
  In order to verify runtime interactions
  I need structuredsteps JS modules to initialise and react to focus events

  Background:
    Given the following "users" exist:
      | username |
      | teacher  |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | teacher | C1     | editingteacher |

  @javascript
  Scenario: Runtime init flag and input dock visibility toggle
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-js-001                  |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    And I am on the "structuredsteps-js-001" "core_question > preview" page
    Then "//div[contains(@class, 'ss-question') and @data-ss-runtime-init='1']" "xpath_element" should exist
    And "//div[@data-ss-input-dock='1' and @hidden]" "xpath_element" should exist
    When I click on "//input[@data-ss-field-id='s1_c1']" "xpath_element"
    Then "//div[@data-ss-input-dock='1' and not(@hidden)]" "xpath_element" should exist

  @javascript
  Scenario: Layout token remains valid after desktop resize
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-js-grid-001             |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    And I am on the "structuredsteps-js-grid-001" "core_question > preview" page
    And I change viewport size to "1920x1080"
    Then "//div[contains(@class, 'ss-question') and (@data-ss-layout='grid' or @data-ss-layout='step_card')]" "xpath_element" should exist

  @javascript
  Scenario: Auto layout resolves to mobile step_card in narrow viewport
    Given I change viewport size to "640x768"
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-js-mobile-001           |
      | Question text | Calculate 53 x 6 using structured steps |
      | Default mark  | 2                                       |
      | Engine        | long_multiplication                     |
    And I am on the "structuredsteps-js-mobile-001" "core_question > preview" page
    Then "//div[contains(@class, 'ss-question') and @data-ss-layout='step_card']" "xpath_element" should exist

  @javascript
  Scenario: Forced grid layout mode is respected at runtime
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-js-forced-grid-001                                                                                                                                            |
      | Question text | Calculate 53 x 6 using structured steps                                                                                                                                         |
      | Default mark  | 2                                                                                                                                                                                |
      | Engine        | long_multiplication                                                                                                                                                              |
      | Model JSON    | {"schema_version":"1.0","engine":"long_multiplication","engine_version":"1.0","params":{"layout_mode":"grid"},"grading":{"mode":"field_sum","expected_response":""},"steps":[{"id":"step1","label":"Multiply by units","type":"computational","marks":1},{"id":"step2","label":"Multiply by tens","type":"computational","marks":1}],"fields":[{"id":"s1_c1","step_id":"step1","type":"numberbox","expected":"318","marks":1,"tolerance":0},{"id":"s2_c1","step_id":"step2","type":"numberbox","expected":"1060","marks":1,"tolerance":0}]} |
    And I am on the "structuredsteps-js-forced-grid-001" "core_question > preview" page
    Then "//div[contains(@class, 'ss-question') and @data-ss-layout='grid']" "xpath_element" should exist

  @javascript
  Scenario: Forced step_card layout mode is respected at runtime
    When I am on the "Course 1" "core_question > course question bank" page logged in as teacher
    And I add a "Structured Steps" question filling the form with:
      | Question name | structuredsteps-js-forced-card-001                                                                                                                                            |
      | Question text | Calculate 53 x 6 using structured steps                                                                                                                                         |
      | Default mark  | 2                                                                                                                                                                                |
      | Engine        | long_multiplication                                                                                                                                                              |
      | Model JSON    | {"schema_version":"1.0","engine":"long_multiplication","engine_version":"1.0","params":{"layout_mode":"step_card"},"grading":{"mode":"field_sum","expected_response":""},"steps":[{"id":"step1","label":"Multiply by units","type":"computational","marks":1},{"id":"step2","label":"Multiply by tens","type":"computational","marks":1}],"fields":[{"id":"s1_c1","step_id":"step1","type":"numberbox","expected":"318","marks":1,"tolerance":0},{"id":"s2_c1","step_id":"step2","type":"numberbox","expected":"1060","marks":1,"tolerance":0}]} |
    And I am on the "structuredsteps-js-forced-card-001" "core_question > preview" page
    Then "//div[contains(@class, 'ss-question') and @data-ss-layout='step_card']" "xpath_element" should exist
