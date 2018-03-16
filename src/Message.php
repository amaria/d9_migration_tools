<?php
/**
 * @file
 * Helper class to manage watchdog and commandline messaging of migrations.
 */

namespace MigrationTools;

class Message {
  /**
   * Logs a system message and outputs it to drush terminal if run from drush.
   *
   * @param string $message
   *   The message to store in the log. Keep $message translatable
   *   by not concatenating dynamic values into it! Variables in the
   *   message should be added by using placeholder strings alongside
   *   the variables argument to declare the value of the placeholders.
   *   See t() for documentation on how $message and $variables interact.
   * @param array $variables
   *   Array of variables to replace in the message on display or
   *   NULL if message is already translated or not possible to
   *   translate.
   * @param int $severity
   *   The severity of the message; one of the following values as defined in
   *   - WATCHDOG_EMERGENCY: Emergency, system is unusable.
   *   - WATCHDOG_ALERT: Alert, action must be taken immediately.
   *   - WATCHDOG_CRITICAL: Critical conditions.
   *   - WATCHDOG_ERROR: Error conditions.
   *   - WATCHDOG_WARNING: Warning conditions.
   *   - WATCHDOG_NOTICE: (default) Normal but significant conditions.
   *   - WATCHDOG_INFO: Informational messages.
   *   - WATCHDOG_DEBUG: Debug-level messages.
   *   - FALSE: Outputs the message to drush without calling Watchdog.
   *
   * @param int $indent
   *   (optional). Sets indentation for drush output. Defaults to 1.
   *
   * @link http://www.faqs.org/rfcs/rfc3164.html RFC 3164: @endlink
   */
  public static function make($message, $variables = array(), $severity = WATCHDOG_NOTICE, $indent = 1) {
    // Determine what instantiated this message.
    $trace = debug_backtrace();
    $type = 'unknown';
    self::determineType($type, $trace);

    if ($severity !== FALSE) {
      $type = (!empty($type)) ? $type : 'Migration';
      watchdog($type, $message, $variables, $severity);
    }
    // Check to see if this is run by drush and output is desired.
    if (drupal_is_cli() && variable_get('migration_tools_drush_debug', FALSE)) {
      $type = (!empty($type)) ? "{$type}: " : '';
      $formatted_message = format_string($message, $variables);
      // Drush does not print all Watchdog messages to terminal only
      // WATCHDOG_ERROR and worse.
      if ($severity > WATCHDOG_ERROR || $severity === FALSE) {
        // Watchdog didn't output it, so output it directly.
        drush_print($type . $formatted_message, $indent);
      }
      if ((variable_get('migration_tools_drush_stop_on_error', FALSE)) && ($severity <= WATCHDOG_ERROR) && $severity !== FALSE) {
        throw new \MigrateException("{$type}Stopped for debug.\n -- Run \"drush mi {migration being run}\" to try again. \n -- Run \"drush vset migration_tools_drush_stop_on_error FALSE\" to disable auto-stop.");
      }
    }
  }

  /**
   * Outputs a visual separator using the message system.
   */
  public static function makeSeparator() {
    self::make("------------------------------------------------------", array(), FALSE, 0);
  }

  /**
   * Message specific to skipping a migration row.
   *
   * @param string $reason
   *   A short explanation of why it is being skipped.
   * @param string $row_id
   *   The id of the row being skipped.
   * @param int $watchdog_level
   *   The watchdog level to declare.
   *
   * @return bool
   *   FALSE.
   */
  public static function makeSkip($reason, $row_id, $watchdog_level = \WATCHDOG_INFO) {
    // Reason is included directly in the message because it needs translation.
    $message = "SKIPPED->{$reason}: @row_id";
    $variables = array(
      '@row_id' => $row_id,
    );
    self::make($message, $variables, $watchdog_level, 1);

    return FALSE;
  }

  /**
   * Generate the import summary.
   *
   * @param array $completed
   *   Array of completed imports.
   * @param int $total_requested
   *   The number to be processed.
   * @param string $operation
   *   The name of the operation being sumaraized.
   *   Ex: Rewrite image src
   *
   * @return string
   *   The report of what was completed.
   */
  public static function makeSummary($completed, $total_requested, $operation) {
    $t = get_t();
    $count = count($completed);
    $long = variable_get('migration_tools_drush_debug', FALSE);
    if ((int) $long >= 2) {
      // Long output requested.
      $completed_string = self::improveArrayOutput($completed);
      $vars = array(
        '@count' => $count,
        '!completed' => $completed_string,
        '@total' => $total_requested,
        '@operation' => $operation,
      );
      $message = "Summary: @operation @count/@total.  Completed:\n !completed";
    }
    else {
      // Default short output.
      $vars = array(
        '@count' => $count,
        '@total' => $total_requested,
        '@operation' => $operation,
      );
      $message = "Summary: @operation @count/@total.";
    }

    self::make($message, $vars, FALSE, 2);
  }


  /**
   * Stringify and clean up the output of an array for messaging.
   *
   * @param array $array_items
   *   The array to be stringified and cleaned.
   *
   * @return string
   *   The stringified array.
   */
  public static function improveArrayOutput($array_items) {
    $string = print_r($array_items, TRUE);
    $remove = array("Array", "(\n", ")\n");
    $string = str_replace($remove, '', $string);
    // Adjust for misaligned second line.
    $string = str_replace('             [', '     [', $string);

    return $string;
  }

  /**
   * Determine the type of thing that created the message.
   *
   * @param string $type
   *   The name of the thing that made the message. (by reference)
   * @param array $trace
   *   The stack trace as returned by debug_backtrace.
   */
  private static function determineType(&$type, $trace) {
    if (isset($trace[1])) {
      // $trace[1] is the thing that instantiated this message.
      if (!empty($trace[1]['class'])) {
        $type = $trace[1]['class'];
      }
      elseif (!empty($trace[1]['function'])) {
        $type = $trace[1]['function'];
      }
    }

    self::reduceTypeNoise($type);
  }

  /**
   * Reduce misleading type, and the noise of full namespace output.
   *
   * @param string $type
   *   The type that needs to be de-noised or reduced in length.
   */
  private static function reduceTypeNoise(&$type) {
    // A list of types to blank out, to reduce deceptive noise.
    $noise_filter = array(
      'MigrationTools\Message',
    );
    $type = ((in_array($type, $noise_filter))) ? '' : $type;

    // A list of types to increase readability and reduce noise.
    $noise_shorten = array(
      'MigrationTools\Obtainer' => 'MT',
      'MigrationTools' => 'MT',
    );
    $type = str_replace(array_keys($noise_shorten), array_values($noise_shorten), $type);
  }

  /**
   * Dumps a var to drush terminal if run by drush.
   *
   * @param mixed $var
   *   Any variable value to output.
   * @param string $var_id
   *   An optional string to identify what is being output.
   */
  public static function varDumpToDrush($var, $var_id = 'VAR DUMPs') {
    // Check to see if this is run by drush and output is desired.
    if (drupal_is_cli() && variable_get('migration_tools_drush_debug', FALSE)) {
      drush_print("{$var_id}: \n", 0);
      if (!empty($var)) {
        drush_print_r($var);
      }
      else {
        drush_print("This variable was EMPTY. \n", 1);
      }
    }
  }
}
