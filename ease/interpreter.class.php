<?php
/*
* Copyright 2014 Cloudward, Inc.
*
* This project is licensed under the terms of either the GNU General Public
* License Version 2 with Classpath Exception or the Common Development and
* Distribution License Version 1.0 (the "License").  See the LICENSE.txt
* file for details.
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

/**
 * Interpreter for use with the EASE Core to process EASE variables and apply contexts
 *
 * @author Mike <mike@cloudward.com>
 */
class ease_interpreter
{
	public $parser;
	public $override_url_params;
	public $core;

	function __construct(&$parser, $override_url_params=null) {
		$this->parser = $parser;
		if(is_array($override_url_params)) {
			$this->override_url_params = $override_url_params;
		}
		$this->core = &$parser->core;
	}

	function inject_global_variables(&$string, $additional_context=null) {
		// this function will return true if any injection was done; false otherwise
		$injected = false;
		// replace any EASE Variable tags with their contexted value:   <#[global.variable as context]#>
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*' . preg_quote($this->core->global_reference_start, '/') . '\s*(.+?)\s*' . preg_quote($this->core->global_reference_end, '/') . '\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use (&$injected, $additional_context) {
				// process the variable name to determine the bucket type and any injection context
				$injected = true;
				$bucket_key_parts = explode('.', $matches[1], 2);
				if(count($bucket_key_parts)==2) {					
					$bucket = rtrim(strtolower($bucket_key_parts[0]));
					$key = ltrim(@$bucket_key_parts[1]);
				} else {
					$bucket = '';
					$key = $matches[1];
				}
				$key_lower = strtolower($key);
				// extract any context from the variable reference
				$context_stack = ease_interpreter::extract_context_stack($key);
				if($additional_context!==null) {
					$context_stack[] = $additional_context;
				}
				if($bucket!='') {
					$name = $bucket . '.' . $key;
				} else {
					$name = $key;
				}
				$name_lower = strtolower($name);
				// retrieve the value to inject based on the bucket type
				if($bucket=='session') {
					if(isset($_SESSION[$key])) {
						$value = $_SESSION[$key];
					} elseif(isset($_SESSION[$key_lower])) {						
						$value = $_SESSION[$key_lower];
					} else {
						$value = '';
					}
				} elseif($bucket=='cookie') {
					if(isset($_COOKIE[$key])) {
						$value = $_COOKIE[$key];
					} elseif(isset($_COOKIE[$key_lower])) {						
						$value = $_COOKIE[$key_lower];
					} else {
						$value = '';
					}
				} elseif($bucket=='post') {
					if(isset($_POST[$key])) {
						$value = $_POST[$key];
					} elseif(isset($_POST[$key_lower])) {
						$value = $_POST[$key_lower];
					} else {
						$value = '';
					}
				} elseif($bucket=='url') {
					if(is_array($this->override_url_params)) {
						if(isset($this->override_url_params[$key])) {
							$value = $this->override_url_params[$key];							
						} elseif(isset($this->override_url_params[$key_lower])) {
							$value = $this->override_url_params[$key_lower];
						} else {
							$value = '';
						}
					} elseif(isset($_GET[$key])) {
						if(isset($_GET[$key])) {
							$value = $_GET[$key];
						} elseif(isset($_GET[$key_lower])) {
							$value = $_GET[$key_lower];
						} else {
							$value = '';
						}
					}
				} elseif($bucket=='request') {
					if(isset($_REQUEST[$key])) {
						$value = $_REQUEST[$key];
					} elseif(isset($_REQUEST[$key_lower])) {
						$value = $_REQUEST[$key_lower];
					} else {
						$value = '';
					}
				} elseif($bucket=='config') {
					if(!$this->core->inject_config_disabled) {
						if(isset($this->core->config[$key])) {
							$value = $this->core->config[$key];
						} elseif(isset($this->core->config[$key_lower])) {
							$value = $this->core->config[$key_lower];
						} else {
							$value = '';
						}
					} else {
						$value = '';
					}
				} elseif($bucket=='cache') {
					if($this->core->memcache) {
						if($this->core->namespace!='') {
							$value = $this->core->memcache->get("{$this->core->namespace}.{$key}");
						} else {
							$value = $this->core->memcache->get($key);
						}
					} else {
						$value = '';
					}
				} elseif($bucket=='spreadsheet_id_by_name') {
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($key);
					if(isset($spreadsheet_meta_data['id'])) {
						$value = $spreadsheet_meta_data['id'];
					} else {
						$value = '';
					}
				} elseif($bucket=='spreadsheet_name_by_id') {
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($key);
					if(isset($spreadsheet_meta_data['name'])) {
						$value = $spreadsheet_meta_data['name'];
					} else {
						$value = '';
					}
				} elseif(isset($this->core->globals[$name])) {
					$value = $this->core->globals[$name];
				} elseif(isset($this->core->globals[$name_lower])) {
					$value = $this->core->globals[$name_lower];
				} elseif($this->core->memcache && (($value = $this->core->memcache->get($name))!==false)) {
					// this is tricky code to save memory on large cached objects
					// $value was already set with the value from the cache... done
				} elseif($this->core->namespace!='' && $this->core->memcache && (($value = $this->core->memcache->get("{$this->core->namespace}.{$name}"))!==false)) {
					// this is tricky code to save memory on large cached objects
					// $value was already set with the value from the cache... done
				} elseif(isset($this->core->globals[".{$name}"])) {
					$value = $this->core->globals[".{$name}"];
				} elseif($this->core->memcache && (($value = $this->core->memcache->get(".{$name}"))!==false)) {
					// this is tricky code to save memory on large cached objects
					// $value was already set with the value from the cache... done
				} elseif($this->core->namespace!='' && $this->core->memcache && (($value = $this->core->memcache->get("{$this->core->namespace}..{$name}"))!==false)) {
					// this is tricky code to save memory on large cached objects
					// $value was already set with the value from the cache... done
				} else {
					// the referenced EASE variable did not match any value... return an empty string
					$value = '';
				}
				// inject global variables into context parameters
				foreach($context_stack as $key=>$context) {
					if(is_array($context)) {
						if($context['context']=='hash' && $context['salt_var']!='') {
							$hash_salt_var = $this->core->ease_block_start . $this->core->global_reference_start . $context['salt_var'] . $this->core->global_reference_end . $this->core->ease_block_end;
							$this->inject_global_variables($hash_salt_var);
							$context_stack[$key]['salt'] = $hash_salt_var;
						}
					}
				}
				// apply the context to the value to inject
				ease_interpreter::apply_context_stack($value, $context_stack);
				// inject the value to replace the EASE variable tag
				return $value;
			},
			$string
		);
		// replace any EASE system variable tags without brackets:  <# system.variable #>   instead of   <#[system.variable]#>
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*system\s*\.\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use (&$injected, $additional_context) {
				$injected = true;
				$key = strtolower($matches[1]);
				$context_stack = ease_interpreter::extract_context_stack($key);
				if($additional_context!==null) {
					$context_stack[] = $additional_context;
				}
				// retrieve the value to inject
				$value = $this->core->globals["system.$key"];
				// apply the context to the value to inject
				ease_interpreter::apply_context_stack($value, $context_stack);
				// inject the value to replace the EASE system variable tag
				return $value;
			},
			$string
		);
		// return boolean value about whether or any injection was done
		return $injected;
	}

	function inject_local_spreadsheet_row_variables(&$string, &$column_letter_by_name, &$cell_value_by_column_letter, &$local_variables=null, $additional_context=null) {
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use ($column_letter_by_name, $cell_value_by_column_letter, $local_variables, $additional_context) {
				$context_stack = ease_interpreter::extract_context_stack($matches[1]);
				if($additional_context!==null) {
					$context_stack[] = $additional_context;
				}
				if(isset($local_variables[$matches[1]])) {
					$value = $local_variables[$matches[1]];
				} else {
					$ease_variable_parts = explode('.', $matches[1], 2);
					if(count($ease_variable_parts)==2) {
						$bucket = preg_replace('/[^a-z]+/', '', strtolower(rtrim($ease_variable_parts[0])));
						if($bucket=='row') {
							$column_letter = strtoupper(rtrim($ease_variable_parts[1]));
							if(in_array($column_letter, $column_letter_by_name)) {
								if(isset($cell_value_by_column_letter[$column_letter])) {
									$value = $cell_value_by_column_letter[$column_letter];
								} else {
									$value = '';
								}
							} else {
								$column_name = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($ease_variable_parts[1])));
								if(isset($cell_value_by_column_letter[$column_letter_by_name[$column_name]])) {
									$value = $cell_value_by_column_letter[$column_letter_by_name[$column_name]];
								} else {
									$value = '';
								}
							}
						}
					} else {
						$column_letter = strtoupper($matches[1]);
						if(in_array($column_letter, $column_letter_by_name)) {
							if(isset($cell_value_by_column_letter[$column_letter])) {
								$value = $cell_value_by_column_letter[$column_letter];
							} else {
								$value = '';
							}
						} else {
							$column_name = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($matches[1]));
							if(isset($cell_value_by_column_letter[$column_letter_by_name[$column_name]])) {
								$value = $cell_value_by_column_letter[$column_letter_by_name[$column_name]];
							} else {
								$value = '';
							}
						}
					}
				}
				ease_interpreter::apply_context_stack($value, $context_stack);
				return $value;
			},
			$string
		);
	}

	// this function differs from inject_local_spreadsheet_row_variables in that it allows <# A.5 #> syntax for referencing any cell in the sheet
	function inject_local_spreadsheet_variables(&$string, &$column_letter_by_name, &$cells_by_row_by_column_letter, $additional_context=null) {
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use ($column_letter_by_name, $cells_by_row_by_column_letter, $additional_context) {
				$context_stack = ease_interpreter::extract_context_stack($matches[1]);
				if($additional_context!==null) {
					$context_stack[] = $additional_context;
				}
				$value = '';
				$ease_variable_parts = explode('.', $matches[1], 2);
				if(count($ease_variable_parts)==2) {
					$column = preg_replace('/[^A-Z]+/', '', strtoupper(rtrim($ease_variable_parts[0])));
					if(in_array($column, $column_letter_by_name)) {
						$row = preg_replace('/[^0-9]+/', '', ltrim($ease_variable_parts[1]));
						if(isset($cells_by_row_by_column_letter[$row][$column])) {
							$value = $cells_by_row_by_column_letter[$row][$column];
						}
					}
				} else {
					$column = preg_replace('/[^A-Z]+/', '', strtoupper($matches[1]));
					if(in_array($column, $column_letter_by_name)) {
						if(isset($cells_by_row_by_column_letter[1][$column])) {
							$value = $cells_by_row_by_column_letter[1][$column];
						}
					}
				}
				ease_interpreter::apply_context_stack($value, $context_stack);
				return $value;
			},
			$string
		);
	}

	function inject_local_sql_row_variables(&$string, &$row, &$sql_table_name, &$local_variables=null, $additional_context=null) {
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use ($row, $sql_table_name, $local_variables, $additional_context) {
				if($matches[1]=='lastrow') {
					if($local_variables['rownumber']==$local_variables['numberofrows']) {
						return 'yes';
					} else {
						return '';
					}
				}
				$table_column_reference = $matches[1];
				$context_stack = ease_interpreter::extract_context_stack($table_column_reference);
				if($additional_context!==null) {
					$context_stack[] = $additional_context;
				}
				if(isset($local_variables[$table_column_reference])) {
					$value = $local_variables[$table_column_reference];
				} else {
					$table_column_parts = explode('.', $table_column_reference, 2);
					if(count($table_column_parts)==2) {
						$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($table_column_parts[0])));
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($table_column_parts[1])));
					} else {
						$bucket = $sql_table_name;
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($table_column_reference)));
					}
					if($bucket=='row') {
						$bucket = $sql_table_name;
					}
					if($key=='id') {
						$key = 'uuid';
					}
					if(isset($row["$bucket.$key"])) {
						$value = $row["$bucket.$key"];
					} elseif(isset($row[$key])) {
						$value = $row[$key];
					} else {
						$value = '';
					}
				}
				ease_interpreter::apply_context_stack($value, $context_stack);
				return $value;
			},
			$string
		);
	}

	public static function extract_context_stack(&$string) {
		$context_stack = array();
		while(preg_match('/(.*)(\s*::\s*|\s+as\s+)([a-z]+)(\s*(\+|-)\s*([0-9., -]+)\s*([a-z-]+)|\s*"(.*?)"|\s*using\s*"\s*(.*?)\s*"\s*salted\s*by\s*("\s*(.*?)\s*"|(.*?))\s*|\s*using\s*"\s*(.*?)\s*"\s*|\s*salted\s*by\s*("\s*(.*?)\s*"|(.*?))\s*using\s*"\s*(.*?)\s*"\s*|\s*salted\s*by\s*("\s*(.*?)\s*"|(.*?))\s*|)\s*$/is', $string, $matches)) {
			$context = strtolower($matches[3]);
			if($context=='timestamp' && isset($matches[6]) && $matches[6]!='') {
				// timestamp context with adjustment
				if($matches[5]=='-') {
					// subtraction adjustment;  set a modifier to allow negating the adjustment value for subtraction
					$adjustment_modifier = -1;
				} else {
					// addition adjustment;  set a modifier to allow summing the adjustment value
					$adjustment_modifier = 1;
				}
				$adjustment_value = preg_replace('/[ ,]+/', '', $matches[6]);
				$adjustment_units = strtolower($matches[7]);
				switch($adjustment_units) {
					case 'minute':
					case 'minutes':
						$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * 60 * $adjustment_modifier);
						break;
					case 'hour':
					case 'hours':
						$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * 60 * 60 * $adjustment_modifier);
						break;
					case 'work-day':
					case 'work-days':
					case 'workday':
					case 'workdays':
						// 8 hour workdays.  it's official.
						$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * 60 * 60 * 8 * $adjustment_modifier);
						break;
					case 'day':
					case 'days':
						$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * 60 * 60 * 24 * $adjustment_modifier);
						break;
					case 'week':
					case 'weeks':
						$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * 60 * 60 * 24 * 7 * $adjustment_modifier);
						break;
					case 'month':
					case 'months':
						$context_stack[] = array('context'=>$context, 'adjustment_months'=>$adjustment_value * $adjustment_modifier);
						break;
					case 'month-end':
					case 'month-ends':
					case 'months-end';
					case 'monthsend';
					case 'months-ends';
					case 'monthsends';
					case 'monthend':
					case 'monthends':
						$context_stack[] = array('context'=>$context, 'adjustment_monthends'=>$adjustment_value * $adjustment_modifier);
						break;
					case 'first-of-month':
					case 'first-of-months':
					case 'firsts-of-month':
					case 'firsts-of-months':
					case 'firstofmonth':
					case 'firstofmonths':
					case 'firstsofmonth':
					case 'firstsofmonths':
						$context_stack[] = array('context'=>$context, 'adjustment_firstofmonths'=>$adjustment_value * $adjustment_modifier);
						break;
					case 'year':
					case 'years':
						$context_stack[] = array('context'=>$context, 'adjustment_years'=>$adjustment_value * $adjustment_modifier);
						break;
					case 'decade':
					case 'decades':
						$context_stack[] = array('context'=>$context, 'adjustment_years'=>$adjustment_value * $adjustment_modifier * 10);
						break;
					case 'century':
					case 'centuries':
						$context_stack[] = array('context'=>$context, 'adjustment_years'=>$adjustment_value * $adjustment_modifier * 100);
						break;
					case 'millennium':
					case 'millennia':
						$context_stack[] = array('context'=>$context, 'adjustment_years'=>$adjustment_value * $adjustment_modifier * 1000);
						break;
					default:
					// default adjustment value units to seconds
					$context_stack[] = array('context'=>$context, 'adjustment_seconds'=>$adjustment_value * $adjustment_modifier);
				}
			} elseif(($context=='time' || $context=='date' || $context=='pdate' || $context=='phpdate') && isset($matches[8]) && $matches[8]!='') {
				// the context was a date or time with a format string.  ex:  <# system.timestamp as date "M/D/Y" #>
				$context_stack[] = array('context'=>$context, 'format_string'=>$matches[8]);
			} elseif($context=='hash') {
				$algo = 'sha256';
				$salt = '';
				$salt_var = '';
				if(isset($matches[9]) && $matches[9]!='') {
					$algo = $matches[9];
				}
				if(isset($matches[11]) && $matches[11]!='') {
					$salt = $matches[11];
				}
				if(isset($matches[12]) && $matches[12]!='') {
					$salt_var = $matches[12];
				}
				if(isset($matches[13]) && $matches[13]!='') {
					$algo = $matches[13];
				}
				if(isset($matches[15]) && $matches[15]!='') {
					$salt = $matches[15];
				}
				if(isset($matches[16]) && $matches[16]!='') {
					$salt_var = $matches[16];
				}
				if(isset($matches[17]) && $matches[17]!='') {
					$algo = $matches[17];
				}
				if(isset($matches[19]) && $matches[19]!='') {
					$salt = $matches[19];
				}
				if(isset($matches[20]) && $matches[20]!='') {
					$salt_var = $matches[20];
				}
				$context_stack[] = array('context'=>'hash', 'algo'=>$algo, 'salt'=>$salt, 'salt_var'=>$salt_var);
			} else {
				// basic single word context
				$context_stack[] = $context;
			}
			// update the string value with the context extracted
			$string = $matches[1];
		}
		return $context_stack;
	}

	public static function apply_context_stack(&$string, $context_stack) {
		if(is_array($context_stack) && count($context_stack)>0) {
			$context_stack = array_unique($context_stack);
			end($context_stack);
			while($context = array_pop($context_stack)) {
				if(is_array($context)) {
					// this is a context with additional parameters
					switch($context['context']) {
						case 'hash':
							$string = hash($context['algo'], $context['salt'] . $string);
							break;
						case 'timestamp':
							// timestamp with adjustments
							if(preg_match('/^[0-9]+(\.[0-9]+|)$/', $string, $matches)) {
								// the value is already in decimal format... assume it is already a timestamp
							} elseif(preg_match('/^([0-9]{1,2})\s*\/\s*([0-9]{1,2})\s*\/\s*([0-9]{4})\s*(([0-9]+)|)(\s*:\s*([0-9]+)|)(\s*:\s*([0-9]+)|)\s*(am|pm|a|p|)$/is', $string, $matches)) {
								// this will convert strings like "09/19/2013 8:30 AM" into seconds since midnight starting jan 1st, 1970
								$month = intval($matches[1]);
								$day = intval($matches[2]);
								$year = intval($matches[3]);
								$hour = intval($matches[5]);
								$minute = intval($matches[7]);
								$second = intval($matches[9]);
								$ampm = strtolower($matches[10]);
								if(($ampm=='pm' || $ampm=='p') && $hour!=12) {
									$hour += 12;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							} elseif(preg_match('/^([0-9]{4})\s*-\s*([0-9]{2})\s*-\s*([0-9]{2})\s*(([0-9]+)|)(\s*:\s*([0-9]+)|)(\s*:\s*([0-9]+)|)\s*(am|pm|a|p|)$/is', $string, $matches)) {
								// this will convert strings that match the system.date_time_short syntax like "1979-01-17 07:43:34" into seconds since midnight starting jan 1st, 1970
								$month = intval($matches[2]);
								$day = intval($matches[3]);
								$year = intval($matches[1]);
								$hour = intval($matches[5]);
								$minute = intval($matches[7]);
								$second = intval($matches[9]);
								$ampm = strtolower($matches[10]);
								if(($ampm=='pm' || $ampm=='p') && $hour!=12) {
									$hour += 12;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							} else {
								// check for other date formats???
							}
							if(isset($context['adjustment_seconds'])) {
								$string = $string + $context['adjustment_seconds'];
							}
							if(isset($context['adjustment_months'])) {
								$timestamp = intval($string);
								$month = idate('m', $timestamp);
								$day = idate('d', $timestamp);
								$year = idate('Y', $timestamp);
								$hour = idate('H', $timestamp);
								$minute = idate('i', $timestamp);
								$second = idate('s', $timestamp);
								$month += $context['adjustment_months'];
								while($month > 12) {
									$month -= 12;
									$year++;
								}
								while($month < 1) {
									$month += 12;
									$year--;
								}
								$try_count = 0;
								while((!checkdate($month, $day, $year)) && ($try_count < 5)) {
									$try_count++;
									$day--;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							}
							if(isset($context['adjustment_monthends'])) {
								$timestamp = intval($string);
								$month = idate('m', $timestamp);
								$year = idate('Y', $timestamp);
								$hour = idate('H', $timestamp);
								$minute = idate('i', $timestamp);
								$second = idate('s', $timestamp);
								$month += $context['adjustment_monthends'];
								while($month > 12) {
									$month -= 12;
									$year++;
								}
								while($month < 1) {
									$month += 12;
									$year--;
								}
								$day = 31;
								$try_count = 0;
								while((!checkdate($month, $day, $year)) && ($try_count < 5)) {
									$try_count++;
									$day--;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							}
							if(isset($context['adjustment_firstofmonths'])) {
								$timestamp = intval($string);
								$month = idate('m', $timestamp);
								$year = idate('Y', $timestamp);
								$hour = idate('H', $timestamp);
								$minute = idate('i', $timestamp);
								$second = idate('s', $timestamp);
								$month += $context['adjustment_firstofmonths'];
								while($month > 12) {
									$month -= 12;
									$year++;
								}
								while($month < 1) {
									$month += 12;
									$year--;
								}
								$day = 1;
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							}
							if(isset($context['adjustment_years'])) {
								$timestamp = intval($string);
								$month = idate('m', $timestamp);
								$day = idate('d', $timestamp);
								$year = idate('Y', $timestamp);
								$hour = idate('H', $timestamp);
								$minute = idate('i', $timestamp);
								$second = idate('s', $timestamp);
								$year += $context['adjustment_years'];
								$try_count = 0;
								while((!checkdate($month, $day, $year)) && ($try_count < 5)) {
									$try_count++;
									$day--;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							}
							break;
						case 'time':
							$days = 0;
							$hours = 0;
							$minutes = 0;
							$seconds = 0;
							$seconds_span = $string;
							if(strpos($context['format_string'], 'd')!==false) {
								$days = $seconds_span / (60 * 60 * 24);
								$days = intval($days);
								$seconds_span = $seconds_span - ($days * 60 * 60 * 24);
							}
							if(strpos($context['format_string'], 'h')!==false) {
								$hours = $seconds_span / (60 * 60);
								$hours = intval($hours);
								$seconds_span = $seconds_span - ($hours * 60 * 60);
							}
							if(strpos($context['format_string'], 'm')!==false) {
								$minutes = $seconds_span / 60;
								$minutes = intval($minutes);
								$seconds_span = $seconds_span - ($minutes * 60);
							}
							if(strpos($context['format_string'], 's')!==false) {
								$seconds = intval($seconds_span);
							}
							$string = $context['format_string'];
							$string = str_replace('d', $days, $string);
							$string = str_replace('hh', str_pad($hours, 2, '0', STR_PAD_LEFT), $string);
							$string = str_replace('h', $hours, $string);
							$string = str_replace('mm', str_pad($minutes, 2, '0', STR_PAD_LEFT), $string);
							$string = str_replace('m', $minutes, $string);
							$string = str_replace('ss', str_pad($seconds, 2, '0', STR_PAD_LEFT), $string);
							$string = str_replace('s', $seconds, $string);
							break;
						case 'pdate':
						case 'phpdate':
							$timestamp = intval($string);
							$string = date($context['format_string'], $timestamp);
						case 'date':
							// breaking the PHP standard for date format strings to support "M/D/Y h:mm:ss A" instead
							$timestamp = intval($string);
							// process the date format string for replacement tokens
							$string = '';
							while(strlen($context['format_string']) > 0) {
								// check for 2 character replacement tokens at the start of the remaining format string
								switch(substr($context['format_string'], 0, 2)) {
									case 'MM':
										// full month name
										$string .= date('F', $timestamp);
										$context['format_string'] = substr($context['format_string'], 2);
										continue;
									case 'DD':
										// day of month with suffix
										$string .= date('jS', $timestamp);
										$context['format_string'] = substr($context['format_string'], 2);
										continue;
									case 'hh':
										// 2 digit hours
										$string .= date('H', $timestamp);
										$context['format_string'] = substr($context['format_string'], 2);
										continue;
									case 'mm':
										// 2 digit minutes
										$string .= date('i', $timestamp);
										$context['format_string'] = substr($context['format_string'], 2);
										continue;
									case 'ss':
										// 2 digit seconds
										$string .= date('s', $timestamp);
										$context['format_string'] = substr($context['format_string'], 2);
										continue;
									default:
									// a 2 character replacement token was not found at the start of the remaining format string
								}
								// check for 1 character replacement tokens at the start of the remaining format string
								switch(substr($context['format_string'], 0, 1)) {
									case 'M':
										// 2 digit month number
										$string .= date('m', $timestamp);
										$context['format_string'] = substr($context['format_string'], 1);
										continue;
									case 'D':
										// 2 digit day of month number
										$string .= date('d', $timestamp);
										$context['format_string'] = substr($context['format_string'], 1);
										continue;
									case 'Y':
										// year number
										$string .= date('Y', $timestamp);
										$context['format_string'] = substr($context['format_string'], 1);
										continue;
									case 'h':
										// hour number
										$string .= date('g', $timestamp);
										$context['format_string'] = substr($context['format_string'], 1);
										continue;                 
									case 'm':
									    // minute number
										$string .= intval(date('i', $timestamp));
										$context['format_string'] = substr($context['format_string'], 1);
										continue;                 
									case 's':
									    // second number
										$string .= intval(date('s', $timestamp));
										$context['format_string'] = substr($context['format_string'], 1);
										continue;                 
									case 'A':
										// AM or PM
										$string .= date('A', $timestamp);
										$context['format_string'] = substr($context['format_string'], 1);
										continue;
									case '\\':
										// an escape character was at the start of the remaining format string
										// use the following character raw without replacing its token value
										$string .= substr($context['format_string'], 1, 1);
										$context['format_string'] = substr($context['format_string'], 2);
									default:
									// a valid token wasn't found, use the raw 1st character
									$string .= substr($context['format_string'], 0, 1);
									$context['format_string'] = substr($context['format_string'], 1);
								}
							}
							break;
						default:
					}
				} else {
					// this is a context without additional parameters
					switch($context) {
						case 'pre':
							$string = htmlspecialchars($string);
							break;
						case 'html':
						case 'web':
						case 'webpage':
						case 'websafe':
							$string = nl2br(htmlspecialchars($string));
							break;
						case 'url':
						case 'uri':
							$string = urlencode($string);
							break;
						case 'link':
							$string = htmlspecialchars(urlencode($string));
							break;
						case 'dollars':
						case 'usd':
						case '$':
							$string = '$ ' . number_format(round(preg_replace('/[^0-9\.-]+/', '', $string), 2), 2, '.', ',');
							break;
						case 'euros':
						case 'eur':
						case '€':
							$string = '€ ' . number_format(round(preg_replace('/[^0-9\.-]+/', '', $string), 2), 2, ',', '.');
							break;
						case 'number':
						case 'numeric':
						case 'decimal':
						case 'float':
						case 'long':
							$string = preg_replace('/[^0-9\.-]+/', '', $string);
							if(trim($string)=='') {
								$string = '0';
							}
							break;
						case 'integer':
						case 'int':
							$string = intval($string);
							break;
						case 'timespan':
							if(preg_match('/^([0-9]+)(:([0-9]{2})|)(:([0-9]{2})|)$/', $string, $matches)) {
								// matched h:mm:ss format, convert that to total number of seconds
								$string = intval($matches[5]) + (intval($matches[3]) * 60) + (intval($matches[1]) * 60 * 60);
							}
							break;
						case 'timestamp':
							if(preg_match('/^[0-9]+(\.[0-9]+|)$/', $string, $matches)) {
								// the value is already in decimal format... assume it is already a timestamp
							} elseif(preg_match('/^([0-9]+)\s*\/\s*([0-9]+)\s*\/\s*([0-9]+)\s*(([0-9]+)|)(\s*:\s*([0-9]+)|)(\s*:\s*([0-9]+)|)\s*(am|pm|a|p|)$/is', $string, $matches)) {
								// this will convert strings like "09/19/2013 8:30 AM" into seconds since midnight starting jan 1st, 1970
								$month = intval($matches[1]);
								$day = intval($matches[2]);
								$year = intval($matches[3]);
								$hour = intval($matches[5]);
								$minute = intval($matches[7]);
								$second = intval($matches[9]);
								$ampm = strtolower($matches[10]);
								if(($ampm=='pm' || $ampm=='p') && $hour!=12) {
									$hour += 12;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							} elseif(preg_match('/^([0-9]{4})\s*-\s*([0-9]{2})\s*-\s*([0-9]{2})\s*(([0-9]+)|)(\s*:\s*([0-9]+)|)(\s*:\s*([0-9]+)|)\s*(am|pm|a|p|)$/is', $string, $matches)) {
								// this will convert strings that match the system.date_time_short syntax like "1979-01-17 07:43:34" into seconds since midnight starting jan 1st, 1970
								$month = intval($matches[2]);
								$day = intval($matches[3]);
								$year = intval($matches[1]);
								$hour = intval($matches[5]);
								$minute = intval($matches[7]);
								$second = intval($matches[9]);
								$ampm = strtolower($matches[10]);
								if(($ampm=='pm' || $ampm=='p') && $hour!=12) {
									$hour += 12;
								}
								$string = mktime($hour, $minute, $second, $month, $day, $year);
							} else {
								// check for other date formats???
							}
							break;
						default:
					}
				}
			}
		}
	}

	// removes single line comments only, where the line starts with // with optional preceding whitespace
	public static function remove_comments(&$string) {
		$string_by_line = preg_split('/\r\n|\n|\r|' . PHP_EOL . '/s', $string);
		$cleansed_string = '';
		foreach($string_by_line as $line) {
			if(!preg_match('@^\s*//@', $line)) {
				// the line did not start with a comment
				$cleansed_string .= $line . "\n";
			}
		}
		$string = $cleansed_string;
		return $cleansed_string;
	}

}
