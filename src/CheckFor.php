<?php

/**
 * @file
 * Contains static methods for checking on elements of a migration document.
 */

namespace MigrationTools;

class CheckFor {

  /**
   * Examines a row to see if it contains a value. Outputs message if found.
   *
   * @param object $row
   *   A migration row object.
   * @param string $row_element
   *   The row->{element} to examine.
   * @param mixed $value
   *   The actual value to look for. Defaults to finding an element not empty.
   *
   * @return bool
   *   TRUE (and outputs a message) if element is found that matches the value.
   *   FALSE if the element does not match the value.
   */
  public static function hasRowValue($row, $row_element, $value = '') {
    $return = FALSE;
    if (!empty($row) && property_exists($row, $row_element)) {
      if (empty($value) && !empty($row->{$row_element})) {
        // Special case where we are just looking for an element that is !empty.
        $return = TRUE;
        $message = "This row->@element contains a value.";
        Message::make($message, array('@element' => $row_element), FALSE, 1);
      }
      elseif (!empty($value) && !empty($row->{$row_element}) && $row->{$row_element} == $value) {
        // The row element has just what we are looking for.
        $return = TRUE;
        $message = "This row->@element contains the value @value.";
        Message::make($message, array('@element' => $row_element, '@value' => $value), FALSE, 1);
      }
    }
    return $return;
  }

  /**
   * Examines a row to see if contains a value. Outputs message ERROR if found.
   *
   * @param object $row
   *   A migration row object.
   * @param string $row_element
   *   The row->{element} to examine.
   * @param mixed $value
   *   The actual value to look for. Defaults to finding an element not empty.
   *
   * @return bool
   *   TRUE (and outputs a message) if element is found that matches the value.
   *   FALSE if the element does not match the value.
   */
  public static function stopOnRowValue($row, $row_element, $value = '') {
    $found = self::hasRowValue($row, $row_element, $value);
    if ($found) {
      if (empty($value)) {
        $message = "Stopped because row->@element contains a value";
      }
      else {
        $message = "Stopped because row->@element has the value: @value";
      }
      // Output the entire row for inspection.
      Message::varDumpToDrush($row, 'OUTPUT $row');
      // Output an error level message so it will stop the migration if
      // vset migration_tools_drush_stop_on_error is set to TRUE.
      Message::make($message, array('@element' => $row_element, '@value' => $value), WATCHDOG_ERROR, 1);

    }
  }

  /**
   * Checks to see if a date comes after a cutoff.
   *
   * @param string $date
   *   A date to evaluate as can be used by strtotime().
   * @param string $date_cutoff
   *   A date representing the low cut-off mm/dd/yyyy.
   * @param bool $default
   *   What should be returned if the date is invalid or unavailable.
   *
   * @return bool
   *   TRUE if the $date > $date_cutoff, or uncheckable.
   *   FALSE if $date < $date_cutoff
   */
  public static function isDateAfter($date, $date_cutoff, $default = TRUE) {
    $date_cutoff = strtotime($date_cutoff);
    $date = strtotime($date);
    if (($date !== FALSE) && ($date_cutoff !== FALSE)) {
      // Both the $date and $date_cutoff are valid.
      if ($date > $date_cutoff) {
        return TRUE;
      }
      else {
        return FALSE;
      }
    }
    // If the comparison is invalid or fails, call it TRUE.
    // Risky but safer than throwing away a file just because it has a bad date.
    return $default;

  }

  /**
   * Determines if the current row is a duplicate using redirects as reference.
   *
   * Legacy paths from site, should not be pointing to more than one node,
   * If this is happening, it is a good sign that we are bringing in duplicate
   * content.
   *
   * @param string $legacy_path
   *   The legacy path that would be registered as a redirect.
   *
   * @return bool
   *   Whether this row is a duplicate or not.
   */
  public static function isDuplicateByRedirect($legacy_path) {
    $parsed = redirect_parse_url($legacy_path);
    $source = isset($parsed['path']) ? ltrim($parsed['path'], '/') : '';
    $redirect = redirect_load_by_source($source);

    if ($redirect) {
      $message = "- @source  -> Skipped: Already redirected to '@redirect'.";
      Message::make($message, array('@source' => $source, '@redirect' => $redirect->redirect), WATCHDOG_WARNING, 1);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Determine if a given file is within any of an array of paths.
   *
   * @param array $paths
   *   Array of paths to check.
   * @param object $row
   *   A row object as delivered by migrate.
   *
   * @return bool
   *   -TRUE if the file is one of the paths.
   *   -FALSE if the file is not within one of the paths.
   */
  public static function isInPath(array $paths, $row) {
    foreach ($paths as $path) {
      // Is the file in one of the paths?
      if (stripos($row->fileId, $path) !== FALSE) {
        // The file is in the path.
        return TRUE;
      }
    }
    // This file is not in any of the paths.
    return FALSE;
  }

  /**
   * Determine if a given file should be excluded from the current migration.
   *
   * @param string $file_id
   *   The unique id for the current row. Typically legacy_path or fileid.
   *
   * @param array $files_to_skip
   *   Array of values to skip and not migrate.
   *
   * @return bool
   *   -TRUE if the row should be skipped.
   *   -FALSE if the row should not be skipped.
   */
  public static function isSkipFile($file_id, $files_to_skip) {
    if (in_array($file_id, $files_to_skip)) {
      // This page should be skipped.
      $message = '- @fileid  -> Skipped: intentionally.';
      watchdog('migration_tools', $message, array('@fileid' => $file_id), WATCHDOG_WARNING);

      return TRUE;
    }

    // This page should not be skipped.
    return FALSE;
  }

  /**
   * Check the file to see if it is the desired type. Msg watchdog if it is not.
   *
   * @param string $desired_type
   *   The content type machine name that should be kept / not skipped.
   * @param object $row
   *   A row object as delivered by migrate.
   *
   * @return bool
   *   TRUE - the file is the desired type or can't be evaluated.
   *   FALSE - if is definitely not that type.
   */
  public static function isType($desired_type, $row) {
    // In order to have $row->content_type populated, add a find method(s) to
    // ObtainContentType.php or an extension of it and add it to the find stack.
    if (property_exists($row, 'content_type') && ($row->content_type != $desired_type)) {
      // This page does not match to $target_type.
      $message = "- @fileid -- Is type '@content_type_obtained' NOT '@desired_type'";
      $vars = array(
        '@desired_type' => $desired_type,
        '@content_type_obtained' => $row->content_type,
        '@fileid' => $row->fileId,
      );

      return FALSE;
    }
    return TRUE;
  }

  /**
   * Check file for redirects.
   *
   * @param object $row
   *   A row object as delivered by migrate.
   * @param QueryPath $query_path
   *   The current QueryPath object.
   *
   * @return mixed
   *   string - full URL of the redirect destination.
   *   FALSE - no detectable redirects exist in the page.
   */
  public static function hasHtmlRedirect($row, $query_path) {
    // Checks for meta location redirects.
    $meta_location = $query_path->find('meta[http-equiv="location"')->attr("url");
    if (!empty($meta_location)) {
      return $meta_location;
    }
    // Checks for meta refresh redirects. Does support relative urls.
    $meta_refresh = $query_path->find('meta[http-equiv="refresh"]')->attr("url");
    if (!empty($meta_refresh)) {
      return $meta_refresh;
    }
    // Checks for presence of Javascript. <script type="text/javascript">
    $js = $this->queryPath->find('script[type="text/javascript"]');
    if (!empty($js)) {
      // Checks for window location JS redirects. window.location.href
      $wlh = $js->attr('window.location.href');
      if (!empty($wlh)) {
        return $wlh;
      }
      // Checks for location JS redirects.
      $location = $js->attr('location');
      if (!empty($location)) {
        return $location;
      }
      // Checks for location href JS redirects. location.href
      $lh = $js->attr('location.href');
      if (!empty($lh)) {
        return $lh;
      }
      // Checks for location assign JS redirects. location.assign
      // Checks for location JS redirects.
      $la = $js->attr('location.assign');
      if (!empty($la)) {
        return $la;
      }
      // Checks for location replace JS redirects. location.replace
      // Checks for location JS redirects.
      $lr = $js->attr('location.replace');
      if (!empty($lr)) {
        return $lr;
      }
    }

    // Checks for human readable text redirects.
    $human = $query_path->find(body);
    // Checks for 'this page has been moved to'.
    $has_been = $human->text('This page has been moved to');
    if (!empty($has_been)) {
      return $has_been;
    }
    // Checks for 'this page has moved to'.
    $this_page = $human->text('this page has moved to');
    if (!empty($this_page)) {
      return $this_page;
    }
  }
}
