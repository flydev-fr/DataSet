<?php namespace ProcessWire;
// DEBUG disable file compiler for this file
// FileCompiler=0

/*
 * DataSet CSV import module
 * 
 * Provides CSV import functions for the DataSet module.
 * 
 * Copyright 2018 Tamas Meszaros <mt+git@webit.hu>
 * This file licensed under Mozilla Public License v2.0 http://mozilla.org/MPL/2.0/
 */
 
class DataSetCsvProcessor extends WireData implements Module {

/***********************************************************************
 * MODULE SETUP
 **********************************************************************/

  /**
   * Called only when this module is installed
   */
  public function ___install() {
  }


  /**
   * Called only when this module is uninstalled
   */
  public function ___uninstall() {
  }


  /**
   * Initialization
   * 
   * This function attaches a hook for page save and decodes module options.
   */
  public function init() {
  }


  /**
   * Count XML entries in a file
   * 
   * @param $file filefield entry to process
   * @param $params assoc array of config parameters like the tag name of the entry
   * returns false on fatal error, number of records on success
   */
  public function countRecords($file, &$params) {
    $this->message("Counting records in {$file->name}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($file->filename, 'rb');
    if (!$fd) {
      $this->error("Unable to open {$file->name}.");
      return false;
    }
    // count rows
    $count = 0;
    while (fgets($fd)) { // TODO large lines?
      $count++;
    }
    fclose($fd);
    // subtract the header row if exists
    if ($params['input']['header'] != 0) $count -= $params['input']['header'];
    return $count;
  }


  /**
   * Process data and add/update/delete child nodes under $dataSetPage
   * 
   * @param $dataSetPage ProcessWire Page object (the root of the data set)
   * @param $file filefield entry to process
   * @param $taskData task data assoc array
   * @param $params array of config parameters like the task object, timeout, template etc.
   * returns false on fatal error
   */
  public function process(Page $dataSetPage, $file, &$taskData, &$params) {
    $this->message("Importing records from {$file->name}.", Notice::debug);
    ini_set("auto_detect_line_endings", true);
    $fd = fopen($file->filename, 'rb');
    if (!$fd) {
      $this->error("ERROR: unable to open {$file->name}.");
      return false;
    }

    $ptemplate = wire('templates')->get($params['pages']['template']);
    if (!$ptemplate instanceof Template) {
      $this->error("ERROR: unknown template: {$params['pages']['template']}.");
      return false;
    }

    // get a reference to Tasker and the task
    $tasker = wire('modules')->get('Tasker');
    $task = $params['task'];

    // count and store a few processed records
    $newPageCounter = 0; $newPages = array();
    // set the import status to not finished
    $notFinished = true;

    // check and set encoding
    if (isset($params['input']['encoding'])) {
      if (!setlocale(LC_CTYPE, $params['input']['encoding'])) {
        $this->error("ERROR: locale {$params['input']['encoding']} is not supported by your system.");
        return false;
      }
      $encoding = $params['input']['encoding'];
    } else {
      $encoding = 'utf-8';
    }

    // skip header rows if needed
    if ($params['input']['header'] != 0) {
      $i = $params['input']['header'];
      while ($i-- > 0) fgets($fd);
    }

    // determine what columns are required
    // TODO this is not tested and may not work
    if (isset($params['input']['required_fields']) && is_array($params['input']['required_fields'])) {
      $req_fields = $params['input']['required_fields'];
    } else {
      $req_fields = array();
    }

    // set default values for field data
    if (isset($params['field_data_defaults']) && is_array($params['field_data_defaults'])) {
      $field_data_defaults = $params['field_data_defaults'];
    } else {
      $field_data_defaults = array();
    }

    // set default values for CSV columns
    if (isset($params['csv_data_defaults']) && is_array($params['csv_data_defaults'])) {
      $csv_data_defaults = $params['csv_data_defaults'];
    } else {
      $csv_data_defaults = array();
    }

    // check if we need to skip a few records
    if ($taskData['records_processed'] > 0) {
      $entrySerial = 0;
      $this->message('Skipping '.$taskData['records_processed'].' entries.', Notice::debug);
      while (!($notFinished = fgetcsv($fd, $params['input']['max_line_length'],
                          $params['input']['delimiter'],
                          $params['input']['enclosure']))) {
        if (++$entrySerial == $taskData['records_processed']) break;
      }
    }

    // set an initial milestone
    $taskData['milestone'] = $taskData['records_processed'] + 20;

// TODO rethink return status

//
// The MAIN data import loop (if we still have data)
//
    if ($notFinished) do {
      if (!$tasker->allowedToExecute($task, $params)) {
        $taskData['task_done'] = 0;
        break; // ... the loop
      }

      // stop importing if we've reached the maximum (e.g. due to a limit)
      if (isset($params['input']['limit']) && $taskData['records_processed'] >= $params['input']['limit']) {
        break; // ... the loop
      }

      // This ensures that new lines in fields are processed correctly
      $csv_data = fgetcsv($fd, $params['input']['max_line_length'],
                          $params['input']['delimiter'],
                          $params['input']['enclosure']);

      if ($csv_data === FALSE) {
        break; // ... the loop as there is no more data
      }

      // increase the number of processed records and the actual offset counter
      $taskData['records_processed']++;

      // check encoding, TODO this is fairly slow, see https://stackoverflow.com/questions/1523460/ensuring-valid-utf-8-in-php
      if (!mb_check_encoding(implode(' ', $csv_data), $encoding)) {
        $this->error('ERROR: wrong character encoding in '.implode($params['input']['delimiter'], $csv_data));
        break;
      }

      if (count($csv_data) < 2 && count($params['fieldmappings']) > 1) {
        $this->error('ERROR: too few columns found. Could be a wrong delimiter or malformed input: '.implode($params['input']['delimiter'], $csv_data));
        break;
      }

      // add a serial number to the beginning of the record
      // it will get index 0 in the $csv_data array
      // this also ensures that CSV files with only one column (and no delimiter) can be processed this way
      $csv_data = array_merge(array(0 => $taskData['records_processed']), $csv_data);

      // check for required fields
      foreach ($req_fields as $column) {
        if (!isset($csv_data[$column]) || empty($csv_data[$column])) {
          $this->error("ERROR: missing required column {$column} in the input: ".implode($params['input']['delimiter'], $csv_data));
          continue 2; // go to the next record in the input
        }
      }

      $this->message('Processing input record: '.implode($params['input']['delimiter'], $csv_data), Notice::debug);

      // use default values
      $csv_data = array_replace($csv_data_defaults, $csv_data);

      $this->message('Input record after defaults merged: '.implode($params['input']['delimiter'], $csv_data), Notice::debug);

      $selector = $params['pages']['selector']; // will be altered later

      // stores field data read from the input
      $field_data = $field_data_defaults;
  
      // transfer input data to a field array
      // TODO sanitize user input
      foreach ($params['fieldmappings'] as $field => $column) {

        if (is_numeric($column)) {
          // if the column is an integer then use a single column from the input
          if (!isset($csv_data[$column]) && !isset($field_data_defaults[$column])) {
            // if the column does not present and there is no default field value then dump an error a skip the record
            $this->error("ERROR: column '{$column}' for field '{$field}' is not found in the input and no default value is set.");
            continue 2; // go to the next record in the input
          }
          $value = trim($csv_data[$column], "\"'\t\n\r\0\x0B");

        } elseif (is_array($column)) { // a set of columns from the input
          // if the column is an array then its elements can be column IDs and strings
          // compose the value from several columns and glue strings
          $value = '';
          foreach ($column as $col) {
            if (is_string($col)) $value .= $col; // a glue string between column values
            else if (is_numeric($col)) {  // a column ID, get its data
              if (!isset($csv_data[$col])) {  // empty input data and no defaults
                $this->error("ERROR: column '{$col}' for field '{$field}' not found in the input and no default value has been set for that CSV column.");
                continue 3; // go to the next record in the input
              }
              // append the column's value
              $value .= trim($csv_data[$col], "\"'\t\n\r\0\x0B");
            } else {
              $this->error("ERROR: invalid column specifier '{$col}' used in composing a value for field '{$field}'");
              break 3; // stop processing records, the error needs to be fixed
            }
          }
        } else { // the column is not an integer and not an array
          $this->error("ERROR: invalid column specifier '{$column}' given for field '{$field}'.");
          break 2; // stop processing records, the error needs to be fixed
        }

        // skip the field if it is empty
        if (!strlen($value)) continue;

        // store the value
        $field_data[$field] = $value;

        // if this field is used in the page selector then replace it with its value
        if (strpos($selector, '@'.$field)) {
          if (mb_strlen($field_data[$field])>100) {  // a ProcessWire constrain
            $this->warning("WARNING: the value of selector '{$field}' is too long. Truncating to 100 characters.");
          }
          // TODO This removes [ ] and other chars, see https://github.com/processwire/processwire/blob/master/wire/core/Sanitizer.php#L1506
          // HOWTO fix this?
          // TODO This may encounter encoding problems. How to handle them?
          $svalue = wire('sanitizer')->selectorValue($field_data[$field]);

          // TODO
          // if a field value used in the selector is missing then the selector will not work
          
          // TODO
          // rewrite the selector setting as an array of fields to be matched
          
          // page reference selectors
          $fconfig = $ptemplate->fields->get($field);
          if ($fconfig == NULL) {
            $this->error("ERROR: unable to retrieve configuration for field {$field}.");
            break 2; // stop processing records, the error needs to be fixed
          }
          if ($fconfig->type instanceof FieldtypePage) {
            $pageSelector = wire('modules')->DataSet->getPageSelector($fconfig, $field_data[$field]);
            $svalue = $this->pages->findOne($pageSelector);
            if ($svalue === NULL || $svalue instanceof NullPage) {
              $this->warning("WARNING: Referenced page {$value} for field {$field} is not found.");
              continue;
            }
          }
          $selector = str_replace('@'.$field, $svalue, $selector);
        }
      }

      // check for required fields
      /* TODO not supported ATM
      $not_present=array_diff($req_fields, $field_data);
      if (count($not_present)) {
        foreach ($not_present as $field) {
          $this->error("ERROR: missing value for required field '{$field}' in the input.");
        }
        $this->message(var_export($req_fields, true));
        $this->message(var_export($field_data, true));
        break;
        // continue; // go to the next record in the input
      }*/

      $this->message("Data interpreted as ".str_replace("\n", " ", print_r($field_data, true)), Notice::debug);
      $this->message("Page selector is {$selector}.", Notice::debug);

      // create or update the page
      // it will log error and warning messages
      $newPage = $this->modules->DataSet->importPage($dataSetPage, $selector, $field_data, $params);
      
      if ($newPage instanceof Page) {
        $newPages[] = $newPage->title;
      } elseif ($newPage === false) {
        $this->error("ERROR: could not import the record '".implode($params['input']['delimiter'], $csv_data)."'");
      }

      // Report progress and check for events if a milestone is reached
      if ($tasker->saveProgressAtMilestone($task, $taskData, $params) && count($newPages)) {
        $this->message('Import successful for '.implode(', ', $newPages));
        // set the next milestone
        $taskData['milestone'] = $taskData['records_processed'] + 20;
        // clear the new pages array (the have been already reported in the log)
        $newPages = array();
      }

    } while (true);
//
// END of the MAIN data import loop (if we still have data)
//

    fclose($fd);

    // print out some info for the user
    if (count($newPages)) $this->message('Import successful for '.implode(', ', $newPages));

    return true;
  }

}
