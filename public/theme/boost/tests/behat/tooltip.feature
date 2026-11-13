@theme_boost @javascript
Feature: Hovering over tooltips
  In order to read a tooltip when I need to move the pointer to see it
  As a user
  I need the tooltip to stay shown while the pointer is on it

  Background:
    Given I keep block drawer closed
    And I log in as "admin"
    And I follow "Dashboard"

  Scenario: A tooltip stays shown while the pointer is on it and hides when the pointer leaves
    When I hover "Open block drawer" "button"
    And ".tooltip" "css_element" should be visible
    And I hover ".tooltip" "css_element"
    Then ".tooltip" "css_element" should be visible
    And I should see "Open block drawer" in the ".tooltip" "css_element"
    And I hover "h1" "css_element"
    And ".tooltip" "css_element" should not exist

  Scenario: A tooltip that the pointer is on can be dismissed with the Escape key
    When I hover "Open block drawer" "button"
    And I hover ".tooltip" "css_element"
    And ".tooltip" "css_element" should be visible
    And I press the escape key
    Then ".tooltip" "css_element" should not exist
