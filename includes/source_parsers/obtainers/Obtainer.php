<?php

/**
 * @file
 * Class Obtainer
 *
 * The Obtainer serves to find a target string within DOM markup. It will
 * iterate over a stack of finder methods until it finds the string that it is
 * looking for, at which point it returns the string.
 */

abstract class Obtainer {

  /**
   * @var object
   *   The QueryPath element to be tested.
   */
  protected $element;

  /**
   * @var QueryPath
   *   QueryPath object passed in at instantiation.
   */
  protected $queryPath;

  /**
   * @var array
   *   Array of find methods to call, in order. Passed in at instantiation.
   */
  protected $methodStack = array();

  /**
   * @var string
   *   The string obtained from the most recently run finder method.
   */
  protected $obtainedString;

  /**
   * Constructor for the Obtainer.
   *
   * @param object $query_path
   *   The query path object to use as the source of possible content.
   *
   * @param array $method_stack
   *   (optional). Array of find methods to run through.
   */
  public function __construct($query_path, $method_stack = array()) {
    $this->queryPath = $query_path;
    $this->setMethodStack($method_stack);
    $this->obtain();
  }

  /**
   * Sets the $methodStack property, checking that each method is valid.
   *
   * @param array $method_stack
   *   The stack of methods to be run.
   */
  public function setMethodStack($method_stack) {
    foreach ($method_stack as $key => $method) {
      if (!method_exists($this, $method)) {
        unset($method_stack[$key]);
        $obtainer_class = get_class($this);
        drush_doj_migration_debug_output("The target method '{$obtainer_class}' in {$obtainer_class} does not exist and was skipped.");
      }
    }
  }

  /**
   * Sets $this->element.
   *
   * @param object $element
   *   The QueryPath element to be removed from source if string is obtained.
   */
  public function setElementToRemove($element) {
    $this->element = $element;
  }

  /**
   * Calls remove() on the currently set QueryPath element.
   */
  public function removeElement() {
    if (!empty($this->element)) {
      $this->element->remove();
    }
  }

  /**
   * Processes each method in method stack until a string is found.
   */
  protected function obtain() {
    // Loop through the stack.
    foreach ($this->methodStack as $current_method) {
      // Run the method. It is expected that the call method will return
      // $this->obtain($element).
      $found_string = $this->$current_method();
      $found_string = $this->cleanString($found_string);

      if ($this->validateString($found_string)) {
        // Give child classes opportunity to process the string before return.
        $found_string = $this->processString($found_string);

        // Remove the element from the DOM and exit loop.
        $obtainer_class = get_class($this);
        drush_doj_migration_debug_output("$obtainer_class matched: $current_method");
        $this->obtainedString = $found_string;
        $this->removeElement();

        break;
      }
    }

    if (empty($this->obtainedString)) {
      drush_doj_migration_debug_output("{$obtainer_class}-Matched: NO MATCHES FOUND");
    }
  }

  /**
   * Cleans $text and returns it.
   *
   * @param string $string
   *   Text to clean and return.
   *
   * @return string
   *   The cleaned text.
   */
  public static function cleanString($string) {
    // There are also numeric html special chars, let's change those.
    module_load_include('inc', 'doj_migration', 'includes/doj_migration');
    $string = doj_migration_html_entity_decode_numeric($string);

    // Checking again in case another process rendered it non UTF-8.
    $is_utf8 = mb_check_encoding($string, 'UTF-8');

    if (!$is_utf8) {
      $string = StringCleanUp::fixEncoding($string);
    }

    $string = StringCleanUp::stripCmsLegacyMarkup($string);

    // Remove specific strings.
    // Strings to remove must be sorted by complexity.  More complex must come
    // before smaller or less complex things.
    $strings_to_remove = array(
      'updated:',
      'updated',
    );
    foreach ($strings_to_remove as $string_to_remove) {
      $string = str_ireplace($string_to_remove, '', $string);
    }

    // Remove white space-like things from the ends and decodes html entities.
    $string = StringCleanUp::superTrim($string);

    return $string;
  }

  /**
   * Evaluates $possibleText and if it checks out, returns TRUE.
   *
   * @param string $string
   *   The string to validate.
   *
   * @return bool
   *   TRUE if possibleText can be used as a title. FALSE if it can't.
   */
  protected function validateString(string $string) {
    // Run through any evaluations. If it makes it to the end, it is good.
    // Case race, first to evaluate TRUE aborts the text.
    switch (TRUE) {
      // List any cases below that would cause it to fail validation.
      case empty($string):
      case is_object($string):
      case is_array($string);
        return FALSE;

      default:
        return TRUE;
    }
  }

  /**
   * Processes a valid string before returning.
   *
   * @param string $string
   *   The found, valid string.
   *
   * @return string
   *   The string to be returned.
   */
  protected function processString($string) {
    return $string;
  }
}
