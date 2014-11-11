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
 * Parser for use with the EASE Core to process EASE & PHP code
 *
 * @author Mike <mike@cloudward.com>
 */
class ease_parser
{
	public $core;
	public $interpreter;
	public $override_url_params;
	public $unprocessed_body;
	public $output_buffer = '';
	public $errors = array();
	public $local_variables = array();
	// these functions are made directly callable by any PHP blocks in EASE
	public $php_functions = array(
		'ease_get_value', 'ease_set_value', 'ease_process', 'ease_html_dump', 'ease_new_uuid',
		'ease_db_query', 'ease_db_query_params', 'ease_db_exec', 'ease_db_error',
		'ease_db_fetch', 'ease_db_fetch_all', 'ease_db_fetch_column',
		'ease_db_create_instance', 'ease_db_set_instance_value',
		'ease_db_get_instance', 'ease_db_get_instance_value',
		'ease_insert_row_into_googlesheet_by_id', 'ease_insert_row_into_googlesheet_by_name',
		'ease_update_row_in_googlesheet_by_id', 'ease_update_row_in_googlesheet_by_name',
		'ease_delete_row_from_googlesheet_by_id', 'ease_delete_row_from_googlesheet_by_name',
		'ease_empty_bucket', 'ease_array_to_bucket', 'ease_bucket_to_array'
	);

	function __construct(&$core, $override_url_params=null) {
		$this->core = $core;
		$this->override_url_params = $override_url_params;
		$this->interpreter = null;
	}

	/**
	 * Process a body of text as EASE
	 * 
	 * @param $body string - text to process
	 * @param $return_output_buffer boolean default false - set to true to return the response rather than append it to the output buffer
	*/
	function process($body, $return_output_buffer=false) {
		// ensure an EASE Interpreter has been initiated for injecting EASE variable values and applying contexts
		if($this->interpreter===null) {
			require_once 'ease/interpreter.class.php';
			$this->interpreter = new ease_interpreter($this, $this->override_url_params);
		}
		// if the debug flag is set, cache the original version of the body so it can be included in the response
		// debug is only available on non-production environments
		if($this->core->environment!='PROD' && isset($_REQUEST['debug']) && $_REQUEST['debug']) {
			$original_body = $body;
		}
		// initialize a buffer to hold the remaining body to be processed
		$this->unprocessed_body = $body;
		// initialize a buffer to hold the output from processing
		$this->output_buffer = '';
		// process the remaining body line by line, injecting EASE variables and scanning for the start sequence of EASE or PHP
		while(sizeof($this->unprocessed_body) > 0) {
			// find the next end-of-line character in the remaining body
			$new_line_position = strpos($this->unprocessed_body, "\n");
			if($new_line_position===false) {
				// an end-of-line character was not found, process the entire remaining body as the last line
				$new_line_position = strlen($this->unprocessed_body);
				if($new_line_position==0) {
					// the last line is blank... done.
					$this->output_buffer .= "\n";
					break;
				}
			}
			// ignore blank lines
			if($new_line_position==0) {
				// the line is blank... output a blank line and continue processing the next line
				$this->output_buffer .= "\n";
				$this->unprocessed_body = substr($this->unprocessed_body, 1);
				continue;
			}
			// store the first line in the remaining body for further processing
			$body_line = substr($this->unprocessed_body, 0, $new_line_position);
			// ignore lines containing only whitespace
			if(sizeof(trim($body_line))==0) {
				// the line contained only whitespace... output the whitespace and continue processing the next line
				$this->output_buffer .= $body_line;
				$this->unprocessed_body = substr($this->unprocessed_body, $new_line_position);
				continue;
			}
			// scan the line for the start sequence of PHP
			// ignore <?php immediately followed by ' or " so it can be used in javascript strings, ex:	var php_start = "<?php";
			while(preg_match('/(.*?)(' . preg_quote('<?php', '/') . '\s*([^\s\'"].*|$))/i', $body_line, $matches)) {
				// start sequence of PHP was found, process the preceeding text for global variable references
				// inject any global variables... if anything is injected, scan the EASE body line again for the start sequence of EASE
				$injected = $this->interpreter->inject_global_variables($matches[1]);
				if($injected) {
					// a global variable reference was injected into the content preceeding the start sequence of EASE
					$body_line = $matches[1] . $matches[2];
				} else {
					// no global variable references were found; ready to process the PHP Block
					break;
				}
			}
			// check if the start sequence of PHP was found
			if(@strlen($matches[2]) > 0) {
				// the start sequence of PHP was found; inject any preceeding text to the output buffer
				$this->output_buffer .= $matches[1];
				// strip any text preceeding the start sequence of PHP from the remaining unprocessed body
				$this->unprocessed_body = $matches[2] . "\n" . substr($this->unprocessed_body, $new_line_position);
				// the remaining uprocessed body now begins with a PHP Block; process the PHP Block
				$this->process_php_block();
				continue;
			}
			// scan the line for the start sequence of EASE
			if(!preg_match('/(.*?)(' . preg_quote($this->core->ease_block_start, '/') . '\s*([^\s\[\'"].*|$))/i', $body_line)) {
				// start sequence of EASE was not found... inject global variables then check again
				$this->interpreter->inject_global_variables($body_line);
			}
			while(preg_match('/(.*?)(' . preg_quote($this->core->ease_block_start, '/') . '\s*([^\s\[\'"].*|$))/i', $body_line, $matches)) {
				// start sequence of EASE was found, process the preceeding text for global variable references
				// inject any global variables... if anything is injected, scan the EASE body line again for the start sequence of EASE
				$injected = $this->interpreter->inject_global_variables($matches[1]);
				if($injected) {
					// a global variable reference was injected into the content preceeding the start sequence of EASE
					$body_line = $matches[1] . $matches[2];
				} else {
					// no global variable references were found; ready to interpret the EASE block
					break;
				}
			}
			// check if the start sequence of EASE was found
			if(@strlen($matches[2]) > 0) {
				// the start sequence of EASE was found; inject any preceeding text to the output buffer
				$this->output_buffer .= $matches[1];
				// strip any text preceeding the start sequence of EASE from the remaining unprocessed body
				$this->unprocessed_body = $matches[2] . "\n" . substr($this->unprocessed_body, $new_line_position);
				// the remaining uprocessed body now begins with the start sequence of EASE; process the EASE block
				$this->process_ease_block();
			} else {
				// the line did not contain the start sequence of EASE;  inject the line into the output buffer
				$this->output_buffer .= $body_line;
				// remove the line from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, $new_line_position);
			}
			// process the next line of the remaining unprocessed body, injecting EASE variables and processing EASE & PHP code
			continue;
		}
		// DEBUG INFO - only shown on non-production servers where the debug flag was set
		if($this->core->environment!='PROD' && isset($_REQUEST['debug']) && $_REQUEST['debug']) {
			// debug was requested... dump the original EASE and the generated response, to both show in an HTML context
			$this->output_buffer = "original EASE:<br />\n"
			 . "<pre style='margin-top:4px;'><div style='margin-left:10px; padding:2px; border:1px solid #000000; background-color:#FFCCCC;'>"
			 . htmlspecialchars($original_body)
			 . "</div></pre>generated response:<br />\n"
			 . "<pre style='margin-top:4px;'><div style='margin-left:10px; padding:2px; border:1px solid #000000; background-color:#CCFFCC;'>"
			 . htmlspecialchars($this->output_buffer)
			 . "</div></pre><hr style='border:0px; border-top:1px dashed #C0C0C0; height:1px; margin-top:6px; margin-bottom:10px; width:95%;' />"
			 . ((count($this->errors) > 0) ? 'EASE PARSE ERRORS: <pre> ' . htmlspecialchars(print_r($this->errors, true)) . '</pre>' : '')
			 . $this->output_buffer;
		}
		// done processing... either return the generated response or echo it
		if($return_output_buffer) {
			return $this->output_buffer;
		} else {
			echo $this->output_buffer;
		}
	}

	// this function should only be called when $this->unprocessed_body begins with an EASE start sequence
	function process_ease_block() {
		// continue processing as long as the EASE Block has content remaining
		while(true) {
			// check for an EASE tag containing only whitespace
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}
			// check for a multi-line EASE comment tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*:(.*?):\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// remove the multi-line EASE comment from the unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}
			// check for a single line EASE comment starting an EASE block
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*\/\/(\V*)/is', $this->unprocessed_body, $matches)) {
				// remove the comment line from the start of the EASE block and continue processing the remaining tag
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			//	find the first valid EASE Command

			###############################################
			##	SYSTEM VARIABLE INJECTION - legacy support for <# system.value #> instead of <#[system.value]#>
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*system\s*\.\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// process the system variable according to context
				$system_variable_key = $matches[1];
				$context_stack = $this->interpreter->extract_context_stack($system_variable_key);
				$value = $this->core->globals['system.' . $system_variable_key];
				$this->interpreter->apply_context_stack($value, $context_stack);
				// inject the contextual value into the output buffer
				$this->output_buffer .= $value;
				// remove the SYSTEM VARIABLE INJECTION tag from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	START TIMER - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+timer\s*[\.;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '\s*/is', $this->unprocessed_body, $matches)) {
				$time = explode(' ', microtime());
				$this->core->globals['system.timer_start'] = $time[1] + $time[0];
				// remove the START TIMER tag from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	PRINT TIMER - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*print\s+timer\s*[\.;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '\s*/is', $this->unprocessed_body, $matches)) {
				$time = explode(' ', microtime());
				$end_time = $time[1] + $time[0];
				// inject the elapsed time into the output buffer as seconds
				$this->output_buffer .= number_format($end_time - $this->core->globals['system.timer_start'], 5, '.', ',') . ' seconds';
				// remove the PRINT TIMER tag from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	PRINT "QUOTED-STRING" - optional AS CONTEXT
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*print\s*"(.*?)"(\s*[^\.;]*[\.;]{0,1})\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the value to print
				$this->interpreter->inject_global_variables($matches[1]);
				// remove any trailing . or ; characters, then determine the context
				$print_context = rtrim($matches[2], '.;');
				$context_stack = $this->interpreter->extract_context_stack($print_context);
				// apply any context to the value then inject it into the output buffer
				$this->interpreter->apply_context_stack($matches[1], $context_stack);
				// injected the value into the output buffer
				$this->output_buffer .= $matches[1];
				// remove the PRINT command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	GRANT ACCESS / REVOKE ACCESS
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(grant|give|permit|allow|revoke)\s*access\s*to\s+([^;]+?)\s*([;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '|;)\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the session.key lock reference
				$this->interpreter->inject_global_variables($matches[2]);
				$matches[2] = trim(preg_replace('/[^a-z0-9]+/is', '_', strtolower($matches[2])), '_');
				switch(strtolower($matches[1])) {
					case 'revoke':
						unset($_SESSION['ease_memberships.' . $matches[2]]);
						break;
					default:
					$_SESSION['ease_memberships.' . $matches[2]] = 'unlocked';
				}
				// remove the tag from the remaining unprocessed body
				$this->unprocessed_body = ($matches[3]==';' ? $this->core->ease_block_start : '') . substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}
		
			###############################################
			##	RESTRICT ACCESS - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*restrict\s*access\s*to\s+([^;]+?)\s*using\s*(.*?)\s*[\.;]{0,1}\s*(?<!' . preg_quote($this->core->global_reference_end, '/') . ')\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the session.key lock reference, and login page
				$this->interpreter->inject_global_variables($matches[1]);
				$matches[1] = trim(preg_replace('/[^a-z0-9]+/is', '_', strtolower($matches[1])), '_');
				if(!isset($_SESSION['ease_memberships.' . $matches[1]]) || !$_SESSION['ease_memberships.' . $matches[1]]) {
					// the current user is restricted from accessing this page any further, redirect to an authentication page or the homepage
					$this->interpreter->inject_global_variables($matches[2]);
					// add the restricted page URL to the query string of the access handler page URL
					$matches[2] .= (strpos($matches[2], '?')===false ? '?' : '&') . 'restricted_page=' . urlencode($_SERVER['REQUEST_URI']);
					if($this->core->catch_redirect) {
						// EASE Framework configured to catch redirects
						$this->core->redirect = $matches[2];
						// halt processing anything after the redirect
						$this->unprocessed_body = '';
						return;
					} else {
						// redirect to the Location URI for the authentication page, halting all further processing of this request
						header('Location: ' . $matches[2]);
						exit;
					}
				} else {
					// the user has been granted access
					// remove the RESTRICT tag from the remaining unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
					return;
				}
			}

			###############################################
			##	DELIVER FILE - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(d(eliver|ownload))\s*(file|)\s+"\s*(.+?)\s*"\s*as\s*"\s*(.+?)\s*"\s*[\.;]{0,1}\s*(?<!' . preg_quote($this->core->global_reference_end, '/') . ')\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				if(!$this->core->deliver_disabled) {
					// inject any global variables into the file path and name
					$this->interpreter->inject_global_variables($matches[4]);
					$this->interpreter->inject_global_variables($matches[5]);
					// deliver the file
					if(preg_match('@^s3://(.*?)/(.*)$@is', $matches[4], $inner_matches) 
					  && isset($this->core->config['s3_access_key_id'])
					  && trim($this->core->config['s3_access_key_id'])!=''
					  && isset($this->core->config['s3_secret_key'])
					  && trim($this->core->config['s3_secret_key'])!='') {
						// this is an S3 file URL... instead of always loading an S3 stream wrapper, handle these URLs seperately
						require_once 'ease/lib/S3.php';
						$s3 = new S3($this->core->config['s3_access_key_id'], $this->core->config['s3_secret_key']);
						if($s3_file = $s3->getObject($inner_matches[1], $inner_matches[2])) {
						    ob_clean();
						    header('Content-Description: File Transfer');
						    header('Content-Type: ' . $s3_file->headers['type']);
						    header('Content-Disposition: attachment; filename=' . basename($inner_matches[2]));
						    header('Expires: 0');
						    header('Cache-Control: must-revalidate');
						    header('Pragma: public');
						    header('Content-Length: ' . $s3_file->headers['size']);
							echo $s3_file->body;
						    exit;
						}
					} elseif(file_exists($matches[4])) {
					    ob_clean();
					    header('Content-Description: File Transfer');
					    header('Content-Type: application/octet-stream');
					    header("Content-Disposition: attachment; filename={$matches[5]}");
					    header('Expires: 0');
					    header('Cache-Control: must-revalidate');
					    header('Pragma: public');
					    header('Content-Length: ' . filesize($matches[4]));
					    readfile($matches[4]);
					    exit;
					} else {
						// invalid file path
					}
				}
				// remove the DELIVER tag from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
			}

			###############################################
			##	INCLUDE "FILE-PATH" - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*include\s*"\s*(.*?)\s*"\s*[\.;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the supplied INCLUDE path
				$this->interpreter->inject_global_variables($matches[1]);
				// only include the path content if includes haven't been disabled
				if($this->core->include_disabled) {
					// include commands have been disabled
					$included_file_content = '';
				} elseif(isset($this->core->include_from_sql) && trim($this->core->include_from_sql)!='') {
					// SQL was provided to pull include content from the database
					$query = $this->core->db->prepare($this->core->include_from_sql);
					if($query->execute(array(':path'=>$matches[1]))) {
						if($row = $query->fetch(PDO::FETCH_ASSOC)) {
							list($key, $val) = each($row);
							$included_file_content = $val;
						}
					}
				} elseif($matches[1]!='header.espx' && $matches[1]!='footer.espx') {
					// the include path will be treated as a file path, and isn't doubly including the local header.espx or footer.espx
					$include_file_path = str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
					if(substr($matches[1], 0, 1)=='/' && file_exists($this->core->application_root . $include_file_path)) {
						$included_file_content = @file_get_contents($this->core->application_root . $include_file_path);
					} else {
						$included_file_content = @file_get_contents($include_file_path, FILE_USE_INCLUDE_PATH);
					}
				} else {
					// the local header.espx or footer.espx was referenced... it is included by default, so don't include it again
					$included_file_content = '';
				}
				// replace the INCLUDE tag from the remaining unprocessed body with the contents of the included file
				$this->unprocessed_body = $included_file_content . substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	INCLUDE GOOGLE DOC - only works as standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*include\s*(processed|stripped|raw|)\s*(and|)\s*(processed|stripped|raw|)\s*google\s*doc\s*("\s*(.*?)\s*"|([^;]+))\s*[\.;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// default to stripping the Google Doc out of the HTML container and processing the style to localize it
				$strip_doc = true;
				if((isset($matches[1]) && strtolower(trim($matches[1]))=='stripped') || (isset($matches[3]) && strtolower(trim($matches[3]))=='stripped')) {
					$strip_doc = true;
				}
				if((isset($matches[1]) && strtolower(trim($matches[1]))=='raw') || (isset($matches[3]) && strtolower(trim($matches[3]))=='raw')) {
					$strip_doc = false;
				}
				// default to not further processing the Google Doc as EASE
				$process_doc = false;
				if((isset($matches[1]) && strtolower(trim($matches[1]))=='processed') || (isset($matches[3]) && strtolower(trim($matches[3]))=='processed')) {
					$process_doc = true;
				}
				if(isset($matches[5]) && trim($matches[5])!='') {
					// inject any global variables into the supplied Google Doc "Name"
					$this->interpreter->inject_global_variables($matches[5]);
				} else {
					// inject any global variables into the supplied Google Doc ID
					$this->interpreter->inject_global_variables($matches[6]);
				}
				// validate the Google Drive API Access Token, and refresh it if necessary
				$this->core->validate_google_access_token();
				require_once 'ease/lib/Google/Client.php';
				$client = new Google_Client();
				$client->setClientId($this->core->config['gapp_client_id']);
				$client->setClientSecret($this->core->config['gapp_client_secret']);
				$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
				$client->setAccessToken($this->core->config['gapp_access_token_json']);
				if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
					// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
					require_once 'ease/lib/Google/Cache/Null.php';
					$cache = new Google_Cache_Null($client);
					$client->setCache($cache);
				} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
					// an external memcache host was configured, pass on that configuration to the Google API Client
					$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
					if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
						$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
					} else {
						$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
					}
					require_once 'ease/lib/Google/Cache/Memcache.php';
					$cache = new Google_Cache_Memcache($client);
					$client->setCache($cache);
				}
				require_once 'ease/lib/Google/Service/Drive.php';
				$service = new Google_Service_Drive($client);
				$files = null;
				$file = null;			
				$try_count = 0;
				while($file===null && $try_count<=5) {
					if($try_count > 0) {
						// apply exponential backoff to retry Google Drive API requests up to 5 times
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					try {
						$try_count++;
						if(isset($matches[5]) && trim($matches[5])) {
							// a Google Doc "Name" was provided... look up the file by title
						 	$files = $service->files->listFiles(array('q'=>"mimeType = 'application/vnd.google-apps.document' and title = '" . str_replace("'", "\\'", str_replace("\\", "\\\\", $matches[5])) . "' and trashed = false"));
							if(isset($files) && count($files) > 0) {
								foreach($files as $file) {
									// loaded the first matching file.  reset() and each() systax doesn't work... but foreach does...
									break;
								}
							} else {
								$file = false;
							}
						} else {
							// a Google Doc ID was provided... look up the file by ID
							$file = $service->files->get($matches[6]);
						}
					} catch(Google_Service_Exception $e) {
						continue;
					}
				}
				// remove the INCLUDE GOOGLE DOC tag from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				// return any file content either to output buffer or unprocessed body, optionally stripping HTML containers
				if(isset($file) && $file!=false) {
					$export_links = $file->getExportLinks();
					$downloadUrl = $export_links['text/html'];
					$request = new Google_Http_Request($downloadUrl);
					$http_request = $client->getAuth()->authenticatedRequest($request);
		         	if($http_request->getResponseHttpCode()==200) {
						if($strip_doc) {
							// strip out the HTML container around the content, leaving the style
							$file_content = $http_request->getResponseBody();
							if(preg_match("@^\s*<html>\s*<head>.*?<style(.*?)>(.*?)</style>.*?</head>\s*<body.*?>(.*)</body>\s*</html>\s*$@is", $file_content, $file_content_html_matches)) {
								// a standard HTML container was found, strip out the style and body content
								// update every style declaration to be scoped to a newly generated ID
								$css_scope = $this->core->new_uuid();
								$css_scope = 'ease_' . substr($css_scope, 0, 11);
								$file_content_html_matches[2] = preg_replace_callback(
									'@([^;]*?)\{(.*?)\}@is', 
									function($matches) use ($css_scope) {
										if($matches[1]=='.title') {
											$matches[2] = str_replace('text-align:left;', '', $matches[2]);
										}
										return "#$css_scope {$matches[1]}{{$matches[2]}}";
									},
									$file_content_html_matches[2]
								);
								// Google makes all HTML links relay through them... remove the evil
								$file_content_html_matches[3] = preg_replace_callback(
									'@<a(.*?)href="http(?:s|)://www.google.com/url\?q=([^&"]+).*?"(.*?)>@is', 
									function($matches) {
										return "<a{$matches[1]}href=\"" . htmlspecialchars(urldecode($matches[2])) . "\"{$matches[3]}>";
									},
									$file_content_html_matches[3]
								);
								$file_content = "<div id='$css_scope'><style{$file_content_html_matches[1]}>{$file_content_html_matches[2]}</style>{$file_content_html_matches[3]}</div>";
							}							
							if($process_doc) {
								// find any EASE start or end sequences that might have been HTML encoded, and convert them back
								// prepend the result to the unprocessed body, then return
								$this->unprocessed_body = preg_replace(array('/' . preg_quote(htmlspecialchars($this->core->ease_block_start), '/') . '/', '/' . preg_quote(htmlspecialchars($this->core->ease_block_end), '/') . '/'), array($this->core->ease_block_start, $this->core->ease_block_end), $file_content) . $this->unprocessed_body;
							} else {
								// the Google Doc was not set to be processed, so append the file content to the output buffer
								$this->output_buffer .= $file_content;
							}
						} else {
							// Google Doc was not requested to be stripped of the HTML container
							if($process_doc) {
								// find any EASE start or end sequences that might have been HTML encoded, and convert them back
								// prepend the result to the unprocessed body, then return
								$this->unprocessed_body = preg_replace(array('/' . preg_quote(htmlspecialchars($this->core->ease_block_start), '/') . '/', '/' . preg_quote(htmlspecialchars($this->core->ease_block_end), '/') . '/'), array($this->core->ease_block_start, $this->core->ease_block_end), $http_request->getResponseBody()) . $this->unprocessed_body;
							} else {
								// the Google Doc was not set to be processed, so append the file content to the output buffer
								$this->output_buffer .= $http_request->getResponseBody();
							}
						}
					}
				}
				return;
			}

			###############################################
			##	IMPORT GOOGLE SHEET INTO SQL TABLE
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*import\s+(google\s*drive|google\s*docs|g\s*drive|g\s*docs|google|g|)\s*(spread|)sheet(\s*"\s*(.+?)\s*"\s*|\s+([\w-]+))(\s*"\s*(.+?)\s*"\s*|)into\s+([^;]+?)\s*;\s*(.*?;\h*(\v+\s*\/\/\V*)*+)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$this->core->validate_google_access_token();
				// inject any global variables into the Google Sheet name
				$this->interpreter->inject_global_variables($matches[4]);
				if(trim($matches[4])!='') {
					$spreadsheet_name = $matches[4];
				} else {
					$spreadsheet_name = null;
				}
				$this->interpreter->inject_global_variables($matches[5]);
				if(trim($matches[5])!='') {
					$spreadsheet_id = $matches[5];
				} else {
					$spreadsheet_id = null;
				}
				$this->interpreter->inject_global_variables($matches[7]);
				if(trim($matches[7])!='') {
					$worksheet_name = $matches[7];
				} else {
					$worksheet_name = null;
				}
				$this->interpreter->inject_global_variables($matches[8]);
				$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[8])), '_');
				$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
				$unprocessed_import_block = $matches[9];
				// initialize import settings
				$sheet_has_header_row = true;
				$preserve_existing_records = false;
				$sheet_column_header_to_sql_column_map = array();
				$sheet_column_header_to_sql_column_keys = array();
				$sheet_column_letter_to_sql_column_map = array();
				$sheet_column_letter_to_sql_column_keys = array();
				$referenced_sql_columns = array();
				// process the remaining IMPORT SPREADSHEET block as long as the next command is an import directive
				$continue_parsing_import_directives = true;
				while($continue_parsing_import_directives) {
					if(preg_match('/^\s*\/\/([^\v]*?)(\v+|$)/is', $unprocessed_import_block, $inner_matches)) {
						$unprocessed_import_block = substr($unprocessed_import_block, strlen($inner_matches[0]));
					} elseif(preg_match('/^\s*(keep|preserve|save)\s*(current|existing|)\s*(records|rows|instances|data|stuff)\s*;\s*/is', $unprocessed_import_block, $inner_matches)) {
						$preserve_existing_records = true;
						$unprocessed_import_block = substr($unprocessed_import_block, strlen($inner_matches[0]));
					} elseif(preg_match('/^\s*no\s*header\s*row\s*;\s*/is', $unprocessed_import_block, $inner_matches)) {
						$sheet_has_header_row = false;
						$unprocessed_import_block = substr($unprocessed_import_block, strlen($inner_matches[0]));
					} elseif(preg_match('/^\s*map\s*("\s*(.*?)\s*"|([a-z]+))\s*(to\s*([^;]+?)\s*|)(as\s*key|)\s*;\s*/is', $unprocessed_import_block, $inner_matches)) {
						if(trim($inner_matches[4])!='') {
							$this->interpreter->inject_global_variables($inner_matches[5]);
							$inner_matches[5] = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($inner_matches[5])), '_');
							$referenced_sql_columns[$inner_matches[5]] = true;
							$map_direct = false;
						} else {
							$map_direct = true;
						}
						if(trim($inner_matches[2])!='') {
							// sheet column was referenced by double-quoted header name
							$this->interpreter->inject_global_variables($inner_matches[2]);
							$inner_matches[2] = preg_replace('/[^a-z0-9]+/s', '_', strtolower($inner_matches[2]));
							if($map_direct) {
								$inner_matches[5] = $inner_matches[2];
								$referenced_sql_columns[$inner_matches[5]] = true;
							}
							$sheet_column_header_to_sql_column_map[$inner_matches[2]] = $inner_matches[5];
							if(trim($inner_matches[6])!='') {
								$sheet_column_header_to_sql_column_keys[$inner_matches[2]] = $inner_matches[5];
							}
						} elseif(trim($inner_matches[3])!='') {
							// sheet column was referenced by letter
							$this->interpreter->inject_global_variables($inner_matches[3]);
							$inner_matches[3] = preg_replace('/[^A-Z]+/s', '', strtoupper($inner_matches[3]));
							if($map_direct) {
								$inner_matches[5] = $inner_matches[3];
								$referenced_sql_columns[$inner_matches[5]] = true;
							}
							$sheet_column_letter_to_sql_column_map[$inner_matches[3]] = $inner_matches[5];
							if(trim($inner_matches[6])!='') {
								$sheet_column_letter_to_sql_column_keys[$inner_matches[3]] = $inner_matches[5];
							}
						}
						$unprocessed_import_block = substr($unprocessed_import_block, strlen($inner_matches[0]));
					} else {
						$continue_parsing_import_directives = false;
					}
				}
				// remove the IMPORT SPREADSHEET block from the remaining unprocessed body
				if(strlen(trim($unprocessed_import_block)) > 0) {
					$this->unprocessed_body = $this->core->ease_block_start . $unprocessed_import_block . $this->core->ease_block_end . substr($this->unprocessed_body, strlen($matches[0]));
				} else {
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				}	
				// only import the Google Sheet if the DB hasn't been disabled
				if(!$this->core->db_disabled) {
					// load the sheet meta data
					if($spreadsheet_name) {
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($spreadsheet_name, $worksheet_name);
						if(isset($spreadsheet_meta_data['id'])) {
							$spreadsheet_id = $spreadsheet_meta_data['id'];
						}
					} elseif($spreadsheet_id) {
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_id, $worksheet_name);
					}
					// initialize a Google Drive API client for Google Sheets
					require_once 'ease/lib/Spreadsheet/Autoloader.php';
					$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
					$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
					Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
					$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
					// determine if the Google Sheet was referenced by "Name" or ID
					if(isset($spreadsheet_id) && $spreadsheet_id!='') {
						// load Google Sheet by ID
						try {
							$spreadSheet = $spreadsheetService->getSpreadsheetById($spreadsheet_id);
						} catch(Exception $e) {
							// error loading spreadsheet by ID
						}
						if($spreadSheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load Google Sheet by ID: " . htmlspecialchars($spreadsheet_id) . "</div>";
							return;
						}
					} elseif(isset($spreadsheet_name) && $spreadsheet_name!='') {
						// load Google Sheet by Title
						$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
						$spreadSheet = $spreadsheetFeed->getByTitle($spreadsheet_name);
						if($spreadSheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load Google Sheet by Title: " . htmlspecialchars($spreadsheet_name) . "</div>";
							return;
						}
					} else {
						$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Import Google Sheet → Missing reference to Spreadsheet ID or Title</div>";
						return;
					}
					// if a worksheet was named, use it, otherwise use the first sheet
					$worksheetFeed = $spreadSheet->getWorksheets();
					if($worksheet_name) {
						$worksheet = $worksheetFeed->getByTitle($worksheet_name);
						if($worksheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load worksheet named: " . htmlspecialchars($worksheet_name) . "</div>";
							return;
						}
					} else {
						$worksheet = $worksheetFeed->getFirstSheet();
					}
					// request all cell data from the worksheet
					$cellFeed = $worksheet->getCellFeed();
					$cell_entries = $cellFeed->getEntries();
					$cells_by_row_by_column_letter = array();
					foreach($cell_entries as $cell_entry) {
						$cell_title = $cell_entry->getTitle();
						preg_match('/([A-Z]+)([0-9]+)/', $cell_title, $inner_matches);
						$cells_by_row_by_column_letter[$inner_matches[2]][$inner_matches[1]] = $cell_entry->getContent();
						$referenced_sheet_column_letters[$inner_matches[1]] = true;
					}
					// query for current columns for the SQL Table
					$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_sql_table_name' AND TABLE_SCHEMA=database();");
					$existing_sql_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					if(!is_array($existing_sql_columns) || count($existing_sql_columns)==0) {
						$preserve_existing_records = false;
					}
					// build sql query strings and parameter maps
					$uuid_column = null;
					$default_query_params = array();
					$created_sql_columns = array();
					$column_header_by_letter = array();
					$column_letter_by_header = array();
					$create_columns_sql = '';
					$query_param_map_sql = '';
					if($sheet_has_header_row) {
						// process the header row (row 1) from the sheet
						foreach($cells_by_row_by_column_letter[1] as $column_letter=>$column_header) {
							$default_query_params[":$column_letter"] = '';
							$column_header = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($column_header)), '_');
							$column_header_by_letter[$column_letter] = $column_header;
							$column_letter_by_header[$column_header] = $column_letter;
							if($column_header=='ease_row_id') {
								// special EASE interoperability handling of the "EASE Row ID" column
								$uuid_column = $column_letter;
							} else {
								// check if this column or letter has been explicitly mapped
								if(isset($sheet_column_header_to_sql_column_map[$column_header])) {
									// this column has been explicitly mapped by column header
									if(!in_array($sheet_column_header_to_sql_column_map[$column_header], $this->core->reserved_sql_columns) && !isset($created_sql_columns[$sheet_column_header_to_sql_column_map[$column_header]])) {
										$create_columns_sql .= ", `{$sheet_column_header_to_sql_column_map[$column_header]}` text NOT NULL default ''";
										$created_sql_columns[$sheet_column_header_to_sql_column_map[$column_header]] = true;
									}
									$query_param_map_sql .= ", `{$sheet_column_header_to_sql_column_map[$column_header]}`=:$column_letter";
								} elseif(isset($sheet_column_letter_to_sql_column_map[$column_letter])) {
									// this column has been explicitly mapped by column letter
									if(!in_array($sheet_column_letter_to_sql_column_map[$column_letter], $this->core->reserved_sql_columns) && !isset($created_sql_columns[$sheet_column_letter_to_sql_column_map[$column_letter]])) {
										$create_columns_sql .= ", `{$sheet_column_letter_to_sql_column_map[$column_letter]}` text NOT NULL default ''";
										$created_sql_columns[$sheet_column_letter_to_sql_column_map[$column_letter]] = true;
									}
									$query_param_map_sql .= ", `{$sheet_column_letter_to_sql_column_map[$column_letter]}`=:$column_letter";
								} else {
									// this column has not been mapped... default to map to column with same name
									if(!in_array($column_header, $this->core->reserved_sql_columns) && !isset($created_sql_columns[$column_header])) {
										$create_columns_sql .= ", `$column_header` text NOT NULL default ''";
										$created_sql_columns[$column_header] = true;
									}
									$query_param_map_sql .= ", `$column_header`=:$column_letter";
								}
							}
						}
						// done processing the header row
						unset($cells_by_row_by_column_letter[1]);
					} else {
						// TODO! there is no header row, so process the sheet to determine if any columns don't have mappings
						foreach(array_keys($referenced_sheet_column_letters) as $referenced_sheet_column_letter) {

						}
					}
					// wipe existing SQL Table unless it was explicitly preserved
					if($preserve_existing_records) {
						// add any new columns to the SQL table
						foreach(array_keys($referenced_sql_columns) as $referenced_sql_column) {
							if(!in_array($referenced_sql_column, $existing_sql_columns)) {
								$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$referenced_sql_column` text NOT NULL default '';");
							}
						}
					} else {
						if(is_array($existing_sql_columns) && count($existing_sql_columns) > 0) {
							// wipe the existing SQL Table
							$this->core->db->exec("DROP TABLE `$namespaced_sql_table_name`;");
						}
						// create the SQL Table
						$sql = "CREATE TABLE `$namespaced_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$create_columns_sql
								);	";
						$this->core->db->exec($sql);
					}
					// process each row in the Google Sheet to insert a new record in the SQL Table
					if($uuid_column) {
						// the "EASE Row ID" column was set in the sheet, use that as the primary key
						$query = $this->core->db->prepare("REPLACE INTO `$namespaced_sql_table_name` SET uuid=:$uuid_column $query_param_map_sql;");
						foreach($cells_by_row_by_column_letter as $row_number=>$row) {
							$params = $default_query_params;
							foreach($row as $column_letter=>$value) {
								$params[":$column_letter"] = $value;
							}
							$result = $query->execute($params);
						}
					} else {
						// custom key mappings... query for existing records
						$key_columns_sql = 'uuid';
						$where_clause_sql = '';
						if(is_array($sheet_column_header_to_sql_column_keys) && count($sheet_column_header_to_sql_column_keys) > 0) {
							$key_columns_sql .= ',`' . implode('`,`', $sheet_column_header_to_sql_column_keys) . '`';
							foreach($sheet_column_header_to_sql_column_keys as $column_header=>$sql_column) {
								$where_clause_sql .= " AND `$sql_column`=:" . $column_letter_by_header[$column_header];
							}
						}
						if(is_array($sheet_column_letter_to_sql_column_keys) && count($sheet_column_letter_to_sql_column_keys) > 0) {
							$key_columns_sql .= ',`' . implode('`,`', $sheet_column_letter_to_sql_column_keys) . '`';
							foreach($sheet_column_letter_to_sql_column_keys as $column_letter=>$sql_column) {
								$where_clause_sql .= " AND `$sql_column`=:$column_letter";
							}
						}
						$where_clause_sql = substr($where_clause_sql, 5);  // remove the initial " AND " from the where clause
						// prepare an  update and a replace statement... execute the update first, if that fails insert a new record
						$update_query = $this->core->db->prepare("UPDATE `$namespaced_sql_table_name` SET updated_on=NOW() $query_param_map_sql WHERE $where_clause_sql;");
						$insert_query = $this->core->db->prepare("REPLACE INTO `$namespaced_sql_table_name` SET uuid=:uuid $query_param_map_sql;");
						foreach($cells_by_row_by_column_letter as $row_number=>$row) {
							$params = $default_query_params;
							foreach($row as $column_letter=>$value) {
								$params[":$column_letter"] = $value;
							}
							$result = $update_query->execute($params);
							if($update_query->rowCount()==0) {
								// update failed, insert a new record instead
								$params[':uuid'] = $this->core->new_uuid();
								$result = $insert_query->execute($params);
							}
						}
					}
				}
				return;
			}

			###############################################
			##	EXPORT SQL TABLE INTO GOOGLE SHEET
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*export\s+([^\.;]+?)\s+into\s+(google\s*drive|g\s*drive|google|g|)\s*(spread|)sheet(\s*"\s*(.+?)\s*"\s*|\s+([\w-]+))(\s*"\s*(.+?)\s*"\s*|)\s*(\.|;){0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// this command requires use of the Google API, ensure an access token is held and valid, refreshing the access token if necessary
				$this->core->validate_google_access_token();
				// remove the EXPORT SQL TABLE command from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				// process the EXPORT SQL TABLE directives, injecting global variables
				$this->interpreter->inject_global_variables($matches[1]);
				$sql_table_name = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1]));
				$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
				$this->interpreter->inject_global_variables($matches[5]);
				if(trim($matches[5])!='') {
					$spreadsheet_name = $matches[5];
				} else {
					$spreadsheet_name = null;
				}
				$this->interpreter->inject_global_variables($matches[6]);
				if(trim($matches[6])!='') {
					$spreadsheet_id = $matches[6];
				} else {
					$spreadsheet_id = null;
				}
				$this->interpreter->inject_global_variables($matches[7]);
				if(trim($matches[7])!='') {
					$worksheet_name = $matches[7];
				} else {
					$worksheet_name = null;
				}
				if(!$this->core->db_disabled) {
					// query for all columns in the SQL Table to build the header row for the Sheet
					$sheet_csv = '';
					$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_sql_table_name' AND TABLE_SCHEMA=database();");
					if($existing_columns = $result->fetchAll(PDO::FETCH_COLUMN)) {
						$sheet_csv = '';
						$column_count = 0;
						$prefix = '';
						$column_headers = array();
						foreach($existing_columns as $column_name) {
							if(!in_array($column_name, $this->core->reserved_sql_columns)) {
								// add the column header to the sheet converting "column_names" to "Column Names"
								$sheet_csv .= $prefix . '"' . str_replace('"', '""', ucwords(str_replace('_', ' ', $column_name))) . '"';
								$prefix = ',';
								$column_names[] = $column_name;
								$column_count++;
							}
						}
						// pad empty values up to column T
						while($column_count < 19) {
							$sheet_csv .= $prefix . '""';
							$column_names[] = '';
							$column_count++;
						}
						// add column for unique EASE Row ID used by the EASE core to enable row update and delete
						$sheet_csv .= $prefix . '"EASE Row ID"';
						$column_names[] = 'uuid';
						$column_count++;
						$sheet_csv .= $prefix . '"Instance ID"';
						$column_names[] = 'instance_id';
						$column_count++;
						$sheet_csv .= $prefix . '"Created On"';
						$column_names[] = 'created_on';
						$column_count++;
						$sheet_csv .= $prefix . '"Updated On"';
						$column_names[] = 'updated_on';
						$column_count++;
						$sheet_csv .= "\r\n";
						$row_count = 1;
						// query for all SQL Table data and build rows in the CSV for them
						$result = $this->core->db->query("SELECT * FROM `$namespaced_sql_table_name`;");
						while($row = $result->fetch(PDO::FETCH_ASSOC)) {
							$prefix = '';
							foreach($column_names as $column_name) {
								if(isset($row[$column_name])) {
									$sheet_csv .= $prefix . '"' . str_replace('"', '""', $row[$column_name]) . '"';
								} else {
									$sheet_csv .= $prefix . '""';
								}
								$prefix = ',';
							}
							$sheet_csv .= "\r\n";
							$row_count++;
						}
						// make sure there at at least 100 rows, and at least 10 rows after the last row
						for($i=0; $i<10; $i++) {
							$sheet_csv .= '""' . "\r\n";
							$row_count++;
						}
						while($row_count<100) {
							$sheet_csv .= '""' . "\r\n";
							$row_count++;
						}
						// initialize a new Google Drive API client
						require_once 'ease/lib/Google/Client.php';
						$client = new Google_Client();
						$client->setClientId($this->core->config['gapp_client_id']);
						$client->setClientSecret($this->core->config['gapp_client_secret']);
						$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
						$client->setAccessToken($this->core->config['gapp_access_token_json']);
						if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
							// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
							require_once 'ease/lib/Google/Cache/Null.php';
							$cache = new Google_Cache_Null($client);
							$client->setCache($cache);
						} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
							// an external memcache host was configured, pass on that configuration to the Google API Client
							$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
							if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
								$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
							} else {
								$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
							}
							require_once 'ease/lib/Google/Cache/Memcache.php';
							$cache = new Google_Cache_Memcache($client);
							$client->setCache($cache);
						}
						require_once 'ease/lib/Google/Service/Drive.php';
						$service = new Google_Service_Drive($client);
						$file = new Google_Service_Drive_DriveFile();
						$file->setTitle($spreadsheet_name);
						$file->setDescription('EASE ' . $this->core->globals['system.domain']);
						$file->setMimeType('text/csv');
						// upload the CSV file and convert it to a Google Sheet
						$new_spreadsheet = null;
						$try_count = 0;
						while($new_spreadsheet===null && $try_count<=5) {
							if($try_count > 0) {
								// apply exponential backoff
								sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
							}
							try {
								$try_count++;
								$new_spreadsheet = $service->files->insert($file, array('data'=>$sheet_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
							} catch(Google_Service_Exception $e) {
								continue;
							}
						}
						// check if there was an error creating the Google Sheet
						if(!isset($new_spreadsheet['id'])) {
							$this->output_buffer .=  "Error!  Unable to create Google Sheet named: " . htmlspecialchars($spreadsheet_name);
						}
					}
				}
				return;
			}

			###############################################
			##	GET GOOGLE SHEET ID BY NAME "QUOTED SPREADSHEET NAME" - only works as a standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*get\s+(g|google\s*|)spreadsheet\s+id\s+by\s+name\s*"\s*(.*?)\s*"\s*(\.|;){0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the Google Sheet name
				$this->interpreter->inject_global_variables($matches[2]);
				$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($matches[2]);
				// inject the Google Sheet ID into the output buffer
				$this->output_buffer .= $spreadsheet_meta_data['id'];
				// remove the GET SPREADSHEET ID BY NAME block from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	SET *EASE-VARIABLE* TO GOOGLE SHEET ID BY NAME "QUOTED SPREADSHEET NAME"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+(g|google\s*|)spreadsheet\s+id\s+by\s+name\s*"\s*(.*?)\s*"\s*;\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the EASE variable name and value being set
				$this->interpreter->inject_global_variables($matches[1]);
				$this->interpreter->inject_global_variables($matches[3]);
				// determine bucket being used to set a key value
				$set_variable_parts = explode('.', $matches[1], 2);
				if(count($set_variable_parts)==2) {
					$set_bucket = rtrim($set_variable_parts[0]);
					$set_bucket_key = ltrim($set_variable_parts[1]);
				} else {
					$set_bucket = '';
					$set_bucket_key = $matches[1];
				}
				// load the Google Sheet meta data
				$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($matches[3]);
				$set_value = $spreadsheet_meta_data['id'];
				// set the key value according to bucket
				if($set_bucket=='system') {
					// do nothing, global system values can not be set in EASE
				} elseif($set_bucket=='session') {
					$_SESSION[$set_bucket_key] = $set_value;
				} elseif($set_bucket=='cookie') {
					setcookie($set_bucket_key, $set_value, time() + 60 * 60 * 24 * 365, '/');
					$_COOKIE[$set_bucket_key] = $set_value;
				} elseif($set_bucket=='cache') {
					if($this->core->memcache) {
						if($this->core->namespace!='') {
							$this->core->memcache->set("{$this->core->namespace}.{$set_bucket_key}", $set_value);
						} else {
							$this->core->memcache->set($set_bucket_key, $set_value);
						}
					}
				} elseif($set_bucket=='config') {
					// TODO! update the ease_config database record for the key
				} else {
					if($set_bucket!='') {
						$this->core->globals["{$set_bucket}.{$set_bucket_key}"] = $set_value;
					} else {
						$this->core->globals[$set_bucket_key] = $set_value;
					}
				}
				// remove the SET command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	SET *EASE-VARIABLE* TO GOOGLE DRIVE FOLDER ID BY NAME "QUOTED-STRING"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+(public\s+|)(g|google\s*|)(drive\s*|docs\s*|)folder\s+id\s+by\s+name\s*"\s*(.*?)\s*"\s*;\s*/is', $this->unprocessed_body, $matches)) {
				$folder_id = null;
				// inject any global variables into the variable name or folder name
				$this->interpreter->inject_global_variables($matches[1]);
				$this->interpreter->inject_global_variables($matches[5]);
				// determine bucket being used to set a key value
				$set_variable_parts = explode('.', $matches[1], 2);
				if(count($set_variable_parts)==2) {
					$set_bucket = strtolower(rtrim($set_variable_parts[0]));
					$set_bucket_key = ltrim($set_variable_parts[1]);
				} else {
					$set_bucket = '';
					$set_bucket_key = $matches[1];
				}
				// initialize a new Google Drive API client
				$this->core->validate_google_access_token();
				require_once 'ease/lib/Google/Client.php';
				$client = new Google_Client();
				$client->setClientId($this->core->config['gapp_client_id']);
				$client->setClientSecret($this->core->config['gapp_client_secret']);
				$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
				$client->setAccessToken($this->core->config['gapp_access_token_json']);
				if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
					// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
					require_once 'ease/lib/Google/Cache/Null.php';
					$cache = new Google_Cache_Null($client);
					$client->setCache($cache);
				} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
					// an external memcache host was configured, pass on that configuration to the Google API Client
					$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
					if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
						$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
					} else {
						$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
					}
					require_once 'ease/lib/Google/Cache/Memcache.php';
					$cache = new Google_Cache_Memcache($client);
					$client->setCache($cache);
				}
				require_once 'ease/lib/Google/Service/Drive.php';
				$service = new Google_Service_Drive($client);
				// check if the folder already exists
				$files = null;
				$try_count = 0;
				while($files===null && $try_count<=5) {
					if($try_count > 0) {
						// apply exponential backoff
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					try {
						$try_count++;
					 	$files = $service->files->listFiles(array('q'=>"mimeType = 'application/vnd.google-apps.folder' and title = '" . str_replace("'", "\\'", str_replace("\\", "\\\\", $matches[5])) . "' and trashed = false"));
					} catch(Google_Service_Exception $e) {
						continue;
					}
				}
				if(isset($files['items']) && is_array($files['items']) && count($files['items']) > 0) {
					foreach($files['items'] as $matching_folder) {
						foreach($matching_folder['parents'] as $matching_folder_parent) {
							if($matching_folder_parent['isRoot']==true) {
								$folder_id = $matching_folder['id'];
								break(2);
							}
						}
					}
				}
				// if the folder wasn't found, create it
				if(!$folder_id) {
					$file = new Google_Service_Drive_DriveFile();
					$file->setTitle($matches[5]);
					$file->setMimeType('application/vnd.google-apps.folder');
					$new_folder = null;
					$try_count = 0;
					while($new_folder===null && $try_count<=5) {
						if($try_count > 0) {
							// apply exponential backoff
							sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
						}
						try {
							$try_count++;
							$new_folder = $service->files->insert($file);
						} catch(Google_Service_Exception $e) {
							continue;
						}
					}
					$folder_id = $new_folder['id'];
					if(trim(strtolower($matches[2]))=='public') {
						$permission = new Google_Service_Drive_Permission();
						$permission->setValue('');
						$permission->setType('anyone');
						$permission->setRole('reader');
						$permission->setWithLink(true);
						$service->permissions->insert($folder_id, $permission);
					}
				}
				// set the key value according to bucket
				if($set_bucket=='system') {
					// do nothing, global system values can not be set by EASE code
				} elseif($set_bucket=='session') {
					$_SESSION[$set_bucket_key] = $folder_id;
				} elseif($set_bucket=='cookie') {
					setcookie($set_bucket_key, $folder_id, time() + 60 * 60 * 24 * 365, '/');
					$_COOKIE[$set_bucket_key] = $folder_id;
				} elseif($set_bucket=='cache') {
					if($this->core->memcache) {
						if($this->core->namespace!='') {
							$this->core->memcache->set("{$this->core->namespace}.{$set_bucket_key}", $folder_id);
						} else {
							$this->core->memcache->set($set_bucket_key, $folder_id);
						}
					}
				} elseif($set_bucket=='config') {
					// TODO! update the ease_config database record for the key, unless config has been disabled
				} else {
					if($set_bucket!='') {
						$this->core->globals["{$set_bucket}.{$set_bucket_key}"] = $folder_id;
					} else {
						$this->core->globals[$set_bucket_key] = $folder_id;
					}
				}
				// remove the SET command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	SET
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+([^;]*?)\s*;\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the variable name or value being set
				$this->interpreter->inject_global_variables($matches[1]);
				$this->interpreter->inject_global_variables($matches[2]);
				$context_stack = $this->interpreter->extract_context_stack($matches[2]);
				// determine what type of value is being written;  either a quoted string or an expression
				if(preg_match('/^\s*"(.*)"\s*$/s', $matches[2], $inner_matches)) {
					// double-quoted string value
					$set_value = $inner_matches[1];
					// remove escape character from escaped double-quote characters and escape characters
					$set_value = str_replace("\\\\", "\\", $set_value); //   \\ to \
					$set_value = str_replace("\\\"", "\"", $set_value); //   \" to "
				} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $matches[2], $inner_matches)) {
					// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
					$eval_result = @eval("\$set_value = $matches[2];");
					if($eval_result===false) {
						// there was an error evaluating the expression... set the value to the expression string
						$set_value = $matches[2];
					}
				} else {
					// the value was not enclosed in double quotes, and could not be processed as a math expression
					// treat the value as a string.
					$set_value = $matches[2];
				}
				$this->interpreter->apply_context_stack($set_value, $context_stack);
				// determine bucket being used to set a key value
				$set_variable_parts = explode('.', $matches[1], 2);
				if(count($set_variable_parts)==2) {
					$set_bucket = rtrim(strtolower($set_variable_parts[0]));
					$set_bucket_key = ltrim(@$set_variable_parts[1]);
					$set_global_key = "{$set_bucket}.{$set_bucket_key}";
				} else {
					$set_bucket = '';
					$set_bucket_key = $matches[1];
					$set_global_key = $matches[1];
				}
				// set the value for the bucket key
				if($set_bucket=='system') {
					// do nothing, global system values can not be set in an EASE block
				} elseif($set_bucket=='session') {
					$_SESSION[$set_bucket_key] = $set_value;
				} elseif($set_bucket=='cookie') {
					setcookie($set_bucket_key, $set_value, time() + 60 * 60 * 24 * 365, '/');
					$_COOKIE[$set_bucket_key] = $set_value;
				} elseif($set_bucket=='cache') {
					if($this->core->memcache) {
						if($this->core->namespace!='') {
							$this->core->memcache->set("{$this->core->namespace}.{$set_bucket_key}", $set_value);
						} else {
							$this->core->memcache->set($set_bucket_key, $set_value);
						}
					}
				} elseif($set_bucket=='config') {
					// TODO! update the ease_config database record for the key
				} else {
					// the bucket was not reserved, set the value in the EASE globals
					$this->core->globals[$set_global_key] = $set_value;
				}
				// remove the SET command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	ROUND
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $this->unprocessed_body, $matches)) {
				$this->interpreter->inject_global_variables($matches[2]);
				$round_var_parts = explode('.', $matches[1], 2);
				if(count($round_var_parts)==2) {
					$bucket = strtolower(rtrim($round_var_parts[0]));
					$key = ltrim($round_var_parts[1]);
					$key_lower = strtolower($key);
					$global_key = $bucket . '.' . $key_lower;
				} else {
					$bucket = '';
					$key = trim($matches[1]);
					$key_lower = strtolower($key);
					$global_key = $key_lower;
				}
				// round the value for the bucket key
				if($bucket=='system') {
					// do nothing, global system values can not be set in an EASE block
				} elseif($bucket=='config') {
					// TODO! update the ease_config database record for the key
				} elseif($bucket=='session') {
					if(isset($_SESSION[$key])) {
						$_SESSION[$key] = round($_SESSION[$key], intval($matches[2]));
					} elseif(isset($_SESSION[$key_lower])) {
						$_SESSION[$key_lower] = round($_SESSION[$key_lower], intval($matches[2]));
					} else {
						$_SESSION[$key_lower] = round(0, intval($matches[2]));
					}
				} elseif($bucket=='cookie') {
					if(isset($_COOKIE[$key])) {
						$set_value = round($_COOKIE[$key], intval($matches[2]));
						setcookie($key, $set_value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $set_value;
					} elseif(isset($_COOKIE[$key_lower])) {
						$set_value = round($_COOKIE[$key_lower], intval($matches[2]));
						setcookie($key_lower, $set_value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key_lower] = $set_value;
					} else {
						$set_value = round(0, intval($matches[2]));
						setcookie($key_lower, $set_value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key_lower] = $set_value;
					}
				} elseif($bucket=='cache') {
					if($this->core->memcache) {
						if($this->core->namespace!='') {
							if(($set_value = $this->core->memcache->get("{$this->core->namespace}.{$key}"))!==false) {
								$set_value = round($set_value, intval($matches[2]));
								$this->core->memcache->set("{$this->core->namespace}.{$key}", $set_value);
							} elseif(($set_value = $this->core->memcache->get("{$this->core->namespace}.{$key_lower}"))!==false) {
								$set_value = round($set_value, intval($matches[2]));
								$this->core->memcache->set("{$this->core->namespace}.{$key_lower}", $set_value);
							} else {
								$this->core->memcache->set("{$this->core->namespace}.{$key_lower}", round(0, intval($matches[2])));
							}
						} else {
							if(($set_value = $this->core->memcache->get($key))!==false) {
								$set_value = round($set_value, intval($matches[2]));
								$this->core->memcache->set($key, $set_value);
							} elseif(($set_value = $this->core->memcache->get($key_lower))!==false) {
								$set_value = round($set_value, intval($matches[2]));
								$this->core->memcache->set($key_lower, $set_value);
							} else {
								$this->core->memcache->set($key_lower, round(0, intval($matches[2])));
							}
						}
					}
				} else {
					// the bucket was not reserved, set the value in the EASE globals
					if(isset($this->core->globals[$bucket . '.' . $key])) {
						$this->core->globals[$bucket . '.' . $key] = round($this->core->globals[$bucket . '.' . $key], intval($matches[2]));
					} elseif(isset($this->core->globals[$global_key])) {
						$this->core->globals[$global_key] = round($this->core->globals[$global_key], intval($matches[2]));
					} elseif($bucket=='' && isset($this->core->globals[$key])) {
						$this->core->globals[$key] = round($this->core->globals[$key], intval($matches[2]));
					} else {
						$this->core->globals[$global_key] = round(0, intval($matches[2]));
					}
				}
				// remove the ROUND command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	REDIRECT TO "QUOTED-STRING"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*redirect\s+to\s*"\s*(.*?)\s*"\s*[\.;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the redirect URL
				$this->interpreter->inject_global_variables($matches[1]);
				if($this->core->catch_redirect) {
					// EASE Framework configured to catch redirects
					$this->core->redirect = $matches[1];
					// halt processing anything after the redirect
					$this->unprocessed_body = '';
					return;
				} else {
					// redirect to the quoted Location URI, halting all further processing of this request
					header("Location: {$matches[1]}");
					exit;
				}
			}

			###############################################
			##	EMPTY BUCKET *BUCKET-NAME* / PRINT BUCKET *BUCKET-NAME*
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(empty|clear|clean|flush|html\s*dump|show|print|echo)\s*bucket\s*([^;]+?)\s*;/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the bucket name
				$this->interpreter->inject_global_variables($matches[2]);
				if(!in_array(strtolower($matches[2]), $this->core->reserved_buckets)) {
					$bucket_name = strtolower($matches[2]) . '.';
					$bucket_name_length = strlen($bucket_name);
					switch(preg_replace('/[^a-z]+/s', '', strtolower($matches[1]))) {
						case 'empty':
						case 'clear':
						case 'clean':
						case 'flush':
							// process every global variable looking for the referenced bucket
							foreach($this->core->globals as $key=>$value) {
								if(substr($key, 0, $bucket_name_length)==$bucket_name) {
									// a global variable was found matching the referenced bucket... unset it
									unset($this->core->globals[$key]);
								}
							}
							break;
						case 'htmldump':
						case 'show':
						case 'print':
						case 'echo':
							// process every global variable looking for the referenced bucket
							$bucket_array = array();
							foreach($this->core->globals as $key=>$value) {
								if(substr($key, 0, $bucket_name_length)==$bucket_name) {
									// a global variable was found matching the referenced bucket
									$bucket_pointer = &$bucket_array;							
									// process the key to build an associative array of the bucket
									// indexes are denoted with a period .  (ex: my_bucket.name, my_bucket.address.city)
									$key_parts = explode('.', $key);
									unset($key_parts[0]);
									foreach($key_parts as $key_part) {
										if(!isset($bucket_pointer[$key_part])) {
											$bucket_pointer[$key_part] = null;
										}
										$bucket_pointer = &$bucket_pointer[$key_part];
									}
									$bucket_pointer = $value;
								}
							}
							$bucket_dump = print_r($bucket_array, true);
							$this->output_buffer .= '<pre style="tab-size:4;">';
							$this->output_buffer .= "<b>" . htmlspecialchars($matches[2]) . ":</b> ";
							$this->output_buffer .= htmlspecialchars($bucket_dump);
							$this->output_buffer .= '</pre>';
							break;
						default:
					}
				}
				// remove the BUCKET command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	CALL CLOUDWARD BILLING
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*call\s*cloudward\s*billing\s*with\s*([^;]+?)\s+to\s+([^;]+?)\s*;/is', $this->unprocessed_body, $matches)) {
				// convert the data in the "with" bucket to an associative array
				$from_bucket_array = $this->ease_bucket_to_array($matches[1], strtolower($matches[2]));
				// encode the data as JSON to send to Cloudward Billing
				$data = json_encode($from_bucket_array, JSON_FORCE_OBJECT);
				// create context for HTTP stream
				$context = stream_context_create(array('http'=>array(
					'method'=>'POST',
					'header'=>'Content-Type: application/json' . "\r\n" . 'Content-Length: ' . strlen($data) . "\r\n",
					'content'=>$data
				)));
				// execute the call to Cloudward Billing with the JSON encoded data
				$response = file_get_contents('https://secure.cloudward.com/webservices/handlers/', false, $context);
				// convert the received JSON and to an associative array
				$response_array = json_decode($response, true);
				// store every element from the associative array in the "to" bucket, after it is emptied
				$this->ease_empty_bucket($matches[2]);
				$this->ease_array_to_bucket($response_array, strtolower($matches[2]));
				// remove the CALL CLOUDWARD BILLING command from the EASE block
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	IF ELSE - Conditional EASE
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*((?:else\s*if\s*\(\s*.*?\s*\)\s*\{.*?\}\s*)*)(?:else\s*\{(.*?)\}\s*|)(' . preg_quote($this->core->ease_block_end, '/') . '|.*?;\s*' . preg_quote($this->core->ease_block_end, '/') . ')/is', $this->unprocessed_body, $matches)) {
				// initialize conditions and variables to store Conditional EASE blocks
				$conditions = array();
				$else_ease_block = '';
				$matched_condition = false;
				$process_ease_block = '';
				// continue processing the matches to build an array Conditonal EASE blocks
				$conditions[$matches[1]] = $matches[2];
				// process any ELSE IF conditions
				while(preg_match('/^else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*/is', $matches[3], $inner_matches)) {
					// found an ELSE IF Conditional EASE block
					$conditions[$inner_matches[1]] = $inner_matches[2];
					$matches[3] = substr($matches[3], strlen($inner_matches[0]));
				}
				if(trim($matches[4])!='') {
					// found an ELSE Conditional EASE block
					$else_ease_block = $matches[4];
				}
				// FIFO process each condition... if a match is found, process the associated Conditional EASE block
				foreach($conditions as $condition=>$conditional_ease_block) {
					$remaining_condition = $condition;
					$php_condition_string = '';
					while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
						if(strtolower($inner_matches[1])=='and') {
							$inner_matches[1] = '&&';
						}
						if(strtolower($inner_matches[1])=='or') {
							$inner_matches[1] = '||';
						}
						if(strtolower($inner_matches[2])=='not') {
							$inner_matches[2] = '!';
						}
						if($inner_matches[5]=='=' || strtolower($inner_matches[5])=='is') {
							$inner_matches[5] = '==';
						}
						$this->interpreter->inject_global_variables($inner_matches[4]);
						$this->interpreter->inject_global_variables($inner_matches[6]);
						$php_condition_string .= $inner_matches[1]
							. $inner_matches[2]
							. $inner_matches[3]
							. var_export($inner_matches[4], true)
							. $inner_matches[5]
							. var_export($inner_matches[6], true)
							. $inner_matches[7];
						$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
					}
					if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
						$matched_condition = true;
						$process_ease_block = $conditional_ease_block;
						break;
					}
				}
				if(!$matched_condition) {
					// none of the IF conditions were matched, so process the ELSE Conditional EASE block
					// the ELSE block defaults to blank
					$process_ease_block = $else_ease_block;
				}
				// remove the IF{} block from the body, then inject the matched Conditional EASE block into the start of remaining body
				if(!preg_match('/^\s*' . preg_quote($this->core->ease_block_start, '/') . '\s*[^' . preg_quote($this->core->global_reference_start, '/') . ']+/is', $process_ease_block, $inner_matches)) {
					// the conditional block did not start with a ease block start sequence... add one
					$process_ease_block = $this->core->ease_block_start . $process_ease_block;
				} else {
					$process_ease_block = ltrim($process_ease_block);
				}
				if(!preg_match('/[^' . preg_quote($this->core->global_reference_end, '/') . ']+\s*' . preg_quote($this->core->ease_block_end, '/') . '\s*$/is', $process_ease_block, $inner_matches)) {
					// the conditional block did not end with a ease block end sequence... add one
					$process_ease_block = $process_ease_block . $this->core->ease_block_end;
				}
				// replace the conditional in the unprocessed body with the EASE block for the matched condition
				if($matches[5]!=$this->core->ease_block_end) {
					// there is EASE code after the IF{} block... add it after the Conditional EASE block
					$this->unprocessed_body = $process_ease_block . $this->core->ease_block_start . $matches[5] . substr($this->unprocessed_body, strlen($matches[0]));
				} else {
					$this->unprocessed_body = $process_ease_block . substr($this->unprocessed_body, strlen($matches[0]));
				}
	 			// the remaining unprocessed body will now begin with the EASE block for the matched condition
				continue;
			}

			###############################################
			##	APPLY *GOOGLE-SHEET-ROW* AS "QUOTED-STRING"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*apply\s+(google\s*drive|g\s*drive|google|g|)\s*(spread|)sheet(\s*"\s*(.+?)\s*"\s*|\s+([\w-]+))(\s*"\s*(.+?)\s*"\s*|)(\s*\.|)\s*([^;]*?)\s*(and\s*reference\s*|reference\s*|)(as\s*"\s*(.*?)\s*"|)\s*;\s*/is', $this->unprocessed_body, $matches)) {
				// initialize a Google Sheet API client
				$this->core->validate_google_access_token();
				require_once 'ease/lib/Spreadsheet/Autoloader.php';
				$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
				$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
				Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
				$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
				// determine the Google Sheet "Name" or ID, and the EASE Row ID
				$google_spreadsheet_id = '';
				$google_spreadsheet_name = '';
				if(isset($matches[4]) && trim($matches[4])!='') {
					// the Google Sheet was referenced by "Name"
					$google_spreadsheet_name = $matches[4];
					$this->interpreter->inject_global_variables($google_spreadsheet_name);
					$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
					$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
					if($spreadSheet===null) {
						// the supplied Google Sheet name did not match an existing Google Sheet
						echo 'Error!  Unable to load Google Sheet named: ' . htmlspecialchars($google_spreadsheet_name);
						exit;
					}
				} else {
					// the Google Sheet was referenced by ID
					$google_spreadsheet_id = $matches[5];
					$this->interpreter->inject_global_variables($google_spreadsheet_id);
					$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
					if($spreadSheet===null) {
						// there was an error loading the Google Sheet by ID...
						// flush the cached meta data for the Google Sheet ID which may no longer be valid
						$this->core->flush_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
						echo 'Error!  Unable to load Google Sheet: ' . htmlspecialchars($google_spreadsheet_id);
						exit;
					}
				}
				// load the worksheets in the Google Sheet
				$worksheetFeed = $spreadSheet->getWorksheets();
				$google_spreadsheet_save_to_sheet = '';
				if(trim($matches[6])!='') {
					$google_spreadsheet_save_to_sheet = $matches[6];
					$this->interpreter->inject_global_variables($google_spreadsheet_save_to_sheet);
					$worksheet = $worksheetFeed->getByTitle($google_spreadsheet_save_to_sheet);
					if($worksheet===null) {
						// the supplied worksheet name did not match an existing worksheet of the Google Sheet
						echo 'Error!  Unable to load Worksheet named: ' . htmlspecialchars($google_spreadsheet_save_to_sheet);
						exit;
					}
				} else {
					$worksheet = $worksheetFeed->getFirstSheet();
				}
				// check for unloaded worksheet
				if($worksheet===null) {
					echo "Google Sheet Error!  Unable to load worksheet.";
					exit;
				}
				// load meta data for the Google Sheet to map header names to columns
				if($google_spreadsheet_id!='') {
					// Google Sheet referenced by ID
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id, $google_spreadsheet_save_to_sheet);
				} elseif($google_spreadsheet_name!='') {
					// Google Sheet referenced by "Name"
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name, $google_spreadsheet_save_to_sheet);
				}
				$this->interpreter->inject_global_variables($matches[9]);
				$row_id	 = preg_replace('/[^a-z0-9]+/s', '', strtolower(ltrim($matches[9])));
				// determine if a referenced name was provided
				$this->interpreter->inject_global_variables($matches[12]);
				$apply_reference = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[12])), '_');
				// query for the row to delete
				$listFeed = $worksheet->getListFeed('', '', "easerowid = \"$row_id\"");
				$listEntries = $listFeed->getEntries();
				// delete all rows that matched the requested EASE Row ID value
				foreach($listEntries as $listEntry) {
					$existing_row = $listEntry->getValues();
					// convert the listEntry column header keys to column letter keys
					foreach($existing_row as $key=>$value) {
						$this->core->globals["$apply_reference.$key"] = $value;
						if(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
							$this->core->globals["$apply_reference.{$spreadsheet_meta_data['column_letter_by_name'][$key]}"] = $value;
						}
					}
					$this->core->globals["$apply_reference.id"] = &$this->core->globals["$apply_reference.easerowid"];
					$this->core->globals["$apply_reference.uuid"] = &$this->core->globals["$apply_reference.easerowid"];
				}
				// remove the APPLY tag from the unprocessed body
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}		

			###############################################
			##	APPLY *SQL-INSTANCE* AS "QUOTED-STRING"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*apply\s+([^;]+?)(\s*\.\s*([^;]+?)|)\s+(and\s*reference\s*|reference\s*|)(as\s*"\s*(.*?)\s*"|)\s*[\.;]\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the variable name or value
				$this->interpreter->inject_global_variables($matches[1]);
				if(trim($matches[3])!='') {
					$this->interpreter->inject_global_variables($matches[3]);
					$apply_uuid = true;
				} else {
					$apply_uuid = false;
				}
				$this->interpreter->inject_global_variables($matches[6]);
				// if a alias name was not given, use the table name
				if(trim($matches[6])=='') {
					$matches[6] = $matches[1];
				}
				// set the applied values in the globals, making sure the referenced bucket name isn't reserved
				if(!in_array($matches[6], $this->core->reserved_buckets)) {
					if($this->core->db && !$this->core->db_disabled) {
						// query for all current key values for the referenced instance by UUID
						$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1])), '_');
						$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
						if($apply_uuid) {
							$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
							$params = array(':uuid'=>(string)$matches[3]);
							if($query->execute($params)) {
								if($row = $query->fetch(PDO::FETCH_ASSOC)) {
									// set the applied values in the globals by bucket.key (table.column)
									foreach($row as $key=>$value) {
										$this->core->globals["$matches[6].$key"] = $value;
									}
									$this->core->globals["$matches[6].id"] = &$this->core->globals["$matches[6].uuid"];
								}
							}
						} else {
							if($result = $this->core->db->query("SELECT * FROM `$namespaced_sql_table_name` ORDER BY created_on ASC LIMIT 1;")) {
								if($row = $result->fetch(PDO::FETCH_ASSOC)) {
									// set the applied values in the globals by bucket.key (table.column)
									foreach($row as $key=>$value) {
										$this->core->globals["$matches[6].$key"] = $value;
									}
									$this->core->globals["$matches[6].id"] = &$this->core->globals["$matches[6].uuid"];
								}
							}
						}
					}
				} else {
					$this->errors[] = "APPLY ERROR!  The global '{$matches[6]}' bucket is reserved.";
				}
				// remove the APPLY command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	CLONE *GOOGLE-DRIVE-SPREADSHEET-ROW* AS "QUOTED-STRING"
			// TODO!!

			###############################################
			##	CLONE *SQL-INSTANCE* AS "QUOTED-STRING"
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(clone|copy|duplicate)\s+([^;]+?)\s*\.\s*([^;]+?)\s+(and\s*reference\s*|reference\s*|)as\s*"\s*(.*?)\s*"\s*[\.;]\s*/is', $this->unprocessed_body, $matches)) {
				// inject any global variables into the variable name or value
				$this->interpreter->inject_global_variables($matches[2]);
				$this->interpreter->inject_global_variables($matches[3]);
				$this->interpreter->inject_global_variables($matches[5]);
				// set the copied values in the globals, making sure the referenced bucket name isn't reserved
				if(!in_array($matches[5], $this->core->reserved_buckets)) {
					if($this->core->db && !$this->core->db_disabled) {
						// query for all current key values for the referenced instance by UUID
						$sql_table_name = preg_replace('/[^a-z0-9-]+/s', '_', strtolower($matches[2]));
						$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9-]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
						$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
						$params = array(':uuid'=>(string)$matches[3]);
						if($query->execute($params)) {
							if($row = $query->fetch(PDO::FETCH_ASSOC)) {
								unset($row['instance_id']);
								unset($row['uuid']);
								unset($row['created_on']);
								unset($row['updated_on']);
								$new_uuid = $this->core->new_uuid();
								$params = array(':uuid'=>$new_uuid);
								$column_list = '';
								foreach($row as $key=>$value) {
									$params[":$key"] = $value;
									$column_list .= ",`$key`=:$key";
								}
								$query = $this->core->db->prepare("INSERT INTO `$namespaced_sql_table_name` SET uuid=:uuid $column_list;");
								if($query->execute($params)) {
									// clone successful... query for auto generated instance ID and creation timestamp
									$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
									$params = array(':uuid'=>$new_uuid);
									if($query->execute($params)) {
										if($row = $query->fetch(PDO::FETCH_ASSOC)) {
											// set the applied values in the globals by bucket.key (table.column)
											foreach($row as $key=>$value) {
												$this->core->globals["$matches[5].$key"] = $value;
											}
											$this->core->globals["$matches[5].id"] = &$this->core->globals["$matches[5].uuid"];
										}
									}
								}
							} else {
								$this->errors[] = "CLONE ERROR!  The requested record '{$matches[3]}' does not exist.";
							}
						}
					}
				} else {
					$this->errors[] = "CLONE ERROR!  The global bucket '{$matches[5]}' is reserved.";
				}
				// remove the CLONE command from the EASE block, then process any remains
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}
		
			###############################################
			##	CREATE NEW RECORD
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*create(\s+new\s+|\s+)record\s+(.*?;\h*(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$create_new_record_block = $matches[0];
				$unprocessed_create_new_record_block = $matches[2];
				$this->interpreter->remove_comments($unprocessed_create_new_record_block);

				###############################################
				##	CREATE NEW RECORD - FOR GOOGLE SHEET
				if(preg_match('/^for\s+(google\s*|g|)spreadsheet\s+("(.*?)"|([^;]+?))(\s*"\s*(.*?)\s*"\s*|\s*)(and\s+reference\s+|reference\s+|)(as\s*"\s*(.*?)\s*"|)\s*;\s*/is', $unprocessed_create_new_record_block, $matches)) {
					// parse out the FOR attributes, some are optional so the @ is used to supress PHP undefined index warnings
					@$google_spreadsheet_name = $matches[3];
					$this->interpreter->inject_global_variables($google_spreadsheet_name);
					@$google_spreadsheet_id = $matches[4];
					$this->interpreter->inject_global_variables($google_spreadsheet_id);
					@$google_spreadsheet_sheet_name = $matches[6];
					$this->interpreter->inject_global_variables($google_spreadsheet_sheet_name);
					@$new_record_reference = $matches[9];
					$this->interpreter->inject_global_variables($new_record_reference);

					// the FOR attribute of the CREATE NEW RECORD command was successfully parsed, scan for any remaining CREATE NEW RECORD directives
					$unprocessed_create_new_record_block = substr($unprocessed_create_new_record_block, strlen($matches[0]));

	 				// SEND EMAIL
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*send\s+email\s*;/is', '', $unprocessed_send_email_block);

							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*?)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);

							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*?)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);

							// build the email message and headers according to the type
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							$result = $this->core->send_email($mail_options);
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// SET *COLUMN* TO "QUOTED-STRING" or *MATH EXPRESSION*
					$new_row = array();
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*set\s+([^;]+?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is',
						function($matches) use (&$new_row, &$header_names, $new_record_reference) {
							$this->interpreter->inject_global_variables($matches[1]);
							if(isset($matches[4]) && trim($matches[4])!='') {
								// math expression
								$this->interpreter->inject_global_variables($matches[4]);
								$context_stack = $this->interpreter->extract_context_stack($matches[4]);
								if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $matches[4], $inner_matches)) {
									// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
									$eval_result = @eval("\$set_value = {$matches[4]};");
									if($eval_result===false) {
										// there was an error evaluating the expression... set the value to the expression string
										$set_value = $matches[4];
									}
								} else {
									// the math expression contained invalid characters... set the value to the broken math expression
									$set_value = $matches[4];
								}
								$this->interpreter->apply_context_stack($set_value, $context_stack);
							} else {
								// double quoted string
								$this->interpreter->inject_global_variables($matches[3]);
								$set_value = $matches[3];
							}
							$bucket_key_parts = explode('.', $matches[1], 2);
							if(count($bucket_key_parts)==2) {
								$bucket = strtolower(rtrim($bucket_key_parts[0]));
								$original_key = ltrim($bucket_key_parts[1]);
								if($bucket==$new_record_reference || $bucket=='row') {
									$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($original_key));
									$header_names[$key] = $original_key;
									$new_row[$key] = $set_value;
								} elseif($bucket=='session') {
									$key = preg_replace('/[^a-z0-9. -]+/s', '_', strtolower($original_key));
									$_SESSION[$key] = $set_value;
								} elseif($bucket=='cookie') {
									$key = preg_replace('/[^a-z0-9. -]+/s', '_', strtolower($original_key));
									setcookie($key, $set_value, time() + 60 * 60 * 24 * 365, '/');
									$_COOKIE[$key] = $set_value;
								}
							} else {
								$original_key = $matches[1];
								$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($original_key));
								$header_names[$key] = $original_key;
								$new_row[$key] = $set_value;
							}
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// REDIRECT TO "QUOTED-STRING"
					$create_new_record_redirect_to = '';
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*redirect\s+to\s*"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$create_new_record_redirect_to) {
							$this->interpreter->inject_global_variables($matches[1]);
							$create_new_record_redirect_to = $matches[1];
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// !! ANY NEW CREATE NEW RECORD - FOR GOOGLE SHEET DIRECTIVES GET ADDED HERE

					// if the CREATE NEW RECORD block has any non-comment content remaining, there was an unrecognized directive, so log a parse error
					// TODO!! change this logic to parse the CREATE NEW RECORD directives sequentially...
					//	 continue processing any commands after the create new record block
					$this->interpreter->remove_comments($unprocessed_create_new_record_block);
					if(trim($unprocessed_create_new_record_block)!='') {
						// ERROR! the CREATE NEW RECORD block contained an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}

					// initialize a Google Sheets API client
					$this->core->validate_google_access_token();
					require_once 'ease/lib/Spreadsheet/Autoloader.php';
					$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
					$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
					Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
					$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);

					// determine if the Google Sheet was referenced by "Name" or ID
					$new_spreadsheet_created = false;
					$spreadSheet = null;
					if($google_spreadsheet_id) {
						// load the Google Sheet by the referenced ID
						@$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
						if($spreadSheet===null) {
							// there was an error loading the Google Sheet by ID...
							// flush the cached meta data for the Google Sheet ID which may no longer be valid
							$this->core->flush_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
							echo 'Error!  Invalid Spreadsheet ID: ' . htmlspecialchars($google_spreadsheet_id);
							exit;
						}
					} elseif($google_spreadsheet_name!='') {
						// load the Google Sheet by the supplied "quoted" name
						$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
						$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
						if($spreadSheet===null) {
							// the supplied Google Sheet name did not match an existing Sheet;  create a new Sheet with the supplied name
							// initialize a new Google Drive API client
							require_once 'ease/lib/Google/Client.php';
							$client = new Google_Client();
							$client->setClientId($this->core->config['gapp_client_id']);
							$client->setClientSecret($this->core->config['gapp_client_secret']);
							$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
							$client->setAccessToken($this->core->config['gapp_access_token_json']);
							if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
								// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
								require_once 'ease/lib/Google/Cache/Null.php';
								$cache = new Google_Cache_Null($client);
								$client->setCache($cache);
							} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
								// an external memcache host was configured, pass on that configuration to the Google API Client
								$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
								if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
									$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
								} else {
									$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
								}
								require_once 'ease/lib/Google/Cache/Memcache.php';
								$cache = new Google_Cache_Memcache($client);
								$client->setCache($cache);
							}
							require_once 'ease/lib/Google/Service/Drive.php';
							$service = new Google_Service_Drive($client);
							$file = new Google_Service_Drive_DriveFile();
							$file->setTitle($google_spreadsheet_name);
							$file->setDescription('EASE ' . $this->core->globals['system.domain']);
							$file->setMimeType('text/csv');
							// build the header row CSV string of column names
							$alphas = range('A', 'Z');
							$header_row_csv = '';
							$prefix = '';
							foreach($header_names as $value) {
								$header_row_csv .= $prefix . '"' . str_replace('"', '""', $value) . '"';
								$prefix = ', ';
							}
							// pad empty values up to column T
							$header_row_count = count($header_names);
							while($header_row_count < 19) {
								$header_row_csv .= $prefix . '""';
								$header_row_count++;
							}
							// add column for unique EASE Row ID used by the EASE core to enable row update and delete
							$header_row_csv .= $prefix . '"EASE Row ID"';
							$header_row_csv .= "\r\n";

							// TODO!! add the new record data here to save an extra API call to insert it later

							// add 100 blank rows
							for($i=1; $i<100; $i++) {
								$header_row_csv .= '""';
								for($j=1; $j<20; $j++) {
									$header_row_csv .= ',""';
								}
								$header_row_csv .= "\r\n";
							}
							// upload the CSV file and convert it to a Google Sheet
							$new_spreadsheet = null;
							$try_count = 0;
							while($new_spreadsheet===null && $try_count<=5) {
								if($try_count > 0) {
									// apply exponential backoff
									sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
								}
								try {
									$try_count++;
									$new_spreadsheet = $service->files->insert($file, array('data'=>$header_row_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
								} catch(Google_Service_Exception $e) {
									continue;
								}
							}
							// get the new Google Sheet ID
							$google_spreadsheet_id = $new_spreadsheet['id'];
							// check if there was an error creating the Google Sheet
							if(!$google_spreadsheet_id) {
								echo 'Error!  Unable to create Google Sheet named: ' . htmlspecialchars($google_spreadsheet_name);
								exit;
							}

							// cache the meta data for the new Google Sheet (id, name, column name to letter map, column letter to name map)
							// TODO! set the meta data directly right here rather than calling the load process that will require extra API calls
							$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name);

							// load the newly created Google Sheet
							$spreadSheet = null;
							$try_count = 0;
							while($spreadSheet===null && $try_count<=5) {
								if($try_count > 0) {
									// apply exponential backoff
									sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
								}
								try {
									$try_count++;
									$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
								} catch(Exception $e) {
									continue;
								}
							}
							$new_spreadsheet_created = true;
						}
					}
					if($spreadSheet===null) {
						echo 'Error!  Unable to load Google Sheet';
						exit;
					}
					// load the worksheets in the Google Sheet
					$worksheetFeed = $spreadSheet->getWorksheets();
					if(trim($google_spreadsheet_sheet_name)!='') {
						$worksheet = $worksheetFeed->getByTitle($google_spreadsheet_sheet_name);
						if($worksheet===null) {
							// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
							$header_row = array();
							foreach($header_names as $value) {
								$header_row[] = $value;
							}
							// pad empty values up to column T
							$header_row_count = count($header_names);
							while($header_row_count < 19) {
								$header_row[] = '';
								$header_row_count++;
							}
							// add column for unique EASE Row ID used by the EASE core to enable row update and delete
							$header_row[] = 'EASE Row ID';
							$new_worksheet_rows = 100;
							if(count($header_row)<=20) {
								$new_worksheet_cols = 20;
							} else {
								$new_worksheet_cols = 10 + count($header_row);
							}
							$worksheet = $spreadSheet->addWorksheet($google_spreadsheet_sheet_name, $new_worksheet_rows, $new_worksheet_cols);
							$worksheet->createHeader($header_row);
							if($new_spreadsheet_created && $google_spreadsheet_sheet_name!='Sheet 1') {
								$worksheetFeed = $spreadSheet->getWorksheets();
								$old_worksheet = $worksheetFeed->getFirstSheet();
								$old_worksheet->delete();
							}
						}
					} else {
						$worksheet = $worksheetFeed->getFirstSheet();
					}
					if($new_spreadsheet_created) {
						// build and set the meta data for the Google Sheet
						$column_letter = 'A';
						foreach($header_names as $value) {
							$spreadsheet_meta_data['column_letter_by_name'][preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($value))] = $column_letter;
							$spreadsheet_meta_data['column_name_by_letter'][$column_letter] = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($value));
							$column_letter++;
						}
						$spreadsheet_meta_data['column_letter_by_name']['easerowid'] = 'T';
						$spreadsheet_meta_data['column_name_by_letter']['T'] = 'easerowid';
						$spreadsheet_meta_data['id'] = trim($google_spreadsheet_id);
						$spreadsheet_meta_data['name'] = trim($google_spreadsheet_name);
						$spreadsheet_meta_data['worksheet'] = trim($google_spreadsheet_sheet_name);
						if($this->core->memcache) {
							$this->core->memcache->set("system.google_spreadsheets_by_id.$google_spreadsheet_id.$google_spreadsheet_sheet_name", $spreadsheet_meta_data);
							if($this->core->namespace!='') {
								$this->core->memcache->set("{$this->core->namespace}.system.google_spreadsheets_by_name.$google_spreadsheet_name.$google_spreadsheet_sheet_name", $spreadsheet_meta_data);
							} else {
								$this->core->memcache->set("system.google_spreadsheets_by_name.$google_spreadsheet_name.$google_spreadsheet_sheet_name", $spreadsheet_meta_data);
							}
						}
						if($this->core->db) {
							$query = $this->core->db->prepare("	REPLACE INTO ease_google_spreadsheets
																	(id, name, worksheet, meta_data_json, namespace)
																VALUES
																	(:id, :name, :worksheet, :meta_data_json, :namespace);	");
							$params = array(
								':id'=>$google_spreadsheet_id,
								':name'=>$google_spreadsheet_name,
								':worksheet'=>$google_spreadsheet_sheet_name,
								':meta_data_json'=>json_encode($spreadsheet_meta_data),
								':namespace'=>$this->core->namespace
							);
							$result = $query->execute($params);
							if(!$result) {
								// the insert failed... attempt to create the ease_google_spreadsheets table, then try again
								$this->core->db->exec("	DROP TABLE IF EXISTS ease_google_spreadsheets;
														CREATE TABLE ease_google_spreadsheets (
															id VARCHAR(255) NOT NULL PRIMARY KEY,
															name TEXT NOT NULL DEFAULT '',
															worksheet TEXT NOT NULL DEFAULT '',
															meta_data_json TEXT NOT NULL DEFAULT '',
															namespace TEXT NOT NULL DEFAULT ''
														);	");
								$result = $query->execute($params);
							}
						}
					} else {
						// load the meta data for the Google Sheet.  meta data includes column letter to column header value maps
						if(trim($google_spreadsheet_id)!='') {
							$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id, $google_spreadsheet_sheet_name);
						} elseif(trim($google_spreadsheet_name)!='') {
							$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name, $google_spreadsheet_sheet_name);
						}
					}
					// build the new row to insert into the selected worksheet of the Google Sheet
					if(is_array($new_row)) {
						foreach($new_row as $key=>$value) {
							if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
								// the column was referenced by letter... update the reference to be by name
								unset($new_row[$key]);
								$new_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
							} elseif(!isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
								// the referenced column wasn't found in the cached Google Sheet meta data... dump the cache and reload it, then check again
								$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
								$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
								if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
									// the column was referenced by letter... update the reference to be by name
									unset($new_row[$key]);
									$new_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
								} elseif(!isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
									// the referenced column still wasn't found... attempt to create it
									$alphas = range('A', 'Z');
									$cellFeed = $worksheet->getCellFeed();
									$cellEntries = $cellFeed->getEntries();
									$cellEntry = $cellEntries[0];
									// if the column reference is a single letter, treat it as a letter reference, otherwise treat it as the header name
									if(strlen($key)==1) {
										// single letter header reference, assume this is a column letter name
										$cellEntry->setContent('Column ' . strtoupper($key));
										$new_column_number = array_search(strtoupper($key), $alphas) + 1;
									} else {
										// column header referenced by "Name", add the header at the first available letter
										if(isset($spreadsheet_meta_data['column_name_by_letter']) && is_array($spreadsheet_meta_data['column_name_by_letter'])) {
											$currently_used_column_letters = array_keys($spreadsheet_meta_data['column_name_by_letter']);
											sort($currently_used_column_letters);
											$ease_row_id_column = array_search('T', $currently_used_column_letters);
											if($ease_row_id_column!==false) {
												$last_column_used_key = $ease_row_id_column - 1;
												$last_column_used_letter = $currently_used_column_letters[$last_column_used_key];
											} else {
												$last_column_used_letter = end($currently_used_column_letters);
												$cellEntry->setCell(1, 20);
												$cellEntry->setContent('EASE Row ID');
												$cellEntry->update();
											}
											$new_column_number = array_search($last_column_used_letter, $alphas) + 2;
										}
										$cellEntry->setContent($header_names[$key]);
									}
									$cellEntry->setCell(1, $new_column_number);
									$cellEntry->update();
									// dump the cached meta data for the Google Sheet and reload it, then check again
									$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
									if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
										// the column was referenced by letter... update the reference to be by name
										unset($new_row[$key]);
										$new_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
									}
								}
							}
						}
						// insert the new row
						if(is_array($new_row)) {
							$new_row_has_nonempty_value = false;
							foreach($new_row as $new_row_value) {
								if(trim($new_row_value)!='') {
									$new_row_has_nonempty_value = true;
									break;
								}
							}
							if($new_row_has_nonempty_value) {
								$new_row['easerowid'] = $this->core->new_uuid();
								$listFeed = $worksheet->getListFeed();
								$listFeed->insert($new_row);
							}
						}
					}
				}

				###############################################
				##	CREATE NEW RECORD - FOR SQL TABLE
				if(preg_match('/^for\s*"\s*(.*?)\s*"(\s*reference\s+as\s*"\s*(.*?)\s*"\s*|\s*);\s*/is', $unprocessed_create_new_record_block, $matches)) {
					// determine the SQL Table name
					$this->interpreter->inject_global_variables($matches[1]);
					$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1])), '_');
					$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9-]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
					// determine if a referenced name was provided
					$this->interpreter->inject_global_variables($matches[3]);
					$new_record_reference = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[3]));
					if(trim($new_record_reference)=='') {
						$new_record_reference = $sql_table_name;
					}

					// the FOR attribute of the CREATE NEW RECORD command was successfully parsed, scan for any remaining CREATE NEW RECORD directives
					$unprocessed_create_new_record_block = substr($unprocessed_create_new_record_block, strlen($matches[0]));

					// SEND EMAIL
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*send\s+email\s*;/is', '', $unprocessed_send_email_block);
							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*?)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*?)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// build the email message and headers according to the type
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							$result = $this->core->send_email($mail_options);
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// SET *COLUMN* TO "QUOTED-STRING" or *MATH EXPRESSION*
					$new_row = array();
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*set\s+([^;]+?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is',
						function($matches) use (&$new_row, $new_record_reference, $sql_table_name) {
							$this->interpreter->inject_global_variables($matches[1]);
							if(isset($matches[4]) && trim($matches[4])!='') {
								// math expression
								$this->interpreter->inject_global_variables($matches[4]);
								$this->interpreter->inject_local_sql_row_variables($matches[4], $new_row, $new_record_reference, $this->local_variables);
								$context_stack = $this->interpreter->extract_context_stack($matches[4]);
								if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $matches[4], $inner_matches)) {
									// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
									$eval_result = @eval("\$set_value = {$matches[4]};");
									if($eval_result===false) {
										// there was an error evaluating the expression... set the value to the expression string
										$set_value = $matches[4];
									}
								} else {
									// the math expression contained invalid characters... set the value to the broken math expression
									$set_value = $matches[4];
								}
								$this->interpreter->apply_context_stack($set_value, $context_stack);
							} else {
								// double quoted string
								$this->interpreter->inject_global_variables($matches[3]);
								$this->interpreter->inject_local_sql_row_variables($matches[3], $new_row, $new_record_reference, $this->local_variables);
								$set_value = $matches[3];
							}
							$bucket_key_parts = explode('.', $matches[1], 2);
							if(count($bucket_key_parts)==2) {
								$bucket = strtolower(rtrim($bucket_key_parts[0]));
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
								if($bucket==$new_record_reference || $bucket=='row') {
									$new_row[$key] = $set_value;
								} elseif($bucket=='session') {
									$_SESSION[$key] = $set_value;
								} elseif($bucket=='cookie') {
									setcookie($key, $set_value, time() + 60 * 60 * 24 * 365, '/');
									$_COOKIE[$key] = $set_value;
								}
							} else {
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1]));
								$new_row[$key] = $set_value;
							}
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// REDIRECT TO "QUOTED-STRING"
					$create_new_record_redirect_to = '';
					$unprocessed_create_new_record_block = preg_replace_callback(
						'/\s*redirect\s+to\s*"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$create_new_record_redirect_to) {
							$this->interpreter->inject_global_variables($matches[1]);
							$create_new_record_redirect_to = $matches[1];
							return '';
						},
						$unprocessed_create_new_record_block
					);

					// !! ANY NEW CREATE NEW RECORD - FOR SQL TABLE DIRECTIVES GET ADDED HERE

					// if the CREATE NEW RECORD block has any content remaining, there was an unrecognized directive, so log a parse error
					$this->interpreter->remove_comments($unprocessed_create_new_record_block);
					if(trim($unprocessed_create_new_record_block)!='') {
						// ERROR! the CREATE NEW RECORD block contained an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}
					// if any values for the new record were generated, create the SQL Table Instance
					if(isset($new_row) && $this->core->db && !$this->core->db_disabled) {
						// make sure the SQL table exists and has all the columns referenced in the new row
						$result = $this->core->db->query("DESCRIBE `$namespaced_sql_table_name`;");
						if($result) {
							// the SQL table exists; make sure all of the columns referenced in the new row exist in the table
							$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
							foreach(array_keys($new_row) as $column) {
								if(!in_array($column, $existing_columns)) {
									$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text not null default '';");
								}
							}
						} else {
							// the SQL table doesn't exist; create it with all of the columns referenced in the new row
							$custom_columns_sql = '';
							foreach(array_keys($new_row) as $column) {
								if(!in_array($column, $this->core->reserved_sql_columns)) {
									$custom_columns_sql .= ", `$column` text NOT NULL default ''";
								}
							}
							$sql = "CREATE TABLE `$namespaced_sql_table_name` (
										instance_id int NOT NULL PRIMARY KEY auto_increment,
										created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
										updated_on timestamp NOT NULL,
										uuid varchar(32) NOT NULL UNIQUE
										$custom_columns_sql
									);	";
							$this->core->db->exec($sql);
						}
						// insert the new row
						if(isset($new_row['uuid'])) {
							$params[':uuid'] = $new_row['uuid'];
							unset($new_row['uuid']);
						} else {
							$params[':uuid'] = $this->core->new_uuid();
						}
						$insert_columns_sql = '';
						foreach($new_row as $key=>$value) {
							$insert_columns_sql .= ",`$key`=:$key";
							$params[":$key"] = (string)$value;
						}
						$query = $this->core->db->prepare("INSERT INTO `$namespaced_sql_table_name` SET uuid=:uuid $insert_columns_sql;");
						$query->execute($params);
						// make the new record available in the EASE Globals
						$new_row['instance_id'] = $this->core->db->lastInsertId();
						$new_row['uuid'] = $params[':uuid'];
						$new_row['id'] = $new_row['uuid'];
						foreach($new_row as $key=>$value) {
							$this->core->globals[$new_record_reference . '.' . $key] = $value;
						}
					}
				}
				// if a redirect command was set in the CREATE NEW RECORD block, redirect now and stop processing this request
				if($create_new_record_redirect_to) {
					$this->interpreter->inject_local_sql_row_variables($create_new_record_redirect_to, $new_row, $new_record_reference, $this->local_variables);
					if($this->core->catch_redirect) {
						// EASE Framework configured to catch redirects
						$this->core->redirect = $create_new_record_redirect_to;
						// halt processing anything after the redirect
						$this->unprocessed_body = '';
						return;
					} else {
						header("Location: $create_new_record_redirect_to");
						exit;
					}
				}
				// done processing the the CREATE NEW RECORD block... remove it from the unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($create_new_record_block));
				return;
			}

			###############################################
			##	UPDATE RECORD
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*update\s+record\s+(.*?;\h*(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$update_record_block = $matches[0];
				$update_record_set_to_commands = array();
				$unprocessed_update_record_block = $matches[1];
				$this->interpreter->remove_comments($unprocessed_update_record_block);

				###############################################
				##	UPDATE RECORD - FOR GOOGLE SHEET ROW
				if(preg_match('/^for\s*(google\s*drive|g\s*drive|google|g|)\s*(spread|)sheet(\s*"\s*(.+?)\s*"\s*|\s+([\w-]+))(\s*"\s*(.+?)\s*"\s*|)(\s*\.|)\s*([^;]*?)\s*(and\s*reference\s*|reference\s*|)(as\s*"\s*(.*?)\s*"|)\s*;\s*/is', $unprocessed_update_record_block, $matches)) {
					// initialize a Google Sheet API client
					$this->core->validate_google_access_token();
					require_once 'ease/lib/Spreadsheet/Autoloader.php';
					$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
					$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
					Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
					$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
					// determine the Google Sheet "Name" or ID, and the EASE Row ID
					$google_spreadsheet_id = '';
					$google_spreadsheet_name = '';
					if(trim($matches[4])!='') {
						// the Google Sheet was referenced by "Name"
						$google_spreadsheet_name = $matches[4];
						$this->interpreter->inject_global_variables($google_spreadsheet_name);
						$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
						$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
						if($spreadSheet===null) {
							// the supplied Google Sheet name did not match an existing Google Sheet
							// TODO!! create a new Google Sheet using the supplied name... colsolidate the code
							// for now, error out
							echo 'Error!  Unable to load Google Sheet named: ' . htmlspecialchars($google_spreadsheet_name);
							exit;
						}
					} else {
						// the Google Sheet was referenced by ID
						$google_spreadsheet_id = $matches[5];
						$this->interpreter->inject_global_variables($google_spreadsheet_id);
						$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
						if($spreadSheet===null) {
							// there was an error loading the Google Sheet by ID...
							// flush the cached meta data for the Google Sheet ID which may no longer be valid
							$this->core->flush_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
							echo 'Error!  Unable to load Google Sheet with ID: ' . htmlspecialchars($google_spreadsheet_id);
							exit;
						}
					}
					// load the worksheets in the Google Sheet
					$worksheetFeed = $spreadSheet->getWorksheets();
					$google_spreadsheet_save_to_sheet = '';
					if(trim($matches[7])!='') {
						$google_spreadsheet_save_to_sheet = $matches[7];
						$this->interpreter->inject_global_variables($google_spreadsheet_save_to_sheet);
						$worksheet = $worksheetFeed->getByTitle($google_spreadsheet_save_to_sheet);
						if($worksheet===null) {
							// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
							// TODO! create the new worksheet.  colsolodate the code that adds new sheets
							// for now, error
							echo 'Error!  Unable to load Worksheet named: ' . htmlspecialchars($google_spreadsheet_save_to_sheet);
							exit;
						}
					} else {
						$worksheet = $worksheetFeed->getFirstSheet();
					}
					// check for unloaded worksheet
					if($worksheet===null) {
						echo "Google Sheet Error!  Unable to load worksheet.";
						exit;
					}
					if($google_spreadsheet_id!='') {
						// load the Google Sheet by the referenced ID
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id, $google_spreadsheet_save_to_sheet);
					} elseif($google_spreadsheet_name!='') {
						// load the Google Sheet by the referenced name
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name, $google_spreadsheet_save_to_sheet);
					}
					$this->interpreter->inject_global_variables($matches[9]);
					$row_id	 = preg_replace('/[^a-z0-9]+/s', '', strtolower(ltrim($matches[9])));
					// determine if a referenced name was provided
					$this->interpreter->inject_global_variables($matches[12]);
					$update_record_reference = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[12]));
					// the FOR attribute of the UPDATE RECORD command was successfully parsed, scan for any remaining UPDATE RECORD directives
					$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($matches[0]));

					// TODO!! change this to process UPDATE RECORD directives sequentially

					// SEND EMAIL
					$update_record_send_email = array();
					$unprocessed_update_record_block = preg_replace_callback(
						'/\s*send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*send\s+email\s*;/is', '', $unprocessed_send_email_block);
							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*?)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*?)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// if the SEND EMAIL block has any non-comment content remaining, there was an unrecognized EMAIL directive, so log a parse error
							$this->interpreter->remove_comments($unprocessed_send_email_block);
							if(trim($unprocessed_send_email_block)!='') {
								// ERROR! the SEND EMAIL block contained an unrecognized directive... log the block and don't attempt to process it further
								if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
									$error = $matches[0];
								} else {
									$error = $this->unprocessed_body;
								}
								$this->errors[] = print_r($send_email_attributes, true);
								$this->errors[] = $unprocessed_send_email_block;
								$this->errors[] = $error;
								$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
								return;
							}
							// build the email message and headers according to the type
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							$result = $this->core->send_email($mail_options);
							return '';
						},
						$unprocessed_update_record_block
					);

					// SET *COLUMN* TO "QUOTED-STRING" or *MATH EXPRESSION*
					$updated_row = array();
					$unprocessed_update_record_block = preg_replace_callback(
						'/\s*set\s+([^;]+?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is',
						function($matches) use (&$updated_row, $update_record_reference, $sql_table_name) {
							$this->interpreter->inject_global_variables($matches[1]);
							if(trim($matches[4])!='') {
								// math expression
								$this->interpreter->inject_global_variables($matches[4]);
								$context_stack = $this->interpreter->extract_context_stack($matches[4]);
								if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $matches[4], $inner_matches)) {
									// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
									$eval_result = @eval("\$set_value = {$matches[4]};");
									if($eval_result===false) {
										// there was an error evaluating the expression... set the value to the expression string
										$set_value = $matches[4];
									}
								} else {
									// the math expression contained invalid characters... set the value to the broken math expression
									$set_value = $matches[4];
								}
								$this->interpreter->apply_context_stack($set_value, $context_stack);
							} else {
								// double quoted string
								$this->interpreter->inject_global_variables($matches[3]);
								$set_value = $matches[3];
							}
							$bucket_key_parts = explode('.', $matches[1], 2);
							if(count($bucket_key_parts)==2) {
								$bucket = strtolower(rtrim($bucket_key_parts[0]));
								$key = ltrim($bucket_key_parts[1]);
								if($bucket==$update_record_reference || $bucket=='row' || $bucket=='record') {
									$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($key));
									$updated_row[$key] = $set_value;
								} elseif($bucket=='session') {
									$_SESSION[$key] = $set_value;
								} elseif($bucket=='cookie') {
									setcookie($key, $set_value, time() + 60 * 60 * 24 * 365, '/');
									$_COOKIE[$key] = $set_value;
								} else {
									$this->local_variables["{$bucket}.{$key}"] = $set_value;
								}
							} else {
								$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($matches[1]));
								$updated_row[$key] = $set_value;
							}
							return '';
						},
						$unprocessed_update_record_block
					);
				
					// REDIRECT TO "QUOTED-STRING"
					$update_record_redirect_to = '';
					$unprocessed_update_record_block = preg_replace_callback(
						'/\s*redirect\s+to\s*"\s*(.+?)\s*"\s*;\s*/is',
						function($matches) use (&$update_record_redirect_to) {
							$this->interpreter->inject_global_variables($matches[1]);
							$update_record_redirect_to = $matches[1];
							return '';
						},
						$unprocessed_update_record_block
					);

					// !! ANY NEW UPDATE RECORD - FOR GOOGLE SHEET ROW DIRECTIVES GET ADDED HERE

					// if the UPDATE RECORD block has any content remaining, there was an unrecognized directive, so log a parse error
					$this->interpreter->remove_comments($unprocessed_update_record_block);
					if(trim($unprocessed_update_record_block)!='') {
						// ERROR! the UPDATE RECORD block contained an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}
					// update any set values for the Google Sheet row
					if(isset($updated_row)) {
						// new values found... update the Google Sheet Row
						foreach($updated_row as $key=>$value) {
							$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($key));
							if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
								// the column was referenced by column letter... change it to reference the column header name
								unset($updated_row[$key]);
								$updated_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
							} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
								// the column was referenced by an existing column header name
							} else {
								// the referenced column wasn't found in the cached Google Sheet meta data...
								//	dump the cache and reload it, then check again... if it still isn't found, create it
								// TODO!! consolidate all the code that does this into a core function
							}
						}
						// query for the row to update
						$listFeed = $worksheet->getListFeed('', '', "easerowid = \"$row_id\"");
						$listEntries = $listFeed->getEntries();
						// update all rows that matched the requested EASE Row ID value
						// TODO!! if a matching record wasn't found, treat this as a create record command instead
						foreach($listEntries as $listEntry) {
							$current_row = $listEntry->getValues();
							foreach($current_row as $key=>$value) {
								if(!isset($updated_row[$key])) {
									$updated_row[$key] = $value;
								}
							}
							$listEntry->update($updated_row);
						}
					}
					// if a redirect command was set in the UPDATE RECORD block, redirect now and stop processing this request
					if($update_record_redirect_to) {
						$this->interpreter->inject_global_variables($update_record_redirect_to);
						if($this->core->catch_redirect) {
							// EASE Framework configured to catch redirects
							$this->core->redirect = $update_record_redirect_to;
							// halt processing anything after the redirect
							$this->unprocessed_body = '';
							return;
						} else {
							header("Location: $update_record_redirect_to");
							exit;
						}
					}
				}

				###############################################
				##	UPDATE RECORD - FOR SQL TABLE
				if(preg_match('/^for\s*"\s*(.*?)\s*"\s*((and\s*reference\s*|reference\s*|)as\s*"\s*(.*?)\s*"|)\s*;\s*/is', $unprocessed_update_record_block, $matches)) {
					// determine the SQL Table name and Instance UUID
					$this->interpreter->inject_global_variables($matches[1]);
					$sql_table_instance_parts = explode('.', $matches[1], 2);
					$this->interpreter->inject_global_variables($sql_table_instance_parts[0]);
					$this->interpreter->inject_global_variables($sql_table_instance_parts[1]);
					$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($sql_table_instance_parts[0])), '_');
					$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
					$instance_uuid = preg_replace('/[^a-z0-9]+/s', '', strtolower(ltrim($sql_table_instance_parts[1])));
					// validate the referenced record exists
					$existing_record = array();
					if(!in_array($sql_table_name, $this->core->reserved_buckets)) {
						if($this->core->db && !$this->core->db_disabled) {
							// query for all current key values for the referenced instance by UUID
							$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
							$params = array(':uuid'=>$instance_uuid);
							if($query->execute($params)) {
								$existing_record = $query->fetch(PDO::FETCH_ASSOC);
							}
						}
					}
					// determine if a referenced name was provided
					$this->interpreter->inject_global_variables($matches[4]);
					$update_record_reference = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[4]));

					// the FOR attribute of the UPDATE RECORD command was successfully parsed, scan for any remaining UPDATE RECORD directives
					$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($matches[0]));

					// SET *COLUMN* TO "QUOTED-STRING" or *MATH EXPRESSION*
					$record_updates = array();
					while(preg_match('/^(\s*\/\/(.*?)\v+|)\s*set\s+([^;]+?)\s+to\s+("\s*(.*?)\s*"|([^;]+?))\s*;\s*/is', $unprocessed_update_record_block, $set_matches)) {
						if(isset($set_matches[6]) && trim($set_matches[6])!='') {
							// math expression?
							$this->interpreter->inject_global_variables($set_matches[6]);
							$this->interpreter->inject_local_sql_row_variables($set_matches[6], $existing_record, $update_record_reference, $this->local_variables);
							$context_stack = $this->interpreter->extract_context_stack($set_matches[6]);
							if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_matches[6], $inner_matches)) {
								// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
								$eval_result = @eval("\$set_value = {$set_matches[6]};");
								if($eval_result===false) {
									// there was an error evaluating the expression... set the value to the expression string
									$set_value = $set_matches[6];
								}
							} else {
								// there were invalid characters in the math expression... set the value to the broken math expression
								$set_value = $set_matches[6];
							}
							$this->interpreter->apply_context_stack($set_value, $context_stack);
						} else {
							// double quoted string
							$this->interpreter->inject_global_variables($set_matches[5]);
							$this->interpreter->inject_local_sql_row_variables($set_matches[5], $existing_record, $update_record_reference, $this->local_variables);
							$set_value = $set_matches[5];
						}
						$bucket_key_parts = explode('.', $set_matches[3], 2);
						if(count($bucket_key_parts)==2) {
							$bucket = strtolower(rtrim($bucket_key_parts[0]));
							$key = ltrim($bucket_key_parts[1]);
							if($bucket==$update_record_reference || $bucket=='row' || $bucket=='record') {
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($key));
								$record_updates[$key] = $set_value;
								$existing_record[$key] = $set_value;
							} elseif($bucket=='session') {
								$_SESSION[$key] = $set_value;
							} elseif($bucket=='cookie') {
								setcookie($key, $set_value, time() + 60 * 60 * 24 * 365, '/');
								$_COOKIE[$key] = $set_value;
							} else {
								$this->local_variables["{$bucket}.{$key}"] = $set_value;
							}
						} else {
							$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($set_matches[3]));
							$record_updates[$key] = $set_value;
							$existing_record[$key] = $set_value;
						}
						$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($set_matches[0]));
					}
					// if any values for the new record were generated, create the SQL Table Instance
					if((count($record_updates) > 0) && $this->core->db && !$this->core->db_disabled) {
						// make sure the SQL table exists and has all the columns referenced in the new row
						$result = $this->core->db->query("DESCRIBE `$namespaced_sql_table_name`;");
						if($result) {
							// the SQL table exists; make sure all of the columns referenced in the new row exist in the table
							$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
							foreach(array_keys($record_updates) as $column) {
								if(!in_array($column, $existing_columns)) {
									$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text NOT NULL default '';");
								}
							}
						} else {
							// the SQL table doesn't exist; create it with all of the columns referenced in the new row
							foreach(array_keys($record_updates) as $column) {
								if(!in_array($column, $this->core->reserved_sql_columns)) {
									$custom_columns_sql .= ", `$column` text NOT NULL default ''";
								}
							}
							$sql = "CREATE TABLE `$namespaced_sql_table_name` (
										instance_id int NOT NULL PRIMARY KEY auto_increment,
										created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
										updated_on timestamp NOT NULL,
										uuid varchar(32) NOT NULL UNIQUE
										$custom_columns_sql
									);";
							$this->core->db->exec($sql);
						}
						// update the row
						$params = array();
						$update_columns_sql = 'updated_on=NOW()';
						foreach($record_updates as $key=>$value) {
							$params[":$key"] = (string)$value;
							$update_columns_sql .= ",`$key`=:$key";
						}
						$query = $this->core->db->prepare("UPDATE `$namespaced_sql_table_name` SET $update_columns_sql WHERE uuid='$instance_uuid';");
						$query->execute($params);
					}
				}
				// remove the UPDATE RECORD block from the unprocessed body
				if(trim($unprocessed_update_record_block)!='') {
					$ease_block_remains = $this->core->ease_block_start . " $unprocessed_update_record_block " . $this->core->ease_block_end;
				} else {
					$ease_block_remains = '';
				}
				$this->unprocessed_body = $ease_block_remains . substr($this->unprocessed_body, strlen($update_record_block));
				return;
			}

			###############################################
			##	DELETE RECORD FOR GOOGLE SHEET
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*delete\s+record\s+(for|from)\s*(google\s*drive|g\s*drive|google|g|)\s*(spread|)sheet(\s*"\s*(.+?)\s*"\s*|\s+([\w-]+))(\s*"\s*(.+?)\s*"\s*|)(\s*\.|)\s*([^;]*?)\s*(and\s*reference\s*|reference\s*|)(as\s*"\s*(.*?)\s*"|)\s*;\s*/is', $this->unprocessed_body, $matches)) {
				// initialize a Google Sheet API client
				$this->core->validate_google_access_token();
				require_once 'ease/lib/Spreadsheet/Autoloader.php';
				$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
				$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
				Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
				$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
				// determine the Google Sheet "Name" or ID, and the EASE Row ID
				$google_spreadsheet_id = '';
				$google_spreadsheet_name = '';
				if(isset($matches[5]) && trim($matches[5])!='') {
					// the Google Sheet was referenced by "Name"
					$google_spreadsheet_name = $matches[5];
					$this->interpreter->inject_global_variables($google_spreadsheet_name);
					$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
					$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
					if($spreadSheet===null) {
						// the supplied Google Sheet name did not match an existing Google Sheet
						echo 'Error!  Unable to load Google Sheet named: ' . htmlspecialchars($google_spreadsheet_name);
						exit;
					}
				} else {
					// the Google Sheet was referenced by ID
					$google_spreadsheet_id = $matches[6];
					$this->interpreter->inject_global_variables($google_spreadsheet_id);
					$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
					if($spreadSheet===null) {
						// there was an error loading the Google Sheet by ID...
						// flush the cached meta data for the Google Sheet ID which may no longer be valid
						$this->core->flush_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
						echo 'Error!  Unable to load Google Sheet: ' . htmlspecialchars($google_spreadsheet_id);
						exit;
					}
				}
				// load the worksheets in the Google Sheet
				$worksheetFeed = $spreadSheet->getWorksheets();
				$google_spreadsheet_save_to_sheet = '';
				if(trim($matches[8])!='') {
					$google_spreadsheet_save_to_sheet = $matches[8];
					$this->interpreter->inject_global_variables($google_spreadsheet_save_to_sheet);
					$worksheet = $worksheetFeed->getByTitle($google_spreadsheet_save_to_sheet);
					if($worksheet===null) {
						// the supplied worksheet name did not match an existing worksheet of the Google Sheet
						echo 'Error!  Unable to load Worksheet named: ' . htmlspecialchars($google_spreadsheet_save_to_sheet);
						exit;
					}
				} else {
					$worksheet = $worksheetFeed->getFirstSheet();
				}
				// check for unloaded worksheet
				if($worksheet===null) {
					echo "Google Sheet Error!  Unable to load worksheet.";
					exit;
				}
				// load meta data for the Google Sheet to map header names to columns
				if($google_spreadsheet_id!='') {
					// Google Sheet referenced by ID
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id, $google_spreadsheet_save_to_sheet);
				} elseif($google_spreadsheet_name!='') {
					// Google Sheet referenced by "Name"
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name, $google_spreadsheet_save_to_sheet);
				}
				$this->interpreter->inject_global_variables($matches[10]);
				$row_id	 = preg_replace('/[^a-z0-9]+/s', '', strtolower(ltrim($matches[10])));
				// determine if a referenced name was provided
				$this->interpreter->inject_global_variables($matches[13]);
				$delete_record_reference = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[13])), '_');
				// query for the row to delete
				$listFeed = $worksheet->getListFeed('', '', "easerowid = \"$row_id\"");
				$listEntries = $listFeed->getEntries();
				// delete all rows that matched the requested EASE Row ID value
				foreach($listEntries as $listEntry) {
					$listEntry->delete();
				}
				// remove the DELETE RECORD block from the unprocessed body
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}
		
			###############################################
			##	DELETE RECORD FOR SQL TABLE
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*delete\s+record(\s*|\s+for\s*|\s+from\s*)"\s*(.*?)\s*"(\s*and\s+reference\s+as\s*"\s*(.*?)\s*"\s*|\s*reference\s+as\s*"\s*(.*?)\s*"\s*|\s*as\s*"\s*(.*?)\s*"\s*|\s*);\s*/is', $this->unprocessed_body, $matches)) {
				// determine the SQL Table name and Instance UUID
				$this->interpreter->inject_global_variables($matches[2]);
				$sql_table_instance_parts = explode('.', $matches[2], 2);
				$this->interpreter->inject_global_variables($sql_table_instance_parts[0]);
				$this->interpreter->inject_global_variables($sql_table_instance_parts[1]);
				$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($sql_table_instance_parts[0])), '_');
				$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
				$instance_uuid = preg_replace('/[^a-z0-9]+/s', '', strtolower($sql_table_instance_parts[1]));
				// determine if a referenced name was provided
				$this->interpreter->inject_global_variables($matches[4]);
				$delete_record_reference = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[4]));
				// delete the row
				if($this->core->db && !$this->core->db_disabled) {
					$query = $this->core->db->prepare("DELETE FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
					$query->execute(array(':uuid'=>$instance_uuid));
				}
				// remove the DELETE RECORD block from the unprocessed body
				$this->unprocessed_body = $this->core->ease_block_start . substr($this->unprocessed_body, strlen($matches[0]));
				continue;
			}

			###############################################
			##	SEND EMAIL - Google Mail Service
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*send\s+email\s*(.*?;\h*(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$send_email_block = $matches[0];
				$send_email_attributes = array();
				$unprocessed_send_email_block = ltrim($matches[1], ';');
				$this->interpreter->remove_comments($unprocessed_send_email_block);
				// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
				$unprocessed_send_email_block = preg_replace_callback(
					'/([a-z_]*?)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
					function($matches) use (&$send_email_attributes) {
						$this->interpreter->inject_global_variables($matches[1]);
						$this->interpreter->inject_global_variables($matches[2]);
						$send_email_attributes[strtolower($matches[1])] = $matches[2];
						return '';
					},
					$unprocessed_send_email_block
				);
				// *EMAIL-ATTRIBUTE* = "quoted"
				$unprocessed_send_email_block = preg_replace_callback(
					'/\s*([a-z_]*?)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
					function($matches) use (&$send_email_attributes) {
						$this->interpreter->inject_global_variables($matches[1]);
						$this->interpreter->inject_global_variables($matches[2]);
						$send_email_attributes[strtolower($matches[1])] = $matches[2];
						return '';
					},
					$unprocessed_send_email_block
				);
				// if the SEND EMAIL block has any content remaining, there was an unrecognized EMAIL directive, so log a parse error
				$this->interpreter->remove_comments($unprocessed_send_email_block);
				if(trim($unprocessed_send_email_block)!='') {
					// ERROR! the SEND EMAIL block contained an unrecognized directive... log the block and don't attempt to process it further
					if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
						$error = $matches[0];
					} else {
						$error = $this->unprocessed_body;
					}
					$this->errors[] = print_r($send_email_attributes, true);
					$this->errors[] = $unprocessed_send_email_block;
					$this->errors[] = $error;
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
					return;
				}
				// build the email message and headers according to the type
				$mail_options = array();
				if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
					// parse the bodypage using any supplied HTTP ?query string
					$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
					if(count($send_email_espx_body_url_parts)==2) {
						$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
						$send_email_url_params = array();
						parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
					} else {
						$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
						$send_email_url_params = null;
					}
					$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
					$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
					$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
					$send_email_page_parser = null;
				}
				if(isset($send_email_attributes['from_name'])) {
					$mail_options['sender'] = $send_email_attributes['from_name'];
				}
				if(isset($send_email_attributes['to'])) {
					$mail_options['to'] = $send_email_attributes['to'];
				}
				if(isset($send_email_attributes['cc'])) {
					$mail_options['cc'] = $send_email_attributes['cc'];
				}
				if(isset($send_email_attributes['bcc'])) {
					$mail_options['bcc'] = $send_email_attributes['bcc'];
				}
				if(isset($send_email_attributes['subject'])) {
					$mail_options['subject'] = $send_email_attributes['subject'];
				}
				if(@$send_email_attributes['type']=='html') {
					$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
				} else {
					$mail_options['textBody'] = (string)$send_email_attributes['body'];
				}
				$result = $this->core->send_email($mail_options);
				// remove the SEND EMAIL block from the unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($send_email_block));
				return;
			}

			###############################################
			##	START FORM
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+form\s+(.*?(;|})\h*(\/\/\V*|)(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$start_form_block = $matches[0];
				$this->interpreter->remove_comments($matches[1]);
				$unprocessed_start_form_block = $matches[1];

				###############################################
				##	START FORM - FOR GOOGLE SHEET
				if(preg_match('/^for\s+(google\s*drive\s*|google\s*docs\s*|google\s*|g\s*drive\s*|g\s*docs\s*|g\s*|)(spread|)sheet(\s*"\s*(.*?)\s*"|\s*(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . ')|\s+([\w-]+))(\s*\.|)(\s*"\s*(.*?)\s*"|)(\s*\.\s*|\s*)(.*?)\s*;\s*/is', $unprocessed_start_form_block, $inner_matches)) {
					$this->core->validate_google_access_token();
					// determine if the Google Sheet was referenced by "Name" or ID
					$google_spreadsheet_id = null;
					$google_spreadsheet_name = null;
					$spreadsheet_meta_data = null;
					if(trim($inner_matches[4])!='') {
						// Google Sheet was referenced by "Name"
						$this->interpreter->inject_global_variables($inner_matches[4]);
						$google_spreadsheet_name = $inner_matches[4];
					} else {
						// Google Sheet was referenced by ID
						if(trim($inner_matches[5])!='') {
							$this->interpreter->inject_global_variables($inner_matches[5]);
							$google_spreadsheet_id = $inner_matches[5];
						} else {
							$google_spreadsheet_id = $inner_matches[6];
						}
					}
					// check for a referenced worksheet "Name"
					if(trim($inner_matches[9])!='') {
						// a worksheet name was provided
						$this->interpreter->inject_global_variables($inner_matches[9]);
						$save_to_sheet = $inner_matches[9];
					}
					// check for a referenced EASE Row ID
					$row_uuid = null;
					if(trim($inner_matches[11])!='') {
						// an EASE Row ID value was provided
						$this->interpreter->inject_global_variables($inner_matches[11]);
						$row_uuid = trim($inner_matches[11]);
						if($row_uuid=='0' || $row_uuid=='') {
							$row_uuid = null;
						}
					}
					// the FOR attribute of the FORM was successfully parsed, scan for any remaining FORM directives
					$unprocessed_start_form_block = substr($unprocessed_start_form_block, strlen($inner_matches[0]));
					// IF/ELSE - parse out and process any Conditionals from the start form block
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)/is',
						function($matches) {
							// process the matches to build the conditional array and any catchall else condition
							$conditions[$matches[1]] = $matches[2];
							// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
							$matches_index = 3;
							while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE IF condition
								$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
								// advance the index to look for the next ELSE IF condition
								$matches_index += 3;
							}
							// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
							if($matches_index==3) {
								// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
								$matches_index = 6;
							}
							// check for any ELSE condition
							if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE condition
								$else_text = $matches[$matches_index + 1];
							}
							// process each conditional in order to determine if any of the conditional EASE blocks should be processed
							foreach($conditions as $condition=>$conditional_text) {
								$remaining_condition = $condition;
								$php_condition_string = '';
								while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
									if(strtolower($inner_matches[1])=='and') {
										$inner_matches[1] = '&&';
									}
									if(strtolower($inner_matches[1])=='or') {
										$inner_matches[1] = '||';
									}
									if(strtolower($inner_matches[2])=='not') {
										$inner_matches[2] = '!';
									}
									if(strtolower($inner_matches[5])=='=') {
										$inner_matches[5] = '==';
									}
									if(strtolower($inner_matches[5])=='is') {
										$inner_matches[5] = '==';
									}
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$this->interpreter->inject_global_variables($inner_matches[6]);
									$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
									return $conditional_text;
								}
							}
							if(isset($else_text)) {
								return $else_text;
							} else {
								return '';
							}
						},
						$unprocessed_start_form_block
					);
					// SAVE TO - define the worksheet in the Google Sheet to use
					unset($save_to_sheet);
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*save\s*to(\s*sheet|\s*worksheet|)\s*"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$save_to_sheet) {
							$this->interpreter->inject_global_variables($matches[2]);
							$save_to_sheet = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// RESTRICT POSTS - define a variable and its required contents to allow the post (CAPTCHA)
					$restrict_post = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*restrict\s*post(ing|s|)\s*to\s*"\s*(.*?)\s*"\s*in\s*(.*?)\s*;\s*/is',
						function($matches) use (&$restrict_post) {
							$this->interpreter->inject_global_variables($matches[2]);
							$restrict_post[$matches[3]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// WHEN *ACTION* REDIRECT TO "QUOTED-STRING"
					$redirect_to_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+redirect\s+to\s*"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$redirect_to_by_action) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[2]);
							$redirect_to_by_action[$matches[1]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// WHEN *ACTION* CALL *JS-FUNCTION*
					$button_action_call_list_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+call\s+(.*?)\s*;\s*/is',
						function($matches) use (&$button_action_call_list_by_action) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[2]);
							$button_action_call_list_by_action[$matches[1]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// WHEN *ACTION* SEND EMAIL
					$send_email_list_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) use (&$send_email_list_by_action) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*when\s+[^\s]*\s+send\s+email\s*;/is', '', $unprocessed_send_email_block);
							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[1]);
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// build the email message and headers according to the type
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							$send_email_list_by_action[$matches[1]][] = $mail_options;
							return '';
						},
						$unprocessed_start_form_block
					);
					// parse out FORM Directives from the top of the START Block
					// TODO!! rewrite all the other FORM-Directive parsing (directly above), and move into this do-while structure
					$set_to_list_by_action = array();
					$calculate_list_by_action = array();
					$create_sql_record_list_by_action = array();
					$update_sql_record_list_by_action = array();
					$delete_sql_record_list_by_action = array();
					$create_spreadsheet_row_list_by_action = array();
					$update_spreadsheet_row_list_by_action = array();
					$delete_spreadsheet_row_list_by_action = array();
					$conditional_actions = array();
					$additional_form_attributes = '';
					do {
						$form_directive_found = false;
						// WHEN *ACTION* [AND *CONDITIONAL*] SET *EASE-VARIABLE* TO "QUOTED-STRING"
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)set\s+([^;]+?)\s+to\s*"(.*?)"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[5]);
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$set_to_list_by_action[$form_directive_matches[1]][$form_directive_matches[5]] = $form_directive_matches[6];
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'set_to_string',
									'variable'=>$form_directive_matches[5],
									'value'=>$form_directive_matches[6]
								);
							}
							$unprocessed_start_form_block = $form_directive_matches[7];
							continue;
						}
						// WHEN *ACTION* [AND *CONDITIONAL*] SET *EASE-VARIABLE* TO *MATH-EXPRESSION*
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)set\s+([^;]+?)\s+to\s*(.*?)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[5]);
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$calculate_list_by_action[$form_directive_matches[1]][$form_directive_matches[5]] = $form_directive_matches[6];
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'set_to_expression',
									'variable'=>$form_directive_matches[5],
									'value'=>$form_directive_matches[6]
								);
							}
							$unprocessed_start_form_block = $form_directive_matches[7];
							continue;
						}
						// WHEN *ACTION* [AND *CONDITIONAL*] CREATE NEW RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)create\s+(new\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$create_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							$create_sql_record['for'] = $form_directive_matches[6];
							$this->interpreter->inject_global_variables($form_directive_matches[10]);
							$create_sql_record['as'] = strtolower($form_directive_matches[10]);
							$unprocessed_create_sql_record_block = $form_directive_matches[11];
							do {
								$create_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+(.*?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[2]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($create_sql_record_directive_matches[1]);
									}
									$create_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+(.*?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($create_sql_record_directive_matches[1]));
									}
									$create_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
							} while($create_sql_record_directive_found);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$create_sql_record_list_by_action[$form_directive_matches[1]][] = $create_sql_record;
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'create_sql_record',
									'record'=>$create_sql_record
								);
							}
							$unprocessed_start_form_block = $unprocessed_create_sql_record_block;
							continue;
						}
						// WHEN *ACTION* UPDATE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+update\s+(old\s+|existing\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$update_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[3]);
							$update_sql_record['for'] = $form_directive_matches[3];
							$this->interpreter->inject_global_variables($form_directive_matches[7]);
							$update_sql_record['as'] = strtolower($form_directive_matches[7]);
							$unprocessed_update_sql_record_block = $form_directive_matches[8];
							do {
								$update_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+(.*?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[2]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($update_sql_record_directive_matches[1]);
									}
									$update_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+(.*?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($update_sql_record_directive_matches[1]));
									}
									$update_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
							} while($update_sql_record_directive_found);
							$update_sql_record_list_by_action[$form_directive_matches[1]][] = $update_sql_record;
							$unprocessed_start_form_block = $unprocessed_update_sql_record_block;
							continue;
						}
						// WHEN *ACTION* DELETE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+delete\s+record\s+for\s*"\s*(.*?)\s*"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$delete_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[2]);
							$delete_sql_record['for'] = strtolower($form_directive_matches[2]);
							$unprocessed_delete_sql_record_block = $form_directive_matches[3];
							do {
								$delete_sql_record_directive_found = false;
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_delete_sql_record_block, $delete_sql_record_directive_matches)) {
									$delete_sql_record_directive_found = true;
									$unprocessed_delete_sql_record_block = substr($unprocessed_delete_sql_record_block, strlen($delete_sql_record_directive_matches[0]));
									continue;
								}
							} while($delete_sql_record_directive_found);
							$delete_sql_record_list_by_action[$form_directive_matches[1]][] = $delete_sql_record;
							$unprocessed_start_form_block = $unprocessed_delete_sql_record_block;
							continue;
						}
						// SET FORM ATTRIBUTE - add attributes for the HTML <form> tag that will be generated
						if(preg_match('/^\s*set\s+form\s*\.\s*([^;]+?)\s*to\s*"(.*?)"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[1]);
							$this->interpreter->inject_global_variables($form_directive_matches[2]);
							$additional_form_attributes .= " {$form_directive_matches[1]}=\"{$form_directive_matches[2]}\"";
							$unprocessed_start_form_block = $form_directive_matches[3];
							continue;
						}

						// !! ANY NEW FORM DIRECTIVES FOR GOOGLE SHEET GET ADDED HERE

					} while($form_directive_found);
					// if the START Block has any non-comment content remaining, there was an unrecognized FORM - FOR GOOGLE SHEET Directive
					$this->interpreter->remove_comments($unprocessed_start_form_block);
					$this->interpreter->inject_global_variables($unprocessed_start_form_block);
					if(trim($unprocessed_start_form_block)!='') {
						// ERROR! the START Block contained an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $inner_matches)) {
							$error = $inner_matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}
					// remove the START Block from the unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($start_form_block));
					// find the END FORM and parse out the form body
					if(preg_match('/(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*form\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
						// END FORM tag found, process the EASE form to generate an HTML form
						$form_body = $matches[1];
						// initialize form information from the user session
						if(isset($_REQUEST['ease_form_id']) && isset($_SESSION['ease_forms'][$_REQUEST['ease_form_id']])) {
							$form_id = $_REQUEST['ease_form_id'];
						} else {
							$form_id = $this->core->new_uuid();
							$_SESSION['ease_forms'][$form_id]['created_on'] = time();
							@$_SESSION['ease_forms'][$form_id]['google_spreadsheet_id'] = $google_spreadsheet_id;
							@$_SESSION['ease_forms'][$form_id]['google_spreadsheet_name'] = $google_spreadsheet_name;
							@$_SESSION['ease_forms'][$form_id]['save_to_sheet'] = $save_to_sheet;
							@$_SESSION['ease_forms'][$form_id]['restrict_post'] = $restrict_post;
							@$_SESSION['ease_forms'][$form_id]['set_to_list_by_action'] = $set_to_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['calculate_list_by_action'] = $calculate_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['send_email_list_by_action'] = $send_email_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['redirect_to_by_action'] = $redirect_to_by_action;
							@$_SESSION['ease_forms'][$form_id]['create_sql_record_list_by_action'] = $create_sql_record_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['update_sql_record_list_by_action'] = $update_sql_record_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['delete_sql_record_list_by_action'] = $delete_sql_record_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['create_spreadsheet_row_list_by_action'] = $create_spreadsheet_row_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['update_spreadsheet_row_list_by_action'] = $update_spreadsheet_row_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['delete_spreadsheet_row_list_by_action'] = $delete_spreadsheet_row_list_by_action;
							@$_SESSION['ease_forms'][$form_id]['conditional_actions'] = $conditional_actions;
						}
						// if this is an edit form, pull the current values for the existing row being edited
						$existing_row = array();
						if(isset($row_uuid)) {
							// initialize a Google Sheet API client
							require_once 'ease/lib/Spreadsheet/Autoloader.php';
							$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
							$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
							Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
							$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
							// determine the Google Sheet "Name" or ID, and the EASE Row ID
							if(trim($google_spreadsheet_name)!='') {
								// the Google Sheet was referenced by "Name"
								$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
								$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
								if($spreadSheet===null) {
									// the supplied Google Sheet name did not match an existing Google Sheet
									// TODO!! create a new Google Sheet using the supplied name... colsolidate the code... for now, error out
									echo 'Error!  Unable to load Google Sheet named: ' . htmlspecialchars($google_spreadsheet_name);
									exit;
								}
							} elseif(trim($google_spreadsheet_id)!='') {
								// the Google Sheet was referenced by ID
								$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
								if($spreadSheet===null) {
									// there was an error loading the Google Sheet by ID...
									// flush the cached meta data for the Google Sheet ID which may no longer be valid
									$this->core->flush_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
									echo 'Error!  Unable to load Google Sheet: ' . htmlspecialchars($google_spreadsheet_id);
									exit;
								}
							} else {
								echo 'Form for Google Sheet Error!  Missing Sheet ID or Name';
								exit;
							}
							// load the worksheets in the Google Sheet
							$worksheetFeed = $spreadSheet->getWorksheets();
							if($save_to_sheet) {
								$worksheet = $worksheetFeed->getByTitle($save_to_sheet);
								if($worksheet===null) {
									// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
									// TODO! create the new worksheet.  colsolodate the code that adds new sheets
									// for now, error
									echo 'Form for Google Sheet Error!  Unable to load Worksheet named: ' . htmlspecialchars($save_to_sheet);
									exit;
								}
							} else {
								$worksheet = $worksheetFeed->getFirstSheet();
							}
							// check for unloaded worksheet
							if($worksheet===null) {
								echo 'Google Sheet Error!  Unable to load Worksheet';
								exit;
							}
							// load meta data for the Google Sheet
							if(trim($google_spreadsheet_id)!='') {
								// load the Google Sheet by the referenced ID
								$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id, $save_to_sheet);
							} elseif(trim($google_spreadsheet_name)!='') {
								// load the Google Sheet by the referenced name
								$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name, $save_to_sheet);
							}
							// query for the row to update
							$listFeed = $worksheet->getListFeed('', '', "easerowid = \"$row_uuid\"");
							$listEntries = $listFeed->getEntries();
							if(is_array($listEntries) && count($listEntries)>0) {
								// the referenced EASE Row ID matched an existing row
								@$_SESSION['ease_forms'][$form_id]['row_uuid'] = $row_uuid;
								foreach($listEntries as $listEntry) {
									$existing_row = $listEntry->getValues();
									// convert the listEntry column header keys to column letter keys
									foreach($existing_row as $key=>$value) {
										if(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
											unset($existing_row[$key]);
											$existing_row[$spreadsheet_meta_data['column_letter_by_name'][$key]] = $value;
										}
									}
								}
							} else {
								// the referenced EASE Row ID did not match an existing row
								// treat this as a "creating" action and use the referenced ID when creating the record
								@$_SESSION['ease_forms'][$form_id]['new_row_uuid'] = $row_uuid;
								$row_uuid = null;
							}
						}
						// parse out and process any Conditional EASE in the form body, replacing the block with contents of the matching condition
						$form_body = preg_replace_callback(
							'/' . preg_quote($this->core->ease_block_start, '/') . '\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)' . preg_quote($this->core->ease_block_end, '/') . '/is',
							function($matches) {
								// process the matches to build the conditional array and any catchall else condition
								$conditions[$matches[1]] = $matches[2];
								// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
								$matches_index = 3;
								while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
									// found an ELSE IF condition
									$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
									// advance the index to look for the next ELSE IF condition
									$matches_index += 3;
								}
								// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
								if($matches_index==3) {
									// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
									$matches_index = 6;
								}
								// check for any ELSE condition
								if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
									// found an ELSE condition
									$else_text = $matches[$matches_index + 1];
								}
								// process each conditional in order to determine if any of the conditional EASE blocks should be processed
								foreach($conditions as $condition=>$conditional_text) {
									$remaining_condition = $condition;
									$php_condition_string = '';
									while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
										if(strtolower($inner_matches[1])=='and') {
											$inner_matches[1] = '&&';
										}
										if(strtolower($inner_matches[1])=='or') {
											$inner_matches[1] = '||';
										}
										if(strtolower($inner_matches[2])=='not') {
											$inner_matches[2] = '!';
										}
										if($inner_matches[5]=='=') {
											$inner_matches[5] = '==';
										}
										if(strtolower($inner_matches[5])=='is') {
											$inner_matches[5] = '==';
										}
										$this->interpreter->inject_global_variables($inner_matches[4]);
										$this->interpreter->inject_global_variables($inner_matches[6]);
										$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
										$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
									}
									if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
										return $conditional_text;
									}
								}
								if(isset($else_text)) {
									return $else_text;
								} else {
									return '';
								}
							},
							$form_body
						);
						// inject any global variables into the form body
						$this->interpreter->inject_global_variables($form_body);
						// process INPUT tags in the FORM body with an EASE tag attribute
						$form_body = preg_replace_callback(
							'/<\s*input\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)(\/\s*|)>/is',
							function($matches) use (&$form_id, $spreadsheet_meta_data, $existing_row, $button_action_call_list_by_action) {
								// an EASE tag was found as an HTML INPUT tag attribute
								$input_ease_reference = $matches[9];
								// process all of the HTML INPUT tag attributes (other than the EASE tag)
								$input_attributes = "{$matches[1]} {$matches[10]}";
								$input_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $input_attributes, $input_attribute_matches);
								foreach($input_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// input attribute assigned a value with =
										$input_attribute_key = strtolower($value);
										if($input_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>"'",
												'value'=>$input_attribute_matches[4][$key]
											);
										} elseif($input_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>'"',
												'value'=>$input_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>'',
												'value'=>$input_attribute_matches[9][$key]
											);
										}
									} else {
										// input attribute with no assigned value
										$input_attribute_key = strtolower($input_attribute_matches[10][$key]);
										$input_attributes_by_key[$input_attribute_key] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								$input_attributes_by_key['type']['value'] = strtolower($input_attributes_by_key['type']['value']);
								// process the INPUT tag by type
								switch($input_attributes_by_key['type']['value']) {
									case 'checkbox':
										$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
										if(count($input_ease_reference_parts)==2) {
											$bucket = strtolower(rtrim($input_ease_reference_parts[0]));
											if($bucket=='row') {
												$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($input_ease_reference_parts[1])));
												$input_attributes_by_key['name'] = array(
													'quote'=>'"',
													'value'=>"row_$header_reference"
												);
												$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
													'original_name'=>ltrim($input_ease_reference_parts[1]),
													'header_reference'=>$header_reference
												);
											}
										} else {
											$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($input_ease_reference));
											$input_attributes_by_key['name'] = array(
												'quote'=>'"',
												'value'=>"row_$header_reference"
											);
											$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
												'original_name'=>$input_ease_reference,
												'header_reference'=>$header_reference
											);
										}
										if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
											if($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										} elseif(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
											if($spreadsheet_meta_data['column_letter_by_name'][$header_reference]) {
												$column_letter = $spreadsheet_meta_data['column_letter_by_name'][$header_reference];
											} else {
												$column_letter = strtoupper($header_reference);
											}
											if(isset($existing_row[$column_letter]) && $existing_row[$column_letter]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										}
										if(!isset($input_attributes_by_key['value'])) {
											$input_attributes_by_key['value'] = array(
												'quote'=>'"',
												'value'=>'Yes'
											);
										}
										if(!isset($input_attributes_by_key['style']) && !isset($input_attributes_by_key['class'])) {
											$input_attributes_by_key['style'] = array(
												'quote'=>'"',
												'value'=>'width:13px; height:13px; padding:0; margin:0; position:relative; top:-1px; *overflow:hidden;'
											);
										}
										break;
									case 'radio':
										$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
										if(count($input_ease_reference_parts)==2) {
											$bucket = strtolower(rtrim($input_ease_reference_parts[0]));
											if($bucket=='row') {
												$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($input_ease_reference_parts[1])));
												$input_attributes_by_key['name'] = array(
													'quote'=>'"',
													'value'=>"row_$header_reference"
												);
												$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
													'original_name'=>ltrim($input_ease_reference_parts[1]),
													'header_reference'=>$header_reference
												);
											}
										} else {
											$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($input_ease_reference));
											$input_attributes_by_key['name'] = array(
												'quote'=>'"',
												'value'=>"row_$header_reference"
											);
											$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
												'original_name'=>$input_ease_reference,
												'header_reference'=>$header_reference
											);
										}
										if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
											if($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										} elseif(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
											if($spreadsheet_meta_data['column_letter_by_name'][$header_reference]) {
												$column_letter = $spreadsheet_meta_data['column_letter_by_name'][$header_reference];
											} else {
												$column_letter = strtoupper($header_reference);
											}
											if($existing_row[$column_letter]==$input_attributes_by_key['value']['value']) {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										}
										if(!isset($input_attributes_by_key['style']) && !isset($input_attributes_by_key['class'])) {
											$input_attributes_by_key['style'] = array(
												'quote'=>'"',
												'value'=>'width:13px; height:13px; padding:0; margin:0; position:relative; top:-1px; *overflow:hidden;'
											);
										}
										break;
									case 'submit':
									case 'button':
										// process the button name to determine the type
										preg_match('/(.*?)\s*button/is', $input_ease_reference, $button_matches);
										$button_reference = preg_replace('/[^a-z0-9\._-]+/is', '', strtolower($button_matches[1]));
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"button_{$button_reference}"
										);
										if($button_reference=='create' || $button_reference=='creating') {
											// this was a BUTTON INPUT tag with a CREATE action;  check if this is an EDIT form
											if(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
												// this is an EDIT form; remove this button from the form
												return '';
											}
											// this is a CREATE form; change the input type to submit and set the button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['creating'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['creating'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'creating',
												'handler'=>'add_row_to_googlespreadsheet'
											);
										} elseif($button_reference=='update' || $button_reference=='updating') {
											// this was a BUTTON INPUT tag with a UPDATE action;  check if this is an EDIT form
											if(!isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
												// this is not an EDIT form; remove this button from the form
												return '';
											}
											// this is an EDIT form; change the button type to the default submit action and set the button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['updating'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['updating'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'updating',
												'handler'=>'update_row_in_googlespreadsheet'
											);
										} elseif($button_reference=='delete' || $button_reference=='deleting') {
											// this was a BUTTON INPUT tag with a DELETE action;  check if this is an EDIT form
											if(!isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
												// this is not an EDIT form; remove the button
												return '';
											}
											// this is an EDIT form; change the input type to require clicking the DELETE button, and set button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['deleting'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['deleting'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'deleting',
												'handler'=>'delete_row_from_googlespreadsheet'
											);
										} else {
											// this is a custom form action
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action[$button_reference])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action[$button_reference])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['action'] = $button_reference;
											if(!isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
												$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'add_row_to_googlespreadsheet';
											} else {
												$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'update_row_in_googlespreadsheet';
											}
										}
										break;
									default:
									// default input for text, number, decimal, date, email, etc...
									$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
									if(count($input_ease_reference_parts)==2) {
										$bucket = strtolower(rtrim($input_ease_reference_parts[0]));
										if($bucket=='row') {
											$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($input_ease_reference_parts[1])));
											$input_attributes_by_key['name'] = array(
												'quote'=>'"',
												'value'=>"row_$header_reference"
											);
											$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
												'original_name'=>ltrim($input_ease_reference_parts[1]),
												'header_reference'=>$header_reference
											);
										}
									} else {
										$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($input_ease_reference));
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"row_$header_reference"
										);
										$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = array(
											'original_name'=>$input_ease_reference,
											'header_reference'=>$header_reference
										);
									}
									if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
										// this is an input validation rejection form, set the input values as previously posted
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])
										);
									} elseif(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
										if($spreadsheet_meta_data['column_letter_by_name'][$header_reference]) {
											$column_letter = $spreadsheet_meta_data['column_letter_by_name'][$header_reference];
										} else {
											$column_letter = strtoupper($header_reference);
										}
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($existing_row[$column_letter])
										);
									}
									if($input_attributes_by_key['type']['value']=='date' && isset($input_attributes_by_key['value']['value']) && trim($input_attributes_by_key['value']['value'])!='') {
										$datetime = strtotime($input_attributes_by_key['value']['value']);
										$input_attributes_by_key['value']['value'] = date('Y-m-d', $datetime);
									}
									if($input_attributes_by_key['type']['value']=='datetime' && isset($input_attributes_by_key['value']['value']) && trim($input_attributes_by_key['value']['value'])!='') {
										$datetime = strtotime($input_attributes_by_key['value']['value']);
										$input_attributes_by_key['value']['value'] = date('Y-m-d H:i:s', $datetime);
									}
									if($input_attributes_by_key['type']['value']=='decimal' || $input_attributes_by_key['type']['value']=='number') {
										$input_attributes_by_key['type']['value'] = 'number';
										if(!isset($input_attributes_by_key['step'])) {
											$input_attributes_by_key['step'] = array('quote'=>'"', 'value'=>'any');
										}
									}
									if($input_attributes_by_key['type']['value']=='integer') {
										$input_attributes_by_key['type']['value'] = 'number';
										if(!isset($input_attributes_by_key['step'])) {
											$input_attributes_by_key['step'] = array('quote'=>'"', 'value'=>'1');
										} else {
											$input_attributes_by_key['step']['value'] = intval($input_attributes_by_key['step']['value']);
										}
									}
									// set validations for supported input types
									switch($input_attributes_by_key['type']['value']) {
										case 'email':
										case 'number':
										case 'integer':
										case 'decimal':
										case 'price':
										case 'dollars':
										case 'usd':
										case 'cost':
										case 'url':
										case 'date':
										case 'datetime':
											$_SESSION['ease_forms'][$form_id]['input_validations'][$input_attributes_by_key['name']['value']] = $input_attributes_by_key['type']['value'];
											break;
										case 'decimalrange':
										case 'decimal-range':
											$_SESSION['ease_forms'][$form_id]['input_ranges'][$input_attributes_by_key['name']['value']] = array('type'=>'decimal', 'min'=>$input_attributes_by_key['min']['value'], 'max'=>$input_attributes_by_key['max']['value']);
											break;
										case 'range':
											$_SESSION['ease_forms'][$form_id]['input_ranges'][$input_attributes_by_key['name']['value']] = array('type'=>'integer', 'min'=>$input_attributes_by_key['min']['value'], 'max'=>$input_attributes_by_key['max']['value']);
											break;
										default:
									}
									if(isset($input_attributes_by_key['min'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['min'] = $input_attributes_by_key['min']['value'];
									}
									if(isset($input_attributes_by_key['max'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['max'] = $input_attributes_by_key['max']['value'];
									}
									if(isset($input_attributes_by_key['step'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['step'] = $input_attributes_by_key['step']['value'];
									}
									if(isset($input_attributes_by_key['pattern'])) {
										$_SESSION['ease_forms'][$form_id]['input_patterns'][$input_attributes_by_key['name']['value']] = stripslashes($input_attributes_by_key['pattern']['value']);
									}
									if(isset($input_attributes_by_key['required'])) {
										$_SESSION['ease_forms'][$form_id]['input_requirements'][$input_attributes_by_key['name']['value']] = true;
									}
								}
								// inject the processed HTML INPUT tag back into the form body
								$input_attributes_string = '';
								foreach($input_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$input_attributes_string .= "$key ";
									} else {
										$input_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$input_string = "<input $input_attributes_string/>";
								if(isset($_SESSION['ease_forms'][$form_id]['invalid_inputs'][$input_attributes_by_key['name']['value']])) {
									$input_string .= '<br /><span style="color:red; font-weight:bold;">' . htmlspecialchars($_SESSION['ease_forms'][$form_id]['invalid_inputs'][$input_attributes_by_key['name']['value']]) . '</span>';
								}
								return $input_string;
							},
							$form_body
						);
						// process SELECT blocks in the FORM body with EASE tags
						$form_body = preg_replace_callback(
							'/<\s*select\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>\s*(.*?)\s*<\s*\/\s*select\s*>/is',
							function($matches) use (&$form_id, $spreadsheet_meta_data, $existing_row) {
								// an EASE tag was found in a SELECT block
								$select_ease_reference = $matches[9];
								// process all of the HTML SELECT tag attributes (other than the EASE tag)
								$select_attributes = "{$matches[1]} {$matches[10]}";
								$select_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $select_attributes, $select_attribute_matches);
								foreach($select_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// select attribute assigned a value with =
										if($select_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$select_attribute_matches[4][$key]
											);
										} elseif($select_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$select_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$select_attribute_matches[9][$key]
											);
										}
									} else {
										// select attribute with no assigned value
										$select_attributes_by_key[$select_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								$select_ease_reference_split = explode('.', $select_ease_reference, 2);
								if(count($select_ease_reference_split)==2) {
									$bucket = strtolower(rtrim($select_ease_reference_split[0]));
									if($bucket=='row') {
										$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($select_ease_reference_split[1])));
										$select_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"row_$header_reference"
										);
										$_SESSION['ease_forms'][$form_id]['inputs']["row_$header_reference"] = array(
											'original_name'=>ltrim($select_ease_reference_split[1]),
											'header_reference'=>$header_reference
										);
									}
								} else {
									$header_reference = preg_replace('/[^a-z0-9]/s', '', strtolower($select_ease_reference));
									$select_attributes_by_key['name'] = array(
										'quote'=>'"',
										'value'=>"row_$header_reference"
									);
									$_SESSION['ease_forms'][$form_id]['inputs']["row_$header_reference"] = array(
										'original_name'=>$select_ease_reference,
										'header_reference'=>$header_reference
									);
								}
								// process each OPTION in the SELECT block
								$select_options_string = '';
								$remaining_select_body = $matches[18];
								while(preg_match('/\s*<\s*option\s*((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)>(.*?)<\s*\/\s*option\s*>\s*/is', $remaining_select_body, $inner_matches)) {
									// process all of the OPTION attributes
									$option_attributes_by_key = array();
									preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $inner_matches[1], $option_attribute_matches);
									foreach($option_attribute_matches[1] as $key=>$value) {
										if(trim($value)!='') {
											// option attribute assigned a value with =
											if($option_attribute_matches[3][$key]=="'") {
												// the value was wrapped in single quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>"'",
													'value'=>$option_attribute_matches[4][$key]
												);
											} elseif($option_attribute_matches[6][$key]=='"') {
												// the value was wrapped in double quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>'"',
													'value'=>$option_attribute_matches[7][$key]
												);
											} else {
												// the value was not wrapped in quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>'',
													'value'=>$option_attribute_matches[9][$key]
												);
											}
										} else {
											// option attribute with no assigned value
											$option_attributes_by_key[$option_attribute_matches[10][$key]] = array(
												'quote'=>'',
												'value'=>''
											);
										}
									}
									// if a value attribute wasn't set, default it to the OPTION body
									if(!isset($option_attributes_by_key['value'])) {
										$option_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($inner_matches[9])
										);
									}
									// if this is an edit form, default the selected OPTION to the existing value
									if(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
										if($spreadsheet_meta_data['column_letter_by_name'][$header_reference]) {
											$column_letter = $spreadsheet_meta_data['column_letter_by_name'][$header_reference];
										} else {
											$column_letter = strtoupper($header_reference);
										}
										if($existing_row[$column_letter]==$option_attributes_by_key['value']['value']) {
											$option_attributes_by_key['selected'] = array(
												'quote'=>'"',
												'value'=>'selected'
											);
										} else {
											unset($option_attributes_by_key['selected']);
										}
									}
									// replace the original OPTION block with the processed attributes
									$options_attributes_string = '';
									foreach($option_attributes_by_key as $key=>$value) {
										if($value['quote']=='' && $value['value']=='') {
											$options_attributes_string .= "$key ";
										} else {
											$options_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
										}
									}
									$select_options_string .= "<option $options_attributes_string>$inner_matches[9]</option>\n";
									$remaining_select_body = substr($remaining_select_body, strlen($inner_matches[0]));
								}
								// replace the original SELECT block with the processed attributes
								$select_attributes_string = '';
								foreach($select_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$select_attributes_string .= "$key ";
									} else {
										$select_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								return "<select $select_attributes_string>$select_options_string</select>";
							},
							$form_body
						);
						// process TEXTAREA tags in the FORM body
						$form_body = preg_replace_callback(
							'/<\s*textarea\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>(.*?)<\s*\/\s*textarea\s*>/is',
							function($matches) use (&$form_id, $spreadsheet_meta_data, $existing_row) {
								// an EASE variable was referenced in a TEXTAREA
								$textarea_ease_reference = $matches[9];
								// process all of the HTML TEXTAREA tag attributes (other than the EASE tag)
								$textarea_attributes = "{$matches[1]} {$matches[10]}";
								$textarea_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $textarea_attributes, $textarea_attribute_matches);
								foreach($textarea_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// textarea attribute assigned a value with =
										if($textarea_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$textarea_attribute_matches[4][$key]
											);
										} elseif($textarea_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$textarea_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$textarea_attribute_matches[9][$key]
											);
										}
									} else {
										// textarea attribute with no assigned value
										$textarea_attributes_by_key[$textarea_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								// parse the EASE tag to determine the column header
								$textarea_ease_reference_split = explode('.', $textarea_ease_reference, 2);
								if(count($textarea_ease_reference_split)==2) {
									$bucket = strtolower(rtrim($textarea_ease_reference_split[0]));
									if($bucket=='row') {
										$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($textarea_ease_reference_split[1])));
										$textarea_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"row_$header_reference"
										);
										$_SESSION['ease_forms'][$form_id]['inputs']["row_$header_reference"] = array(
											'original_name'=>ltrim($textarea_ease_reference_split[1]),
											'header_reference'=>$header_reference
										);
									}
								} else {
									$header_reference = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($textarea_ease_reference));
									$textarea_attributes_by_key['name'] = array(
										'quote'=>'"',
										'value'=>"row_$header_reference"
									);
									$_SESSION['ease_forms'][$form_id]['inputs']["row_$header_reference"] = array(
										'original_name'=>$textarea_ease_reference,
										'header_reference'=>$header_reference
									);
								}
								// build the new TEXTAREA HTML tag with attributes
								$textarea_attributes_string = '';
								foreach($textarea_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$textarea_attributes_string .= "$key ";
									} else {
										$textarea_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$return = "<textarea $textarea_attributes_string>";
								if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$textarea_attributes_by_key['name']['value']])) {
									// this is an edit form, populate the textarea with the current value for the instance
									$return .= htmlspecialchars($_SESSION['ease_forms'][$form_id]['post_values'][$textarea_attributes_by_key['name']['value']]);
								} elseif(isset($_SESSION['ease_forms'][$form_id]['row_uuid'])) {
									if($spreadsheet_meta_data['column_letter_by_name'][$header_reference]) {
										$column_letter = $spreadsheet_meta_data['column_letter_by_name'][$header_reference];
									} else {
										$column_letter = strtoupper($header_reference);
									}
									$return .= htmlspecialchars(@$existing_row[$column_letter]);
								} else {
									// this is a create form, leave any preset textarea content in place
									$return .= $matches[18];
								}
								$return .= "</textarea>";
								return $return;
							},
							$form_body
						);
						// process BUTTON tags in the FORM body
						$form_body = preg_replace_callback(
							'/<\s*button\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>(.*?)<\s*\/\s*button\s*>/is',
							function($matches) use (&$form_id, $existing_row) {
								// an EASE variable was referenced in a BUTTON
								$button_ease_reference = $matches[9];
								// process all of the HTML BUTTON tag attributes (other than the EASE tag)
								$button_attributes = "{$matches[1]} {$matches[10]}";
								$button_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $button_attributes, $button_attribute_matches);
								foreach($button_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// button attribute assigned a value with =
										if($button_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$button_attribute_matches[4][$key]
											);
										} elseif($button_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$button_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$button_attribute_matches[9][$key]
											);
										}
									} else {
										// button attribute with no assigned value
										$button_attributes_by_key[$button_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								// process the button name to determine the type
								preg_match('/(.*?)\s*button/is', $button_ease_reference, $button_matches);
								$button_reference = preg_replace('/[^a-z0-9\._-]+/is', '', strtolower($button_matches[1]));
								$button_attributes_by_key['name'] = array(
									'quote'=>'"',
									'value'=>"button_{$button_reference}"
								);
								if($button_reference=='create' || $button_reference=='creating') {
									// this was a BUTTON tag with a CREATE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']!='') {
										// this is an EDIT form; remove this button from the form
										return '';
									}
									// this is a CREATE form; change the input type to submit and set the button handler
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['creating'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['creating'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'creating',
										'handler'=>'add_row_to_googlespreadsheet'
									);
								} elseif($button_reference=='update' || $button_reference=='updating') {
									// this was a BUTTON INPUT tag with a UPDATE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										// this is not an EDIT form; remove this button from the form
										return '';
									}
									// this is an EDIT form; change the button type to the default submit action and set the button handler
									$input_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['updating'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['updating'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'updating',
										'handler'=>'update_row_in_google_spreadsheet'
									);
								} elseif($button_reference=='delete' || $button_reference=='deleting') {
									// this was a BUTTON INPUT tag with a DELETE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										// this is not an EDIT form; remove this button from the form
										return '';
									}
									// this is an EDIT form; change the input type to require clicking the DELETE button, and set button handler
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['deleting'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['deleting'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'deleting',
										'handler'=>'delete_row_from_googlespreadsheet'
									);
								} else {
									// this is a custom form action
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action[$button_reference])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action[$button_reference])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['action'] = $button_reference;
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'add_row_to_googlespreadsheet';
									} else {
										$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'update_row_in_googlespreadsheet';
									}
								}
								// build the new BUTTON HTML tag with attributes
								$button_attributes_string = '';
								foreach($button_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$button_attributes_string .= "$key ";
									} else {
										$button_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$return = "<button $button_attributes_string>";
								if(trim($matches[18])=='') {
									$return .= $button_attributes_by_key['value']['value'];
								} else {
									$return .= $matches[18];
								}
								$return .= "</button>";
								return $return;
							},
							$form_body
						);
						// build the form HTML and any input validation JavaScript, then inject them into the output buffer
						$form_attributes = "enctype='multipart/form-data' method='post' accept-charset='utf-8'";
						// determine if client side form validation is required
						if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) || isset($_SESSION['ease_forms'][$form_id]['input_requirements']) || isset($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
							$form_attributes .= " onsubmit='return validate_$form_id(this)'";
						}
						$form_attributes .= $additional_form_attributes;
						// inject the form HTML into the output buffer
						$this->output_buffer .= "<form action='" . $this->core->service_endpoints['form'] . "' $form_attributes>\n";
						$this->output_buffer .= "<input type='hidden' id='ease_form_id' name='ease_form_id' value='$form_id' />\n";
						$this->output_buffer .= trim($form_body) . "\n";
						$this->output_buffer .= "</form>";
						// if any form inputs require validation, inject the input validation javascript
						if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) || isset($_SESSION['ease_forms'][$form_id]['input_requirements']) || isset($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
							$form_validation_javascript = '';
							if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) && is_array($_SESSION['ease_forms'][$form_id]['input_validations'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_validations'] as $input_name=>$input_type) {
									switch($input_type) {
										case 'email':
		 									$form_validation_javascript .= "	re = /^\\S+@\\S+$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid email address.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'integer':
											$form_validation_javascript .= "	re = /^[0-9-]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid integer.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'number':
										case 'decimal':
											$form_validation_javascript .= "	re = /^[0-9\\.-]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].select();\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid number.\\n\\nInvalid: ' + window.getSelection().toString());\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'usd':
										case 'price':
										case 'cost':
										case 'dollars':
											$form_validation_javascript .= "	re = /^[0-9\$,\\. -]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid dollar value.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'date':
											$form_validation_javascript .= "	re = /^[0-9\\/\\. -]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid date.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										default:
									}
								}
							}
							if(isset($_SESSION['ease_forms'][$form_id]['input_patterns']) && is_array($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_patterns'] as $input_name=>$regex_pattern) {
									$form_validation_javascript .= "	re = /^$regex_pattern$/;\n";
									$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
									$form_validation_javascript .= "		alert(\"Please enter a value matching this pattern:\\n" . htmlspecialchars($regex_pattern) . "\\n\\nInvalid: \" + ease_form.elements['$input_name'].value);\n";
									$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
									$form_validation_javascript .= "		return false;\n";
									$form_validation_javascript .= "	}\n";
									break;
								}
							}
							if(isset($_SESSION['ease_forms'][$form_id]['input_requirements']) && is_array($_SESSION['ease_forms'][$form_id]['input_requirements'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_requirements'] as $input_name=>$required) {
									if($required) {
										$form_validation_javascript .= "	if(ease_form.elements['$input_name'].value=='') {\n";
		 								$form_validation_javascript .= "		alert('A value is required.');\n";
		 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
										$form_validation_javascript .= "		return false;\n";
		 								$form_validation_javascript .= "	}\n";
									}
								}
							}
							$this->output_buffer .= "\n<script type='text/javascript'>\nfunction validate_$form_id(ease_form) {\n\tvar re;\n$form_validation_javascript}\n</script>";
						}
						// remove the parsed form body and the END FORM block from the remaining unprocessed body
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
					} else {
						// an END FORM tag was not found, display an error
						$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  EASE FORM without an END FORM tag.</div>";
					}
					// done processing FORM - FOR GOOGLE SHEET, return to process the remaining body
					return;
				}

				###############################################
				##	START FORM - FOR SQL TABLE
				if(preg_match('/^for\s+(\w+)\s*(.*?)\s*;\s*/is', $unprocessed_start_form_block, $matches)) {
					// determine the SQL Table name
					$sql_table_name = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($matches[1])), '_');
					$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
					// check for a record ID in the FOR attribute, implying an edit form
					$this->interpreter->inject_global_variables($matches[2]);
					$instance_uuid = trim($matches[2]);
					if($instance_uuid=='0') {
						$instance_uuid = '';
					}

					// the FOR attribute of the FORM was successfully parsed, scan for any remaining FORM directives
					$unprocessed_start_form_block = substr($unprocessed_start_form_block, strlen($matches[0]));

					// IF/ELSE - parse out and process any Conditionals from the START Block
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)/is',
						function($matches) {
							// process the matches to build the conditional array, and any else condition if a match isn't found
							$conditions[$matches[1]] = $matches[2];
							// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
							$matches_index = 3;
							while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE IF condition
								$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
								// advance the index to look for the next ELSE IF condition
								$matches_index += 3;
							}
							// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
							if($matches_index==3) {
								// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
								$matches_index = 6;
							}
							// check for any ELSE condition
							if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE condition
								$else_text = $matches[$matches_index + 1];
							}
							// process each conditional in order to determine if any of the conditional EASE blocks should be processed
							foreach($conditions as $condition=>$conditional_text) {
								$remaining_condition = $condition;
								$php_condition_string = '';
								while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
									if(strtolower($inner_matches[1])=='and') {
										$inner_matches[1] = '&&';
									}
									if(strtolower($inner_matches[1])=='or') {
										$inner_matches[1] = '||';
									}
									if(strtolower($inner_matches[2])=='not') {
										$inner_matches[2] = '!';
									}
									if($inner_matches[5]=='=' || $inner_matches[5]=='===' || strtolower($inner_matches[5])=='is') {
										$inner_matches[5] = '==';
									}
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$this->interpreter->inject_global_variables($inner_matches[6]);
									$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
									return $conditional_text;
								}
							}
							if(isset($else_text)) {
								return $else_text;
							} else {
								return '';
							}
						},
						$unprocessed_start_form_block
					);
				
					// RESTRICT POSTS - define a variable and its required contents to allow the post (CAPTCHA)
					$restrict_post = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*restrict\s*post(ing|s|)\s*to\s*"\s*(.*?)\s*"\s*in\s*(.*?)\s*;\s*/is',
						function($matches) use (&$restrict_post) {
							$this->interpreter->inject_global_variables($matches[2]);
							$restrict_post[$matches[3]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
				
					// WHEN *ACTION* REDIRECT TO "QUOTED-STRING"
					$redirect_to_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+redirect\s+to\s+"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$redirect_to_by_action) {
							$this->interpreter->inject_global_variables($matches[2]);
							$redirect_to_by_action[$matches[1]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// WHEN *ACTION* CALL *JS-FUNCTION*
					$button_action_call_list_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+call\s+(.*?)\s*;\s*/is',
						function($matches) use (&$button_action_call_list_by_action) {
							$this->interpreter->inject_global_variables($matches[2]);
							$button_action_call_list_by_action[$matches[1]] = $matches[2];
							return '';
						},
						$unprocessed_start_form_block
					);
					// WHEN *ACTION* SEND EMAIL
					$send_email_list_by_action = array();
					$unprocessed_start_form_block = preg_replace_callback(
						'/\s*when\s+(\w+)\s+send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) use (&$send_email_list_by_action) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*when\s+[^\s]*\s+send\s+email\s*;/is', '', $unprocessed_send_email_block);
							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);
							// build an array containing the email message and headers
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							$send_email_list_by_action[$matches[1]][] = $mail_options;
							return '';
						},
						$unprocessed_start_form_block
					);
					// continue processing the remaining START Block as long as FORM ACTIONS are parsed out from the beginning of the unprocessed START Block
					$set_to_list_by_action = array();
					$calculate_list_by_action = array();
					$create_sql_record_list_by_action = array();
					$update_sql_record_list_by_action = array();
					$delete_sql_record_list_by_action = array();
					$create_spreadsheet_row_list_by_action = array();
					$update_spreadsheet_row_list_by_action = array();
					$delete_spreadsheet_row_list_by_action = array();
					$conditional_actions = array();
					$additional_form_attributes = '';
					do {
						$form_directive_found = false;
						// WHEN *ACTION* [AND *CONDITIONAL*] SET *EASE-VARIABLE* TO "QUOTED-STRING"
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)set\s+([^;]+?)\s+to\s*"(.*?)"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[5]);
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$set_to_list_by_action[$form_directive_matches[1]][$form_directive_matches[5]] = $form_directive_matches[6];
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'set_to_string',
									'variable'=>$form_directive_matches[5],
									'value'=>$form_directive_matches[6]
								);
							}
							$unprocessed_start_form_block = $form_directive_matches[7];
							continue;
						}
						// WHEN *ACTION* [AND *CONDITIONAL*] SET *EASE-VARIABLE* TO *MATH-EXPRESSION*
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)set\s+([^;]+?)\s+to\s*(.*?)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[5]);
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$calculate_list_by_action[$form_directive_matches[1]][$form_directive_matches[5]] = $form_directive_matches[6];
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'set_to_expression',
									'variable'=>$form_directive_matches[5],
									'value'=>$form_directive_matches[6]
								);
							}
							$unprocessed_start_form_block = $form_directive_matches[7];
							continue;
						}
						// WHEN *ACTION* [AND *CONDITIONAL*] CREATE NEW RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+((and|&&|&)\s*(\(\s*.*?\s*\))\s*|)create\s+(new\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$create_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[6]);
							$create_sql_record['for'] = $form_directive_matches[6];
							$this->interpreter->inject_global_variables($form_directive_matches[10]);
							$create_sql_record['as'] = strtolower($form_directive_matches[10]);
							$unprocessed_create_sql_record_block = $form_directive_matches[11];
							do {
								$create_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+(.*?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[2]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($create_sql_record_directive_matches[1]);
									}
									$create_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+(.*?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($create_sql_record_directive_matches[1]));
									}
									$create_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
									continue;
								}
							} while($create_sql_record_directive_found);
							if(isset($form_directive_matches[2]) && trim($form_directive_matches[2])=='') {
								$create_sql_record_list_by_action[$form_directive_matches[1]][] = $create_sql_record;
							} else {
								$conditional_actions[$form_directive_matches[1]][] = array(
									'condition'=>$form_directive_matches[4],
									'type'=>'create_sql_record',
									'record'=>$create_sql_record
								);
							}
							$unprocessed_start_form_block = $unprocessed_create_sql_record_block;
							continue;
						}
						// WHEN *ACTION* UPDATE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+update\s+(old\s+|existing\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$update_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[3]);
							$update_sql_record['for'] = $form_directive_matches[3];
							$this->interpreter->inject_global_variables($form_directive_matches[7]);
							$update_sql_record['as'] = strtolower($form_directive_matches[7]);
							$unprocessed_update_sql_record_block = $form_directive_matches[8];
							do {
								$update_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+(.*?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[2]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($update_sql_record_directive_matches[1]);
									}
									$update_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+(.*?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($update_sql_record_directive_matches[1]));
									}
									$update_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
									continue;
								}
							} while($update_sql_record_directive_found);
							$update_sql_record_list_by_action[$form_directive_matches[1]][] = $update_sql_record;
							$unprocessed_start_form_block = $unprocessed_update_sql_record_block;
							continue;
						}
						// WHEN *ACTION* DELETE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+(\w+)\s+delete\s+record\s+for\s*"\s*(.*?)\s*"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$delete_sql_record = array();
							$this->interpreter->inject_global_variables($form_directive_matches[2]);
							$delete_sql_record['for'] = strtolower($form_directive_matches[2]);
							$unprocessed_delete_sql_record_block = $form_directive_matches[3];
							do {
								$delete_sql_record_directive_found = false;
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_delete_sql_record_block, $delete_sql_record_directive_matches)) {
									$delete_sql_record_directive_found = true;
									$unprocessed_delete_sql_record_block = substr($unprocessed_delete_sql_record_block, strlen($delete_sql_record_directive_matches[0]));
									continue;
								}
							} while($delete_sql_record_directive_found);
							$delete_sql_record_list_by_action[$form_directive_matches[1]][] = $delete_sql_record;
							$unprocessed_start_form_block = $unprocessed_delete_sql_record_block;
							continue;
						}
						// SET FORM ATTRIBUTE
						if(preg_match('/^\s*set\s+form\s*\.\s*([^;]+?)\s*to\s*"(.*?)"\s*;(.*)$/is', $unprocessed_start_form_block, $form_directive_matches)) {
							$form_directive_found = true;
							$this->interpreter->inject_global_variables($form_directive_matches[1]);
							$this->interpreter->inject_global_variables($form_directive_matches[2]);
							$additional_form_attributes .= " {$form_directive_matches[1]}=\"{$form_directive_matches[2]}\"";
							$unprocessed_start_form_block = $form_directive_matches[3];
							continue;
						}
					
						// !! ANY NEW FORM DIRECTIVES FOR SQL TABLE GET ADDED HERE

					} while($form_directive_found);

					// if the START Block has any content remaining, there was an unrecognized FORM directive; log an error
					$this->interpreter->remove_comments($unprocessed_start_form_block);
					$this->interpreter->inject_global_variables($unprocessed_start_form_block);
					if(trim($unprocessed_start_form_block)!='') {
						// ERROR! the START Block contained an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}
					// initialize the form for the current user's session
					if(isset($_REQUEST['ease_form_id']) && isset($_SESSION['ease_forms'][$_REQUEST['ease_form_id']])) {
						// the ease_form_id value provided in the request matches a form in the current user's session
						// this is likely a form post that failed input validation, and was redirected back to the form
						$form_id = $_REQUEST['ease_form_id'];
					} else {
						// generate a new ID for the form, and store information about the form in the current user's session
						$form_id = $this->core->new_uuid();
						$_SESSION['ease_forms'][$form_id]['created_on'] = time();
						@$_SESSION['ease_forms'][$form_id]['sql_table_name'] = $sql_table_name;
						@$_SESSION['ease_forms'][$form_id]['namespaced_sql_table_name'] = $namespaced_sql_table_name;
						@$_SESSION['ease_forms'][$form_id]['instance_uuid'] = $instance_uuid;
						@$_SESSION['ease_forms'][$form_id]['restrict_post'] = $restrict_post;
						@$_SESSION['ease_forms'][$form_id]['set_to_list_by_action'] = $set_to_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['calculate_list_by_action'] = $calculate_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['send_email_list_by_action'] = $send_email_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['create_sql_record_list_by_action'] = $create_sql_record_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['update_sql_record_list_by_action'] = $update_sql_record_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['delete_sql_record_list_by_action'] = $delete_sql_record_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['create_spreadsheet_row_list_by_action'] = $create_spreadsheet_row_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['update_spreadsheet_row_list_by_action'] = $update_spreadsheet_row_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['delete_spreadsheet_row_list_by_action'] = $delete_spreadsheet_row_list_by_action;
						@$_SESSION['ease_forms'][$form_id]['redirect_to_by_action'] = $redirect_to_by_action;
						@$_SESSION['ease_forms'][$form_id]['conditional_actions'] = $conditional_actions;
					}
					// remove the START Block from the unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($start_form_block));
					// find the END FORM tag to determine the form body
					if(preg_match('/(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*form\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
						// END FORM tag found;  process the form body
						$form_body = $matches[1];

						// if this is an edit form, validate the referenced record ID
						$existing_row = array();
						if($instance_uuid && $this->core->db && !$this->core->db_disabled) {
							// query for current instance values
							$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
							$params = array(':uuid'=>$instance_uuid);
							if($query->execute($params) && $query->rowCount()==1) {
								$existing_row = $query->fetch(PDO::FETCH_ASSOC);
							} else {
								// inject an Invalid SQL Table Instance UUID error into the output buffer
								$this->output_buffer .= "<span style='color:red; font-weight:bold;'>EASE FORM ERROR!</span> - Invalid SQL Record ID:&nbsp;&nbsp;<b>" . htmlspecialchars("$sql_table_name.$instance_uuid") . "</b>";
								// remove the form body and the END FORM tag from the remaining unprocessed body, and abort processing this form
								$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
								return;
							}
						}
					
						// process the form body for any EASE
						$tokenized_form_body = $this->string_to_tokenized_ease($form_body);
						$params = array('save_to_global_variable'=>'system.processed_form_body', 'no_local_injection'=>true);
						$this->process_tokenized_ease($tokenized_form_body, $params);
						if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
							// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
							return;
						}
						$form_body = $this->core->globals['system.processed_form_body'];
					
						// process INPUT tags in the FORM body with an EASE tag attribute
						$form_body = preg_replace_callback(
							'/<\s*input\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)(\/\s*|)>/is',
							function($matches) use (&$form_id, $existing_row, $button_action_call_list_by_action) {
								// an EASE tag was found as an HTML INPUT tag attribute
								$input_ease_reference = $matches[9];
								// process all of the HTML INPUT tag attributes (other than the EASE tag)
								$input_attributes = "{$matches[1]} {$matches[10]}";
								$input_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $input_attributes, $input_attribute_matches);
								foreach($input_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// input attribute assigned a value with =
										$input_attribute_key = strtolower($value);
										if($input_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>"'",
												'value'=>$input_attribute_matches[4][$key]
											);
										} elseif($input_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>'"',
												'value'=>$input_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$input_attributes_by_key[$input_attribute_key] = array(
												'quote'=>'',
												'value'=>$input_attribute_matches[9][$key]
											);
										}
									} else {
										// input attribute with no assigned value
										$input_attribute_key = strtolower($input_attribute_matches[10][$key]);
										$input_attributes_by_key[$input_attribute_key] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								$input_attributes_by_key['type']['value'] = strtolower($input_attributes_by_key['type']['value']);
								// process the INPUT tag by type
								switch($input_attributes_by_key['type']['value']) {
									case 'checkbox':
										$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
										if(count($input_ease_reference_parts)==2) {
											$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($input_ease_reference_parts[0])));
											$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($input_ease_reference_parts[1])));
										} else {
											$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
											$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($input_ease_reference));
										}
										if($key=='id') {
											$key = 'uuid';
										}
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"input_{$bucket}_{$key}"
										);
										if(!isset($input_attributes_by_key['value'])) {
											$input_attributes_by_key['value'] = array(
												'quote'=>'"',
												'value'=>'Yes'
											);
										}
										if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
											if($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										} elseif($_SESSION['ease_forms'][$form_id]['instance_uuid']) {
											if(isset($existing_row[$key]) && $existing_row[$key]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										}
										if(!isset($input_attributes_by_key['style']) && !isset($input_attributes_by_key['class'])) {
											$input_attributes_by_key['style'] = array(
												'quote'=>'"',
												'value'=>'width:13px; height:13px; padding:0; margin:0; vertical-align:bottom; position:relative; top:-1px; *overflow:hidden;'
											);
										}
										$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = "{$bucket}.{$key}";
										break;
									case 'radio':
										$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
										if(count($input_ease_reference_parts)==2) {
											$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($input_ease_reference_parts[0])));
											$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($input_ease_reference_parts[1])));
										} else {
											$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
											$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($input_ease_reference));
										}
										if($key=='id') {
											$key = 'uuid';
										}
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"input_{$bucket}_{$key}"
										);
										if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
											if($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']]!='') {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										} elseif($_SESSION['ease_forms'][$form_id]['instance_uuid']) {
											if($existing_row[$key]==$input_attributes_by_key['value']['value']) {
												$input_attributes_by_key['checked'] = array(
													'quote'=>'"',
													'value'=>'checked'
												);
											} else {
												unset($input_attributes_by_key['checked']);
											}
										}
										if(!isset($input_attributes_by_key['style']) && !isset($input_attributes_by_key['class'])) {
											$input_attributes_by_key['style'] = array(
												'quote'=>'"',
												'value'=>'width:13px; height:13px; padding:0; margin:0; position:relative; top:-1px; *overflow:hidden;'
											);
										}
										$_SESSION['ease_forms'][$form_id]['inputs']["input_{$bucket}_{$key}"] = "{$bucket}.{$key}";
										break;
									case 'file':
										if(preg_match('/^\s*upload\s+file\s+to\s+google\s*drive\s*"\s*(.*?)\s*"\s*for\s+(.*?)\s*$/is', $input_ease_reference, $googledrive_file_matches)) {
											$input_ease_reference_parts = explode('.', $googledrive_file_matches[2], 2);
											if(count($input_ease_reference_parts)==2) {
												$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($input_ease_reference_parts[0])));
												$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($input_ease_reference_parts[1])));
											} else {
												$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
												$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($googledrive_file_matches[2]));
											}
											$input_attributes_by_key['name'] = array(
												'quote'=>'"',
												'value'=>"file_{$bucket}_{$key}"
											);
											$_SESSION['ease_forms'][$form_id]['files']["file_{$bucket}_{$key}"] = array(
												'map'=>"{$bucket}.{$key}",
												'folder'=>$googledrive_file_matches[1]
											);
										} elseif(preg_match('/^\s*(private|)\s*(.+?)\s*$/is', $input_ease_reference, $inner_matches)) {
											$input_ease_reference_parts = explode('.', $inner_matches[2], 2);
											if(count($input_ease_reference_parts)==2) {
												$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($input_ease_reference_parts[0])));
												$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($input_ease_reference_parts[1])));
											} else {
												$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
												$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($inner_matches[2]));
											}
											$input_attributes_by_key['name'] = array(
												'quote'=>'"',
												'value'=>"file_{$bucket}_{$key}"
											);
											$_SESSION['ease_forms'][$form_id]['files']["file_{$bucket}_{$key}"] = array(
												'map'=>"{$bucket}.{$key}",
												'private'=>(strtolower($inner_matches[1])=='private')
											);
										}
										break;
									case 'submit':
									case 'button':
										// process the button name to determine the type
										preg_match('/(.*?)\s*button/is', $input_ease_reference, $button_matches);
										$button_reference = preg_replace('/[^a-z0-9\._-]+/is', '', strtolower($button_matches[1]));
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"button_{$button_reference}"
										);
										if($button_reference=='create' || $button_reference=='creating') {
											// this was a BUTTON INPUT tag with a CREATE action;  check if this is an EDIT form
											if($_SESSION['ease_forms'][$form_id]['instance_uuid']!='') {
												// this is an EDIT form; remove this button from the form
												return '';
											}
											// this is a CREATE form; change the input type to submit and set the button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['creating'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['creating'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'creating',
												'handler'=>'add_instance_to_sql_table'
											);
										} elseif($button_reference=='update' || $button_reference=='updating') {
											// this was a BUTTON INPUT tag with a UPDATE action;  check if this is an EDIT form
											if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
												// this is not an EDIT form; remove this button from the form
												return '';
											}
											// this is an EDIT form; change the button type to the default submit action and set the button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['updating'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['updating'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'updating',
												'handler'=>'update_instance_in_sql_table'
											);
										} elseif($button_reference=='delete' || $button_reference=='deleting') {
											// this was a BUTTON INPUT tag with a DELETE action;  check if this is an EDIT form
											if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
												// this is not an EDIT form; remove this button from the form
												return '';
											}
											// this is an EDIT form; change the input type to require clicking the DELETE button, and set button handler
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action['deleting'])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['deleting'])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
												'action'=>'deleting',
												'handler'=>'delete_instance_from_sql_table'
											);
										} else {
											// this is a custom form action
											$input_attributes_by_key['type'] = array(
												'quote'=>'"',
												'value'=>'submit'
											);
											if(isset($button_action_call_list_by_action[$button_reference])) {
												$input_attributes_by_key['onclick'] = array(
													'quote'=>'"',
													'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action[$button_reference])
												);
											}
											// if a value wasn't set for the button, default it to the custom action name
											if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
												$input_attributes_by_key['value'] = array(
													'quote'=>'"',
													'value'=>$button_matches[1]
												);
											}
											$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['action'] = $button_reference;
											if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
												$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'add_instance_to_sql_table';
											} else {
												$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'update_instance_in_sql_table';
											}
										}
										break;
									default:
									// default input for text, number, decimal, date, email, etc...
									$input_ease_reference_parts = explode('.', $input_ease_reference, 2);
									if(count($input_ease_reference_parts)==2) {
										$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($input_ease_reference_parts[0])));
										$key = preg_replace('/[^a-z0-9]+/', '_', strtolower(ltrim($input_ease_reference_parts[1])));
									} else {
										$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
										$key = preg_replace('/[^a-z0-9]+/', '_', strtolower($input_ease_reference));
									}
									if($key=='id') {
										$key = 'uuid';
									}
									$input_attributes_by_key['name'] = array(
										'quote'=>'"',
										'value'=>"input_{$bucket}_{$key}"
									);
									if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])) {
										// this is an input validation rejection form, set the input values as previously posted
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($_SESSION['ease_forms'][$form_id]['post_values'][$input_attributes_by_key['name']['value']])
										);
									} elseif($_SESSION['ease_forms'][$form_id]['instance_uuid']) {
										// this is an edit form, set the input values to the existing values
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($existing_row[$key])
										);
									}
									if($input_attributes_by_key['type']['value']=='date' && isset($input_attributes_by_key['value']['value']) && trim($input_attributes_by_key['value']['value'])!='') {
										$datetime = strtotime($input_attributes_by_key['value']['value']);
										$input_attributes_by_key['value']['value'] = date('Y-m-d', $datetime);
									}
									if($input_attributes_by_key['type']['value']=='datetime' && isset($input_attributes_by_key['value']['value']) && trim($input_attributes_by_key['value']['value'])!='') {
										$datetime = strtotime($input_attributes_by_key['value']['value']);
										$input_attributes_by_key['value']['value'] = date('Y-m-d H:i:s', $datetime);
									}
									if($input_attributes_by_key['type']['value']=='decimal' || $input_attributes_by_key['type']['value']=='number') {
										$input_attributes_by_key['type']['value'] = 'number';
										if(!isset($input_attributes_by_key['step'])) {
											$input_attributes_by_key['step'] = array('quote'=>'"', 'value'=>'any');
										}
									}
									if($input_attributes_by_key['type']['value']=='integer') {
										$input_attributes_by_key['type']['value'] = 'number';
										if(!isset($input_attributes_by_key['step'])) {
											$input_attributes_by_key['step'] = array('quote'=>'"', 'value'=>'1');
										} else {
											$input_attributes_by_key['step']['value'] = intval($input_attributes_by_key['step']['value']);
										}
									}
									if(isset($input_attributes_by_key['min'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['min'] = $input_attributes_by_key['min']['value'];
									}
									if(isset($input_attributes_by_key['max'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['max'] = $input_attributes_by_key['max']['value'];
									}
									if(isset($input_attributes_by_key['step'])) {
										$_SESSION['ease_forms'][$form_id]['input_validation_attributes'][$input_attributes_by_key['name']['value']]['step'] = $input_attributes_by_key['step']['value'];
									}
									if(isset($input_attributes_by_key['pattern'])) {
										$_SESSION['ease_forms'][$form_id]['input_patterns'][$input_attributes_by_key['name']['value']] = stripslashes($input_attributes_by_key['pattern']['value']);
									}
									if(isset($input_attributes_by_key['required'])) {
										$_SESSION['ease_forms'][$form_id]['input_requirements'][$input_attributes_by_key['name']['value']] = true;
									}
									// set validations for supported input types
									switch($input_attributes_by_key['type']['value']) {
										case 'email':
										case 'number':
										case 'integer':
										case 'decimal':
										case 'price':
										case 'dollars':
										case 'usd':
										case 'cost':
										case 'url':
										case 'date':
										case 'datetime':
											$_SESSION['ease_forms'][$form_id]['input_validations'][$input_attributes_by_key['name']['value']] = $input_attributes_by_key['type']['value'];
											break;
										case 'decimalrange':
										case 'decimal-range':
											$_SESSION['ease_forms'][$form_id]['input_ranges'][$input_attributes_by_key['name']['value']] = array('type'=>'decimal', 'min'=>$input_attributes_by_key['min']['value'], 'max'=>$input_attributes_by_key['max']['value']);
											break;
										case 'range':
											$_SESSION['ease_forms'][$form_id]['input_ranges'][$input_attributes_by_key['name']['value']] = array('type'=>'integer', 'min'=>$input_attributes_by_key['min']['value'], 'max'=>$input_attributes_by_key['max']['value']);
											break;
										default:
									}
									$_SESSION['ease_forms'][$form_id]['inputs'][$input_attributes_by_key['name']['value']] = "{$bucket}.{$key}";
								}
								// inject the processed HTML INPUT tag back into the form body
								$input_attributes_string = '';
								foreach($input_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$input_attributes_string .= "$key ";
									} else {
										$input_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$input_string = "<input $input_attributes_string/>";
								if(isset($_SESSION['ease_forms'][$form_id]['invalid_inputs'][$input_attributes_by_key['name']['value']])) {
									$input_string .= '<br /><span style="color:red; font-weight:bold;">' . htmlspecialchars($_SESSION['ease_forms'][$form_id]['invalid_inputs'][$input_attributes_by_key['name']['value']]) . '</span>';
								}
								return $input_string;
							},
							$form_body
						);
						// process SELECT blocks in the FORM body with EASE tags
						$form_body = preg_replace_callback(
							'/<\s*select\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>\s*(.*?)\s*<\s*\/\s*select\s*>/is',
							function($matches) use (&$form_id, $existing_row) {
								// an EASE tag was found in a SELECT block
								$select_ease_reference = $matches[9];
								// process all of the HTML SELECT tag attributes (other than the EASE tag)
								$select_attributes = "{$matches[1]} {$matches[10]}";
								$select_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $select_attributes, $select_attribute_matches);
								foreach($select_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// select attribute assigned a value with =
										if($select_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$select_attribute_matches[4][$key]
											);
										} elseif($select_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$select_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$select_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$select_attribute_matches[9][$key]
											);
										}
									} else {
										// select attribute with no assigned value
										$select_attributes_by_key[$select_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								$select_ease_reference_split = explode('.', $select_ease_reference, 2);
								if(count($select_ease_reference_split)==2) {
									$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($select_ease_reference_split[0])));
									$column = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($select_ease_reference_split[1])));
								} else {
									$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
									$column = preg_replace('/[^a-z0-9]+/s', '_', strtolower($select_ease_reference));
								}
								if($column=='id') {
									$column = 'uuid';
								}
								$select_attributes_by_key['name'] = array(
									'quote'=>'"',
									'value'=>"input_{$bucket}_{$column}"
								);
								$_SESSION['ease_forms'][$form_id]['inputs'][$select_attributes_by_key['name']['value']] = "{$bucket}.{$column}";
								// process each OPTION in the SELECT block
								$select_options_string = '';
								$remaining_select_body = $matches[18];
								while(preg_match('/\s*<\s*option\s*((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)>(.*?)<\s*\/\s*option\s*>\s*/is', $remaining_select_body, $inner_matches)) {
									// process all of the OPTION attributes
									$option_attributes_by_key = array();
									preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $inner_matches[1], $option_attribute_matches);
									foreach($option_attribute_matches[1] as $key=>$value) {
										if(trim($value)!='') {
											// option attribute assigned a value with =
											if($option_attribute_matches[3][$key]=="'") {
												// the value was wrapped in single quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>"'",
													'value'=>$option_attribute_matches[4][$key]
												);
											} elseif($option_attribute_matches[6][$key]=='"') {
												// the value was wrapped in double quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>'"',
													'value'=>$option_attribute_matches[7][$key]
												);
											} else {
												// the value was not wrapped in quotes
												$option_attributes_by_key[$value] = array(
													'quote'=>'',
													'value'=>$option_attribute_matches[9][$key]
												);
											}
										} else {
											// option attribute with no assigned value
											$option_attributes_by_key[$option_attribute_matches[10][$key]] = array(
												'quote'=>'',
												'value'=>''
											);
										}
									}
									// if a value attribute wasn't set, default it to the OPTION body
									if(!isset($option_attributes_by_key['value'])) {
										$option_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>htmlspecialchars($inner_matches[9])
										);
									}
									// if this is an edit form, default the selected OPTION to the existing value
									if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$select_attributes_by_key['name']['value']])) {
										if($_SESSION['ease_forms'][$form_id]['post_values'][$select_attributes_by_key['name']['value']]==$option_attributes_by_key['value']['value']) {
											$option_attributes_by_key['selected'] = array(
												'quote'=>'"',
												'value'=>'selected'
											);
										} else {
											unset($option_attributes_by_key['selected']);
										}
									} elseif($_SESSION['ease_forms'][$form_id]['instance_uuid']) {
										if($existing_row[$column]==$option_attributes_by_key['value']['value']) {
											$option_attributes_by_key['selected'] = array(
												'quote'=>'"',
												'value'=>'selected'
											);
										} else {
											unset($option_attributes_by_key['selected']);
										}
									}
									// replace the original OPTION block with the processed attributes
									$options_attributes_string = '';
									foreach($option_attributes_by_key as $key=>$value) {
										if($value['quote']=='' && $value['value']=='') {
											$options_attributes_string .= "$key ";
										} else {
											$options_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
										}
									}
									$select_options_string .= "<option $options_attributes_string>$inner_matches[9]</option>\n";
									$remaining_select_body = substr($remaining_select_body, strlen($inner_matches[0]));
								}
								// replace the original SELECT block with the processed attributes
								$select_attributes_string = '';
								foreach($select_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$select_attributes_string .= "$key ";
									} else {
										$select_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								return "<select $select_attributes_string>$select_options_string</select>";
							},
							$form_body
						);
						// process TEXTAREA tags in the FORM body
						$form_body = preg_replace_callback(
							'/<\s*textarea\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>(.*?)<\s*\/\s*textarea\s*>/is',
							function($matches) use (&$form_id, $existing_row) {
								// an EASE variable was referenced in a TEXTAREA
								$textarea_ease_reference = $matches[9];
								// process all of the HTML TEXTAREA tag attributes (other than the EASE tag)
								$textarea_attributes = "{$matches[1]} {$matches[10]}";
								$textarea_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $textarea_attributes, $textarea_attribute_matches);
								foreach($textarea_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// textarea attribute assigned a value with =
										if($textarea_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$textarea_attribute_matches[4][$key]
											);
										} elseif($textarea_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$textarea_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$textarea_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$textarea_attribute_matches[9][$key]
											);
										}
									} else {
										// textarea attribute with no assigned value
										$textarea_attributes_by_key[$textarea_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								// parse the referenced EASE variable to determine the data bucket and key
								$textarea_ease_reference_split = explode('.', $textarea_ease_reference, 2);
								if(count($textarea_ease_reference_split)==2) {
									$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($textarea_ease_reference_split[0])));
									$column = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($textarea_ease_reference_split[1])));
								} else {
									$bucket = $_SESSION['ease_forms'][$form_id]['sql_table_name'];
									$column = preg_replace('/[^a-z0-9]+/s', '_', strtolower($textarea_ease_reference));
								}
								if($column=='id') {
									$column = 'uuid';
								}
								$textarea_attributes_by_key['name'] = array(
									'quote'=>'"',
									'value'=>"input_{$bucket}_{$column}"
								);
								// set the TEXTAREA as an expected input of the form
								$_SESSION['ease_forms'][$form_id]['inputs'][$textarea_attributes_by_key['name']['value']] = "{$bucket}.{$column}";
								// build the new TEXTAREA HTML tag with attributes
								$textarea_attributes_string = '';
								foreach($textarea_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$textarea_attributes_string .= "$key ";
									} else {
										$textarea_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$return = "<textarea $textarea_attributes_string>";
								if(isset($_SESSION['ease_forms'][$form_id]['post_values'][$textarea_attributes_by_key['name']['value']])) {
									// this is an edit form, populate the textarea with the current value for the instance
									$return .= htmlspecialchars($_SESSION['ease_forms'][$form_id]['post_values'][$textarea_attributes_by_key['name']['value']]);
								} elseif($_SESSION['ease_forms'][$form_id]['instance_uuid']) {
									// this is an edit form, populate the textarea with the current value for the instance
									$return .= htmlspecialchars($existing_row[$column]);
								} else {
									// this is a create form, leave any preset textarea content in place
									$return .= $matches[18];
								}
								$return .= "</textarea>";
								return $return;
							},
							$form_body
						);
						// process BUTTON tags in the FORM body
						$form_body = preg_replace_callback(
							'/<\s*button\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)>(.*?)<\s*\/\s*button\s*>/is',
							function($matches) use (&$form_id, $existing_row) {
								// an EASE variable was referenced in a BUTTON
								$button_ease_reference = $matches[9];
								// process all of the HTML BUTTON tag attributes (other than the EASE tag)
								$button_attributes = "{$matches[1]} {$matches[10]}";
								$button_attributes_by_key = array();
								preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $button_attributes, $button_attribute_matches);
								foreach($button_attribute_matches[1] as $key=>$value) {
									if(trim($value)!='') {
										// button attribute assigned a value with =
										if($button_attribute_matches[3][$key]=="'") {
											// the value was wrapped in single quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>"'",
												'value'=>$button_attribute_matches[4][$key]
											);
										} elseif($button_attribute_matches[6][$key]=='"') {
											// the value was wrapped in double quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>'"',
												'value'=>$button_attribute_matches[7][$key]
											);
										} else {
											// the value was not wrapped in quotes
											$button_attributes_by_key[$value] = array(
												'quote'=>'',
												'value'=>$button_attribute_matches[9][$key]
											);
										}
									} else {
										// button attribute with no assigned value
										$button_attributes_by_key[$button_attribute_matches[10][$key]] = array(
											'quote'=>'',
											'value'=>''
										);
									}
								}
								// process the button name to determine the type
								preg_match('/(.*?)\s*button/is', $button_ease_reference, $button_matches);
								$button_reference = preg_replace('/[^a-z0-9\._-]+/is', '', strtolower($button_matches[1]));
								$button_attributes_by_key['name'] = array(
									'quote'=>'"',
									'value'=>"button_{$button_reference}"
								);
								if($button_reference=='create' || $button_reference=='creating') {
									// this was a BUTTON tag with a CREATE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']!='') {
										// this is an EDIT form; remove this button from the form
										return '';
									}
									// this is a CREATE form; change the input type to submit and set the button handler
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['creating'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['creating'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'creating',
										'handler'=>'add_instance_to_sql_table'
									);
								} elseif($button_reference=='update' || $button_reference=='updating') {
									// this was a BUTTON INPUT tag with a UPDATE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										// this is not an EDIT form; remove this button from the form
										return '';
									}
									// this is an EDIT form; change the button type to the default submit action and set the button handler
									$input_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['updating'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['updating'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'updating',
										'handler'=>'update_instance_in_sql_table'
									);
								} elseif($button_reference=='delete' || $button_reference=='deleting') {
									// this was a BUTTON INPUT tag with a DELETE action;  check if this is an EDIT form
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										// this is not an EDIT form; remove this button from the form
										return '';
									}
									// this is an EDIT form; change the input type to require clicking the DELETE button, and set button handler
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action['deleting'])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action['deleting'])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"] = array(
										'action'=>'deleting',
										'handler'=>'delete_instance_from_sql_table'
									);
								} else {
									// this is a custom form action
									$button_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action[$button_reference])) {
										$button_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action[$button_reference])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($button_attributes_by_key['value']['value'])) || trim($button_attributes_by_key['value']['value'])=='') {
										$button_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['action'] = $button_reference;
									if($_SESSION['ease_forms'][$form_id]['instance_uuid']=='') {
										$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'add_instance_to_sql_table';
									} else {
										$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'update_instance_in_sql_table';
									}
								}
								// build the new BUTTON HTML tag with attributes
								$button_attributes_string = '';
								foreach($button_attributes_by_key as $key=>$value) {
									if($value['quote']=='' && $value['value']=='') {
										$button_attributes_string .= "$key ";
									} else {
										$button_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
									}
								}
								$return = "<button $button_attributes_string>";
								if(trim($matches[18])=='') {
									$return .= $button_attributes_by_key['value']['value'];
								} else {
									$return .= $matches[18];
								}
								$return .= "</button>";
								return $return;
							},
							$form_body
						);
						// inject the HTML form into the output buffer
						$form_attributes = "enctype='multipart/form-data' method='post' accept-charset='utf-8'";
						// determine if client side form validation is required
						if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) || isset($_SESSION['ease_forms'][$form_id]['input_requirements']) || isset($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
							$form_attributes .= " onsubmit='return validate_$form_id(this)'";
						}
						$form_attributes .= $additional_form_attributes;
						// determine if routing file uploads through Google Cloud Storage is required
						if(!isset($this->core->config['gs_bucket_name']) || trim($this->core->config['gs_bucket_name'])=='') {
							$this->core->load_system_config_var('gs_bucket_name');
						}
						if(isset($_SERVER['SERVER_SOFTWARE'])
						  && strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine')!==false
						  && isset($this->core->config['gs_bucket_name'])
						  && isset($_SESSION['ease_forms'][$form_id]['files'])
						  && is_array($_SESSION['ease_forms'][$form_id]['files'])
						  && count($_SESSION['ease_forms'][$form_id]['files']) > 0) {
							// AppEngine environment with file upload fields in the form, route the form post through the Google Cloud Storage form handler
							require_once 'google/appengine/api/cloud_storage/CloudStorageTools.php';
							$options = array('gs_bucket_name'=>$this->core->config['gs_bucket_name']);
							$upload_url = google\appengine\api\cloud_storage\CloudStorageTools::createUploadUrl($this->core->service_endpoints['form'], $options);
							$this->output_buffer .= "<form action='$upload_url' $form_attributes>\n";
						} else {
							// no file upload fields in the form, or an external file upload handler is not required
							// post directly to the EASE Framework form handler
							$this->output_buffer .= "<form action='{$this->core->service_endpoints['form']}' $form_attributes>\n";
						}
						$this->output_buffer .= "<input type='hidden' id='ease_form_id' name='ease_form_id' value='$form_id' />\n";
						$this->output_buffer .= trim($form_body) . "\n";
						$this->output_buffer .= "</form>\n";
						// if client side form validation was required, inject the validation javascript
						if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) || isset($_SESSION['ease_forms'][$form_id]['input_requirements']) || isset($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
							$form_validation_javascript = '';
							if(isset($_SESSION['ease_forms'][$form_id]['input_validations']) && is_array($_SESSION['ease_forms'][$form_id]['input_validations'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_validations'] as $input_name=>$input_type) {
									switch($input_type) {
										case 'email':
		 									$form_validation_javascript .= "	re = /^\\S+@\\S+$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid email address.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'integer':
											$form_validation_javascript .= "	re = /^[0-9-]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid integer.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'number':
										case 'decimal':
											$form_validation_javascript .= "	re = /^[0-9\\.-]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].select();\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid number.\\n\\nInvalid: ' + window.getSelection().toString());\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'usd':
										case 'price':
										case 'cost':
										case 'dollars':
											$form_validation_javascript .= "	re = /^[0-9\$,\\. -]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid dollar value.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										case 'date':
											$form_validation_javascript .= "	re = /^[0-9\\/\\. -]*$/;\n";
											$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
			 								$form_validation_javascript .= "		alert('Please enter a valid date.\\n\\nInvalid: ' + ease_form.elements['$input_name'].value);\n";
			 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
											$form_validation_javascript .= "		return false;\n";
			 								$form_validation_javascript .= "	}\n";
											break;
										default:
									}
								}
							}
							if(isset($_SESSION['ease_forms'][$form_id]['input_patterns']) && is_array($_SESSION['ease_forms'][$form_id]['input_patterns'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_patterns'] as $input_name=>$regex_pattern) {
									$form_validation_javascript .= "	re = /^$regex_pattern$/;\n";
									$form_validation_javascript .= "	if(!re.test(ease_form.elements['$input_name'].value)) {\n";
									$form_validation_javascript .= "		alert(\"Please enter a value matching this pattern:\\n" . htmlspecialchars($regex_pattern) . "\\n\\nInvalid: \" + ease_form.elements['$input_name'].value);\n";
									$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
									$form_validation_javascript .= "		return false;\n";
									$form_validation_javascript .= "	}\n";
									break;
								}
							}
							if(isset($_SESSION['ease_forms'][$form_id]['input_requirements']) && is_array($_SESSION['ease_forms'][$form_id]['input_requirements'])) {
								foreach($_SESSION['ease_forms'][$form_id]['input_requirements'] as $input_name=>$required) {
									if($required) {
										$form_validation_javascript .= "	if(ease_form.elements['$input_name'].value=='') {\n";
		 								$form_validation_javascript .= "		alert('A value is required.');\n";
		 								$form_validation_javascript .= "		ease_form.elements['$input_name'].focus();\n";
										$form_validation_javascript .= "		return false;\n";
		 								$form_validation_javascript .= "	}\n";
									}
								}
							}
							$this->output_buffer .= "\n<script type='text/javascript'>\nfunction validate_$form_id(ease_form) {\n\tvar re;\n$form_validation_javascript}\n</script>";
						}
						// remove the parsed form body and the END FORM block from the remaining unprocessed body
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
					}
					// done processing FORM - FOR SQL TABLE, return to process the remaining body
					return;
				}
				// the FOR attribute of a START Block was not found
			}

			###############################################
			##	START LIST
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+list\s+(.*?(;|})\h*(\/\/\V*|)(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$start_list_block = $matches[0];
				$this->interpreter->remove_comments($matches[1]);
				$unprocessed_start_list_block = $matches[1];

				###############################################
				##	START LIST - FOR GOOGLE SHEET
				if(preg_match('/^for\s+(google\s*|g)(spread|)sheet\s+(.*?)\s*;\s*/is', $unprocessed_start_list_block, $matches)) {
					// ensure the Google Drive API access token is fresh
					$this->core->validate_google_access_token();
					// reset the local variables
					$this->local_variables = array();
					// determine if the Google Sheet was referenced by "Name" or ID
					$original_spreadsheet_reference = $matches[3];
					$this->interpreter->inject_global_variables($matches[3]);
					if(substr($matches[3], 0, 1)=='"' && substr($matches[3], -1, 1)=='"') {
						// Google Sheet was referenced by "Name"... remove the "quotes"
						$google_spreadsheet_name = substr($matches[3], 1, strlen($matches[3]) - 2);
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($google_spreadsheet_name);
						if(isset($spreadsheet_meta_data['id'])) {
							$google_spreadsheet_id = $spreadsheet_meta_data['id'];
						}
					} else {
						// Google Sheet was referenced by ID
						$google_spreadsheet_id = $matches[3];
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($google_spreadsheet_id);
					}

					// the FOR attribute of the LIST was successfully parsed, scan for any remaining LIST directives
					$unprocessed_start_list_block = substr($unprocessed_start_list_block, strlen($matches[0]));

					// IF/ELSE - parse out and process any Conditionals from the start list block
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)/is',
						function($matches) {
							// process the matches to build the conditional array and any catchall else condition
							$conditions[$matches[1]] = $matches[2];
							// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
							$matches_index = 3;
							while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE IF condition
								$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
								// advance the index to look for the next ELSE IF condition
								$matches_index += 3;
							}
							// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
							if($matches_index==3) {
								// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
								$matches_index = 6;
							}
							// check for any ELSE condition
							if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE condition
								$else_text = $matches[$matches_index + 1];
							}
							// process each conditional in order to determine if any of the conditional EASE blocks should be processed
							foreach($conditions as $condition=>$conditional_text) {
								$remaining_condition = $condition;
								$php_condition_string = '';
								while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
									if(strtolower($inner_matches[1])=='and') {
										$inner_matches[1] = '&&';
									}
									if(strtolower($inner_matches[1])=='or') {
										$inner_matches[1] = '||';
									}
									if(strtolower($inner_matches[2])=='not') {
										$inner_matches[2] = '!';
									}
									if(strtolower($inner_matches[5])=='=') {
										$inner_matches[5] = '==';
									}
									if(strtolower($inner_matches[5])=='is') {
										$inner_matches[5] = '==';
									}
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$this->interpreter->inject_global_variables($inner_matches[6]);
									$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
									return $conditional_text;
								}
							}
							if(isset($else_text)) {
								return $else_text;
							} else {
								return '';
							}
						},
						$unprocessed_start_list_block
					);

					// HIDE PAGERS / SHOW PAGERS
					$pagers = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*hide\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*hide\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_list_block
					);

					// SHOW *NUMBER* ROWS PER PAGE
					$rows_per_page = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s*([0-9]+)\s*rows\s*per\s*page\s*;\s*/is',
						function($matches) use (&$rows_per_page) {
							$rows_per_page = $matches[1];
							return '';
						},
						$unprocessed_start_list_block
					);

					// INCLUDE *COLUMNS* FROM "WORKSHEET" / WHEN *CONDITION*
					$include_columns = '';
					$list_from_sheet = '';
					$include_conditionals = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*include\s+([^;]*?)(\s+from\s*"\s*(.*?)\s*"|)(\s*(where|when)\s+(.*?)\s*|\s*);\s*/is',
						function($matches) use (&$include_columns, &$list_from_sheet, &$include_conditionals, $spreadsheet_meta_data) {
							$include_columns = $matches[1];
							$list_from_sheet = $matches[3];
							if(trim($list_from_sheet)!='') {
								$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $list_from_sheet);
							}
							$remaining_condition = $matches[6];
							while(preg_match('/^(&&|\|\||and\s+|or\s+|xor\s+){0,1}\s*(!|not\s+){0,1}([(\s]*)(.*?)\s+(==|!=|>|>=|<|<=|<>|===|!==|=|is|is\s*not)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
								if(trim(strtolower($inner_matches[1])=='and')) {
									$inner_matches[1] = '&&';
								}
								if(trim(strtolower($inner_matches[1])=='or')) {
									$inner_matches[1] = '||';
								}
								if(trim(strtolower($inner_matches[2])=='not')) {
									$inner_matches[2] = '!';
								}
								if($inner_matches[5]=='=') {
									$inner_matches[5] = '==';
								}
								if(strtolower($inner_matches[5])=='is') {
									$inner_matches[5] = '==';
								}
								if(preg_replace('/[^a-z]+/', '', strtolower($inner_matches[5]))=='isnot') {
									$inner_matches[5] = '!=';
								}
								$this->interpreter->inject_global_variables($inner_matches[4]);
								$this->interpreter->inject_global_variables($inner_matches[6]);
								$bucket_key_parts = explode('.', $inner_matches[4], 2);
								if(count($bucket_key_parts)==2) {
									$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
									$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($bucket_key_parts[1])));
								} else {
									$bucket = 'row';
									$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($inner_matches[4]));
								}
								if($bucket=='row') {
									if(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
										$key = $spreadsheet_meta_data['column_letter_by_name'][$key];
									} elseif(strlen($key)<=2) {
										// assume the column was referenced by column letter
										$key = strtoupper($key);
									} else {
										// the column name wasn't found and wasn't likely referenced by column letter
										// reload the meta data cache for the Google Sheet
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $list_from_sheet);
										if(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
											$key = $spreadsheet_meta_data['column_letter_by_name'][$key];
										} else {
											// the column doesn't exist... treat it as a column letter reference
											$key = strtoupper($key);
										}
									}
									$include_conditionals[] = array(
										'logical_operator'=>$inner_matches[1],
										'logical_negator'=>$inner_matches[2],
										'start_group'=>$inner_matches[3],
										'column_letter'=>$key,
										'comparitor'=>$inner_matches[5],
										'string'=>$inner_matches[6],
										'end_group'=>$inner_matches[7]
									);
								}
								$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// START ROW TEMPLATE AT ROW *NUMBER*
					$start_row = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*start\s+(row\s*template\s+|)at\s+row\s*([0-9]+)\s*;\s*/is',
						function($matches) use (&$start_row) {
							$start_row = $matches[2];
							return '';
						},
						$unprocessed_start_list_block
					);

					// SET *LOCAL-VARIABLE* TO TOTAL OF *COLUMN*
					$total_var_by_column = array();
					$page_total_var_by_column = array();
					$totals_by_var = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*set\s+([^;]*?)\s+to(\s+page|)\s+total\s+of\s+([^;]+?)\s*;\s*/is',
						function($matches) use (&$total_var_by_column, &$page_total_var_by_column, $spreadsheet_meta_data) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[3]);
							$column_reference_parts = explode('.', $matches[3], 2);
							if(count($column_reference_parts)==2) {
								$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($column_reference_parts[0])));
								$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower(ltrim($column_reference_parts[1])));
							} else {
								$bucket = 'row';
								$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($matches[3]));
							}
							if($bucket=='row') {
								if(strtolower(trim($matches[2]))=='page') {
									$page_total_var_by_column[$key] = $matches[1];
								} else {
									$total_var_by_column[$key] = $matches[1];
								}
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// SAVE TO *EASE-VARIABLE*
					$save_to_global_variable = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*(store|save|cache)\s*(results|result|list|content|output|)\s+(to|in)\s+([^;]+)\s*;\s*/is',
						function($matches) use (&$save_to_global_variable) {
							$this->interpreter->inject_global_variables($matches[4]);
							$bucket_key_parts = explode('.', $matches[4], 2);
							if(count($bucket_key_parts)==2) {
								$bucket = strtolower(rtrim($bucket_key_parts[0]));
								$key = strtolower(ltrim($bucket_key_parts[1]));
								if(!in_array($bucket, $this->core->reserved_buckets)) {
									$save_to_global_variable = "{$bucket}.{$key}";
								}
							} else {
								$bucket = strtolower(trim($matches[4]));
								if(!in_array($bucket, $this->core->reserved_buckets)) {
									$save_to_global_variable = $bucket;
								}
							}
							return '';
						},
						$unprocessed_start_list_block
					);
					// if the list output is being saved to an EASE variable, blank it out
					if(isset($save_to_global_variable)) {
						$this->core->globals[$save_to_global_variable] = '';
					}

					// !! ANY NEW LIST - FOR GOOGLE SHEET DIRECTIVES GET ADDED HERE

					// if the START Block has any content remaining, there was an unrecognized LIST directive, so log a parse error
					$this->interpreter->remove_comments($unprocessed_start_list_block);
					$this->interpreter->inject_global_variables($unprocessed_start_list_block);
					if(trim($unprocessed_start_list_block)!='') {
						// ERROR! EASE LIST with unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}

					// remove the START Block from the unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($start_list_block));

					// find the END LIST block and parse out the LIST body
					if(preg_match('/(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*list\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
						// END LIST found, parse out the LIST body
						$list_body = $matches[1];
						$list_length = strlen($matches[0]);
					} else {
						// END LIST not found... treat the entire remaining unprocessed body as the LIST body
						$list_body = $this->unprocessed_body;
						$list_length = strlen($list_body);
					}

					// parse out the LIST HEADER template
					$list_header_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+header\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*header\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_header_template) {
							$list_header_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_header_template_tokenized_ease = $this->string_to_tokenized_ease($list_header_template);

					// parse out the LIST ROW template
					$list_row_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+row\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*row\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_row_template) {
							$list_row_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_row_template_tokenized_ease = $this->string_to_tokenized_ease($list_row_template);

					// parse out the LIST FOOTER template
					$list_footer_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+footer\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*footer\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_footer_template) {
							$list_footer_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_footer_template_tokenized_ease = $this->string_to_tokenized_ease($list_footer_template);

					// parse out the LIST NO RESULTS template
					$list_no_results_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(start\s+|)no\s+results\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*no\s*results\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_no_results_template) {
							$list_no_results_template = $matches[2];
							return '';
						},
						$list_body
					);
					$list_no_results_template_tokenized_ease = $this->string_to_tokenized_ease($list_no_results_template);

					// inject anything left in the LIST body to the output buffer
					if(isset($save_to_global_variable)) {
						$this->core->globals[$save_to_global_variable] .= trim($list_body);
					} else {
						$this->output_buffer .= trim($list_body);
					}

					// remove the list body and the END LIST block from the remaining unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, $list_length);

					// initialize a Google Drive API client for Google Sheets
					require_once 'ease/lib/Spreadsheet/Autoloader.php';
					$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
					$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
					Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
					$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
					// determine if the Google Sheet was referenced by "Name" or ID
					if(isset($google_spreadsheet_id) && $google_spreadsheet_id!='') {
						// Google Sheet was referenced by ID
						$spreadSheet = null;
						$try_count = 0;
						while($spreadSheet===null && $try_count<=2) {
							if($try_count > 0) {
								// apply exponential backoff
								sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
							}
							try {
								$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
							} catch(Exception $e) {
								continue;
							}
						}
						if($spreadSheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load Google Sheet by ID: " . htmlspecialchars($google_spreadsheet_id) . "</div>";
							return;
						}
					} elseif(isset($google_spreadsheet_name) && $google_spreadsheet_name!='') {
						// Google Sheet was referenced by "Name"
						$spreadsheetFeed = null;
						$try_count = 0;
						while($spreadsheetFeed===null && $try_count<=2) {
							if($try_count > 0) {
								// apply exponential backoff
								sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
							}
							try {
								$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
							} catch(Exception $e) {
								continue;
							}
						}
						$spreadSheet = $spreadsheetFeed->getByTitle($google_spreadsheet_name);
						if($spreadSheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load Google Sheet by Title: " . htmlspecialchars($google_spreadsheet_name) . "</div>";
							return;
						}
					} else {
						if($original_spreadsheet_reference!='') {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  List for Google Sheet - unset reference: " . htmlspecialchars($original_spreadsheet_reference) . "</div>";
						} else {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  List for Google Sheet - no referenced ID or Title</div>";
						}
						return;
					}
					// if a worksheet was named, use it; otherwise use the first sheet
					$worksheetFeed = null;
					$try_count = 0;
					while($worksheetFeed===null && $try_count<=2) {
						if($try_count > 0) {
							// apply exponential backoff
							sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
						}
						try {
							$worksheetFeed = $spreadSheet->getWorksheets();
						} catch(Exception $e) {
							continue;
						}
					}
					if($list_from_sheet) {
						$worksheet = $worksheetFeed->getByTitle($list_from_sheet);
						if($worksheet===null) {
							$this->output_buffer .= "<div style='margin:5px; color:red;'>Error!  Unable to load worksheet named: " . htmlspecialchars($list_from_sheet) . "</div>";
							return;
						}
					} else {
						$worksheet = $worksheetFeed->getFirstSheet();
					}
					// request all cell data from the worksheet
					$cellFeed = $worksheet->getCellFeed();
					$cell_entries = $cellFeed->getEntries();
					$cells_by_row_by_column_letter = array();
					foreach($cell_entries as $cell_entry) {
						$cell_title = $cell_entry->getTitle();
						preg_match('/([A-Z]+)([0-9]+)/', $cell_title, $inner_matches);
						$cells_by_row_by_column_letter[$inner_matches[2]][$inner_matches[1]] = $cell_entry->getContent();
					}
					// process any filters
					if(count($include_conditionals) > 0 && count($cells_by_row_by_column_letter) > 0) {
						foreach($cells_by_row_by_column_letter as $row_number=>$row_cells_by_column_letter) {
							// don't filter the header row
							if($row_number!=1) {
								if(isset($start_row) && ($start_row > $row_number)) {
									// this was before the start row... remove it from the results
									unset($cells_by_row_by_column_letter[$row_number]);
									continue;
								}
								$php_conditional_string = '';
								foreach($include_conditionals as $include_conditional) {
									if(!isset($row_cells_by_column_letter[$include_conditional['column_letter']])) {
										$row_cells_by_column_letter[$include_conditional['column_letter']] = '';
									}
									$php_conditional_string .= $include_conditional['logical_operator']
										. $include_conditional['logical_negator']
										. $include_conditional['start_group']
										. var_export($row_cells_by_column_letter[$include_conditional['column_letter']], true)
										. $include_conditional['comparitor']
										. var_export($include_conditional['string'], true)
										. $include_conditional['end_group'];
								}
								if(!@eval('if(' . $php_conditional_string . ') return true; else return false;')) {
									// this row did not match the conditional... remove it from the results
									unset($cells_by_row_by_column_letter[$row_number]);
								}
							}
						}
					}
					// process any TOTAL directives
					if(count($total_var_by_column) > 0) {
						foreach($total_var_by_column as $total_column=>$total_var) {
							foreach($cells_by_row_by_column_letter as $row_number=>$row_cells_by_column_letter) {
								// don't total the header row
								if($row_number!=1) {
									if(isset($spreadsheet_meta_data['column_letter_by_name'][$total_column])) {
										$key = $spreadsheet_meta_data['column_letter_by_name'][$total_column];
									} elseif(strlen($total_column)<=2) {
										// assume the column was referenced by column letter
										$key = strtoupper($total_column);
									} else {
										// the column name wasn't found and wasn't likely referenced by column letter
										// reload the meta data cache for the Google Sheet
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $list_from_sheet);
										if(isset($spreadsheet_meta_data['column_letter_by_name'][$total_column])) {
											$key = $spreadsheet_meta_data['column_letter_by_name'][$total_column];
										} else {
											// the column doesn't exist... treat it as a column letter reference
											$key = strtoupper($total_column);
										}
									}
									@$this->local_variables[$total_var] += preg_replace('/[^0-9\.-]+/is', '', $row_cells_by_column_letter[$key]);
								}
							}
						}
					}

					// initialize parameters for local variable injection, and output buffering
					$process_params = array();
					$process_params['column_letter_by_name'] = array();
					$process_params['cells_by_row_by_column_letter'] = &$cells_by_row_by_column_letter;
					if(isset($save_to_global_variable)) {
						$process_params['save_to_global_variable'] = $save_to_global_variable;
					}

					// check if any results were found
					if(count($cells_by_row_by_column_letter)<=1) {
						// LIST has no results, process the NO RESULTS template
						$this->process_tokenized_ease($list_no_results_template_tokenized_ease, $process_params);
						if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
							// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
							return;
						}
					} else {
						// LIST has results, process the 1st row to build column-by-name to column-by-letter maps
						foreach($cells_by_row_by_column_letter[1] as $column_letter=>$column_name) {
							$process_params['column_letter_by_name'][preg_replace('/(^[0-9]+|[^a-z0-9]+)/', '', strtolower($column_name))] = $column_letter;
						}
						// store the row count as a local EASE variable
						$this->local_variables['numberofrows'] = count($cells_by_row_by_column_letter) - 1;
						// process any pager settings to determine if pagers should be shown
						if(strlen(@$_REQUEST['index'])==32) {
							// the page index was requested as the UUID of a record expected to be in the list...
							// show the page that includes the referenced record
							$indexed_page = null;
							$row_id_letter = $process_params['column_letter_by_name']['easerowid'];
							$row_count = 1;
							foreach($cells_by_row_by_column_letter as $row=>$cell_value_by_column_letter) {
								if($row==1) {
									// ignore header row
									continue;
								}
								if(@$cell_value_by_column_letter[$row_id_letter]==$_REQUEST['index']) {
									$indexed_page = ceil(($row_count) / $rows_per_page);
									break;
								} else {
									$row_count++;
								}
							}
							reset($cells_by_row_by_column_letter);
						} else {
							$indexed_page = intval(@$_REQUEST['index']);
							if(($indexed_page * $rows_per_page) > $this->local_variables['numberofrows'] || @$_REQUEST['index']=='last') {
								// the requested page is past the end of the list, or the last page was requested... default to the last page
								$indexed_page = ceil($this->local_variables['numberofrows'] / $rows_per_page);
							} elseif($indexed_page==0 || $rows_per_page==0) {
								$indexed_page = 1;
							}
						}
						if((!isset($indexed_page)) || !$indexed_page) {
							$indexed_page = 1;
						}
						// remove the page index from the query string in the REQUEST URI
						$url_parts = parse_url($_SERVER['REQUEST_URI']);
						$query_string_no_index = preg_replace('/(^index=[a-z0-9]+(&|$)|&index=[a-z0-9]+)/is', '', @$url_parts['query']);
						$show_pagers = array();
						$pager_html = '';
						if(is_array($pagers)) {
							foreach($pagers as $type=>$value) {
								if($type=='both') {
									if($value=='show') {
										$show_pagers['top'] = true;
										$show_pagers['bottom'] = true;
									} else {
										unset($show_pagers['top']);
										unset($show_pagers['bottom']);
									}
								} else {
									if($value=='show') {
										$show_pagers[$type] = true;
									} else {
										unset($show_pagers[$type]);
									}
								}
							}
						}
						$indexed_page_start = 1;
						$indexed_page_end = $this->local_variables['numberofrows'];
						if($rows_per_page > 0) {
							// a rows per page value was set;  determine if any pagers should be defaulted to show
							if(count($pagers)==0) {
								// no pagers were explicitly shown or hidden; default to showing both pagers
								$show_pagers['top'] = true;
								$show_pagers['bottom'] = true;
							} elseif(!isset($pagers['both'])) {
								// a value wasn't set for 'both' pagers; if either 'top' or 'bottom' values weren't set, default to show
								if(!isset($pagers['top'])) {
									$show_pagers['top'] = true;
								}
								if(!isset($pagers['bottom'])) {
									$show_pagers['bottom'] = true;
								}
							}
							// calculate the row range for the indexed page
							$indexed_page_start = (($indexed_page - 1) * $rows_per_page) + 1;
							$indexed_page_end = $indexed_page_start + ($rows_per_page - 1);
						}
						if(count($show_pagers) > 0) {
							// pagers will be shown... inject the default pager style
							$this->inject_pager_style();
							// build the pager html
							$pager_html = $this->build_pager_html($rows_per_page, $this->local_variables['numberofrows'], $indexed_page, $query_string_no_index);
							// show the top pager unless it was hidden
							if($show_pagers['top']) {
								if(isset($save_to_global_variable)) {
									$this->core->globals[$save_to_global_variable] .= $pager_html;
								} else {
									$this->output_buffer .= $pager_html;
								}
							}
						}

						// process the header template
						$this->process_tokenized_ease($list_header_template_tokenized_ease, $process_params);
						if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
							// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
							return;
						}

						// apply the ROW template for each data row and inject the results into the output buffer
						$this->local_variables['rownumber'] = 0;
						foreach($cells_by_row_by_column_letter as $row=>$cell_value_by_column_letter) {
							// ignore the header row
							if($row==1) {
								continue;
							}
							// if this is a paged list, skip rows outside of the indexed page range
							$this->local_variables['rownumber']++;
							if($rows_per_page > 0) {
								if(($this->local_variables['rownumber'] < $indexed_page_start) || ($this->local_variables['rownumber'] > $indexed_page_end)) {
									continue;
								}
							}
							// process the ROW template
							$process_params['cell_value_by_column_letter'] = &$cell_value_by_column_letter;
							$this->process_tokenized_ease($list_row_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
						}

						// process the FOOTER template
						$this->process_tokenized_ease($list_footer_template_tokenized_ease, $process_params);
						if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
							// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
							return;
						}
						// show the bottom pager unless it was hidden
						if(isset($show_pagers['bottom'])) {
							if(isset($save_to_global_variable)) {
								$this->core->globals[$save_to_global_variable] .= $pager_html;
							} else {
								$this->output_buffer .= $pager_html;
							}
						}
					}
					// done processing LIST FOR GOOGLE SHEET, return to process the remaining body
					return;
				}

				###############################################
				##	START LIST - FOR SQL TABLE
				if(preg_match('/^for\s+(.*?)\s*;\s*/is', $unprocessed_start_list_block, $matches)) {
					// determine the referenced SQL table
					$this->interpreter->inject_global_variables($matches[1]);
					$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1])), '_');
					$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
					$referenced_columns = array();
					$this->local_variables = array();
					$this->local_variables['sql_table_name'] = $sql_table_name;

					// validate the referenced SQL table exists by querying for all column names
					if($this->core->db && !$this->core->db_disabled) {
						$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_sql_table_name' AND TABLE_SCHEMA=database();");
						if($existing_columns = $result->fetchAll(PDO::FETCH_COLUMN)) {
							// initialize the SELECT clause of the list query
							$select_clause_sql_string = '';
							foreach($existing_columns as $column) {
								if($select_clause_sql_string!='') {
									$select_clause_sql_string .= ', ';
								}
								$select_clause_sql_string .= "`$namespaced_sql_table_name`.`$column` AS `$sql_table_name.$column`";
							}
						}
					}

					// the FOR attribute of the LIST was successfully parsed, scan for any remaining LIST directives
					$unprocessed_start_list_block = substr($unprocessed_start_list_block, strlen($matches[0]));

					// continue processing the remaining START Block as long as LIST directives are parsed out from the beginning of the unprocessed START Block
					// TODO!! change list directive processing to be parsed and processed sequentially
					$list_directive_found = false;
					do {
						$list_directive_found = false;
						/*
						if(preg_match('/^\s*\s*;(.*)$/is', $unprocessed_start_list_block, $list_directive_matches)) {
							$list_directive_found = true;
							$this->interpreter->inject_global_variables($list_directive_matches[0]);

							$unprocessed_start_list_block = $list_directive_matches[1];
							continue;
						}
						*/
						// !! ANY NEW LIST - FOR SQL TABLE DIRECTIVES GET ADDED HERE
					} while($list_directive_found);

					// IF/ELSE - parse out and process any Conditionals from the start list block
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)/is',
						function($matches) {
							// process the matches to build the conditional array and any catchall else condition
							$conditions[$matches[1]] = $matches[2];
							// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
							$matches_index = 3;
							while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE IF condition
								$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
								// advance the index to look for the next ELSE IF condition
								$matches_index += 3;
							}
							// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
							if($matches_index==3) {
								// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
								$matches_index = 6;
							}
							// check for any ELSE condition
							if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE condition
								$else_text = $matches[$matches_index + 1];
							}
							// process each conditional in order to determine if any of the conditional EASE blocks should be processed
							foreach($conditions as $condition=>$conditional_text) {
								$remaining_condition = $condition;
								$php_condition_string = '';
								while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
									if(strtolower($inner_matches[1])=='and') {
										$inner_matches[1] = '&&';
									}
									if(strtolower($inner_matches[1])=='or') {
										$inner_matches[1] = '||';
									}
									if(strtolower($inner_matches[2])=='not') {
										$inner_matches[2] = '!';
									}
									if(strtolower($inner_matches[5])=='=') {
										$inner_matches[5] = '==';
									}
									if(strtolower($inner_matches[5])=='is') {
										$inner_matches[5] = '==';
									}
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$this->interpreter->inject_global_variables($inner_matches[6]);
									$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
									return $conditional_text;
								}
							}
							if(isset($else_text)) {
								return $else_text;
							} else {
								return '';
							}
						},
						$unprocessed_start_list_block
					);

					// SHOW UNIQUE - directive to group results from a related table
					$show_unique_sql_table = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s*unique\s*([^;]+?)\s*;\s*/is',
						function($matches) use (&$sql_table_name, &$show_unique_sql_table, &$referenced_columns, &$select_clause_sql_string) {
							$this->interpreter->inject_global_variables($matches[1]);
							$show_unique_sql_table = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1])), '_');
							return '';
						},
						$unprocessed_start_list_block
					);
					if($show_unique_sql_table && $show_unique_sql_table!=$sql_table_name) {
						$select_clause_sql_string = '';
					}
				
					// RELATE
					$join_sql_string = '';
					$list_relations = array();
					$existing_relation_columns = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*(must\s*|force\s*|require\s*|always\s*|)(relate|join|link|tie)\s+([^;]+?)\s+to\s+([^;]+?)\s*;\s*/is',
						function($matches) use (&$join_sql_string, &$list_relations, &$show_unique_sql_table, &$existing_relation_columns, &$sql_table_name, &$existing_columns, &$referenced_columns, &$select_clause_sql_string) {
							if($this->core->db && !$this->core->db_disabled) {
								$this->interpreter->inject_global_variables($matches[3]);
								$this->interpreter->inject_global_variables($matches[4]);
								$table_column_parts = explode('.', trim($matches[3]), 2);
								if(count($table_column_parts)==2) {
									$from_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
									$from_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
								} else {
									$from_bucket = $sql_table_name;
									$from_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[3]));
								}
								$table_column_parts = explode('.', $matches[4], 2);
								if(count($table_column_parts)==2) {
									$to_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
									$to_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
								} else {
									$to_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[4]));
									$to_key = $from_key;
								}
								if($from_bucket!=$sql_table_name && $to_bucket==$sql_table_name) {
									// the relate directive was given backwards
									$temp_bucket = $from_bucket;
									$temp_key = $from_key;
									$from_bucket = $to_bucket;
									$from_key = $to_key;
									$to_bucket = $temp_bucket;
									$to_key = $temp_key;
								}
								$namespaced_to_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $to_bucket), '_');
								$namespaced_from_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $from_bucket), '_');
								if($from_bucket==$sql_table_name) {
									// validate the referenced SQL table exists by querying for all column names
									$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_to_sql_table_name' AND TABLE_SCHEMA=database();");
									if($existing_relation_columns[$to_bucket] = $result->fetchAll(PDO::FETCH_COLUMN)) {
										if($from_key=='id') {
											$from_key = 'uuid';
										}
										if($to_key=='id') {
											$to_key = 'uuid';
										}
										if($to_key==$from_key && !in_array($to_key, $existing_relation_columns[$to_bucket])) {
											// this was likely a relate directive like:   relate product_id to products;
											// that would first look for products.product_id, but if that column doesn't exist, join on the products.uuid
											$to_key = 'uuid';
										}
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
											// append to the SELECT clause of the list query
											if((!$show_unique_sql_table) || ($show_unique_sql_table==$to_bucket)) {
												foreach($existing_relation_columns[$to_bucket] as $column) {
													if($select_clause_sql_string!='') {
														$select_clause_sql_string .= ', ';
													}
													$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
												}
											}
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										} elseif(in_array($from_key, $existing_columns) && in_array($to_key, $existing_relation_columns[$to_bucket])) {
											$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
											// append to the SELECT clause of the list query
											if((!$show_unique_sql_table) || ($show_unique_sql_table==$to_bucket)) {
												foreach($existing_relation_columns[$to_bucket] as $column) {
													if($select_clause_sql_string!='') {
														$select_clause_sql_string .= ', ';
													}
													$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
												}
											}
											$join_sql_string .= " LEFT OUTER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									} else {
										// the related SQL Table does not exist
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											// this was a forced relation, so include the JOIN clause even though the query will fail
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									}
								} else {
									// check if this is a relation to a table that was already related to the original relation table				
									if((!isset($existing_relation_columns[$from_bucket])) && isset($existing_relation_columns[$to_bucket])) {
										// the relate directive was given backwards
										$temp_bucket = $from_bucket;
										$temp_key = $from_key;
										$from_bucket = $to_bucket;
										$from_key = $to_key;
										$to_bucket = $temp_bucket;
										$to_key = $temp_key;
									}
									$namespaced_to_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $to_bucket), '_');
									$namespaced_from_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $from_bucket), '_');
									if(isset($existing_relation_columns[$from_bucket])) {
										// validate the referenced SQL table exists by querying for all column names
										$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_to_sql_table_name' AND TABLE_SCHEMA=database();");
										if($existing_relation_columns[$to_bucket] = $result->fetchAll(PDO::FETCH_COLUMN)) {
											if($from_key=='id') {
												$from_key = 'uuid';
											}
											if($to_key=='id') {
												$to_key = 'uuid';
											}
											if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
												$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
												// append to the SELECT clause of the list query
												if((!$show_unique_sql_table) || ($show_unique_sql_table==$to_bucket)) {
													foreach($existing_relation_columns[$to_bucket] as $column) {
														if($select_clause_sql_string!='') {
															$select_clause_sql_string .= ', ';
														}
														$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
													}
												}
												$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											} elseif(in_array($from_key, $existing_relation_columns[$from_bucket]) && in_array($to_key, $existing_relation_columns[$to_bucket])) {
												$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
												// append to the SELECT clause of the list query
												if((!$show_unique_sql_table) || ($show_unique_sql_table==$to_bucket)) {
													foreach($existing_relation_columns[$to_bucket] as $column) {
														if($select_clause_sql_string!='') {
															$select_clause_sql_string .= ', ';
														}
														$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
													}
												}
												$join_sql_string .= " LEFT OUTER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											}
										} else {
											// the related SQL Table does not exist
											if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
												// this was a forced relation, so include the JOIN clause even though the query will fail
												$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											}
										}
									} else {
										// the related SQL Table does not exist
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											// this was a forced relation, so include the JOIN clause even though the query will fail
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									}
								}
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// INCLUDE WHEN *SQL-TABLE-COLUMN* *COMPARITOR* "quoted"  (full support for paren nesting and all boolean operators)
					$where_sql_string = '';
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*include\s+when(\s+|\s*\()((\s*&&\s*|\s*and\s+|\s*\|\|\s*|\s*or\s+|\s*xor\s+|\s*)(\()*([^;]+?)\s*(===|==|=|!==|!=|is\s*not|is|>|>=|<|<=|<>|contains\s*exactly|contains|~|regexp|regex|like|ilike|rlike|not\s*in|in)\s*"(.*?)"\s*(if\s*set){0,1}\s*(\))*)*(\s*' . preg_quote($this->core->ease_block_start, '/') . '\s*' . preg_quote($this->core->global_reference_start, '/') . '.*' . preg_quote($this->core->global_reference_end, '/') . '\s*' . preg_quote($this->core->ease_block_end, '/') . '\s*)*;\s*/is',
						function($matches) use (&$where_sql_string, &$existing_columns, &$existing_relation_columns, &$referenced_columns, $sql_table_name) {
							if($this->core->db && !$this->core->db_disabled) {
								if(isset($matches[10]) && trim($matches[10])!='') {
									$this->interpreter->inject_global_variables($matches[0]);
								}
								if($where_sql_string!='') {
									$where_sql_string .= ' AND ';
								}
								$where_sql_string .= '(';
								preg_match('/\s*include\s+when\s*(.*);\s*/is', $matches[0], $matches);						
								$remaining_condition = $matches[1];
								while(preg_match('/^(&&|&|\|\||and\s+|or\s+|xor\s+){0,1}\s*(!|not\s+){0,1}\s*([(\s]*)([^;]+?)\s*(===|==|=|!==|!=|>|>=|<|<=|<>|is\s*not|is|contains\s*exactly|contains|~|regexp|regex|like|ilike|rlike|not\s*in|in)\s*"(.*?)"\s*(if\s*set){0,1}([)\s]*)/is', $remaining_condition, $inner_matches)) {
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$table_column_parts = explode('.', $inner_matches[4], 2);
									if(count($table_column_parts)==2) {
										$table = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($table_column_parts[0])), '_');
										$column = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($table_column_parts[1])), '_');
									} else {
										$table = $sql_table_name;
										$column = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($inner_matches[4])), '_');
									}
									if($column=='id') {
										$column = 'uuid';
									}
									if($table==$sql_table_name) {
										$referenced_columns[$column] = true;
									}
									$namespaced_table = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $table), '_');									
									$cleansed_comparator = strtolower(preg_replace('/\s+/s', ' ', trim($inner_matches[5])));
									switch($cleansed_comparator) {
										case '==':
										case '===':
										case 'is':
											$inner_matches[5] = '=';
											break;
										case '!=':
										case '!==':
										case 'is not':
											$inner_matches[5] = '<>';
											break;
										case 'like':
										case 'contains exactly':
											$inner_matches[5] = 'LIKE';
											break;
										case 'contains':
										case 'ilike':
											$inner_matches[5] = 'contains';
											break;
										case '~':
										case 'regex':
										case 'regexp':
											$inner_matches[5] = 'REGEXP';
											break;
										default:
									}
									$this->interpreter->inject_global_variables($inner_matches[6]);
									if(strtolower(preg_replace('/\s+/s', ' ', trim($inner_matches[7])))=='if set' && trim($inner_matches[6])=='') {
										// the directive contained an IF SET directive, and the comparison value was not set, so never filter based on this term
										// the comparison will be replaced in the SQL WHERE clause with the boolean value TRUE, maintaining any grouped conditional logic
										$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] TRUE $inner_matches[8] ";
									} elseif(($table==$sql_table_name && @in_array($column, $existing_columns)) || @in_array($column, $existing_relation_columns[$table])) {
										// the directive contained a valid column name to compare
										// add the comparison clause to the SQL WHERE directive, maintaining any grouped conditional logic
										if($cleansed_comparator=='in' || $cleansed_comparator=='not in') {
											$in_csv = '';
											foreach(explode(',', $inner_matches[6]) as $sql_list_item) {
												if($in_csv!='') {
													$in_csv .= ',';
												}	
												$in_csv .= $this->core->db->quote(trim($sql_list_item));
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] `$namespaced_table`.`$column` $cleansed_comparator ($in_csv) $inner_matches[8] ";
										} elseif($inner_matches[5]=='contains') {
											$value = $this->core->db->quote('%' . $inner_matches[6] . '%');
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] UPPER(`$namespaced_table`.`$column`) LIKE UPPER($value) $inner_matches[8] ";
										} else {
											if(preg_match('/^\s*(-|)[0-9\s\.,]+$/is', $inner_matches[6], $number_matches) && preg_match('/^(=|<>|>|>=|<|<=)$/', $inner_matches[5], $comparitor_matches)) {
												$value = $inner_matches[6];
											} else {
												$value = $this->core->db->quote($inner_matches[6]);
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] `$namespaced_table`.`$column` $inner_matches[5] $value $inner_matches[8] ";
										}
									} else {
										// the directive did not contain a valid column name to compare
										// add the comparison clause to the SQL WHERE directive, treating the column value as a blank string, maintaining any grouped conditional logic
										if($cleansed_comparator=='in' || $cleansed_comparator=='not in') {
											$in_csv = '';
											foreach(explode(',', $inner_matches[6]) as $sql_list_item) {
												if($in_csv!='') {
													$in_csv .= ',';
												}	
												$in_csv .= $this->core->db->quote(trim($sql_list_item));
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' $cleansed_comparator ($in_csv) $inner_matches[8] ";
										} elseif($inner_matches[5]=='contains') {
											$value = $this->core->db->quote('%' . $inner_matches[6] . '%');
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' LIKE UPPER($value) $inner_matches[8] ";
										} else {
											$value = $this->core->db->quote($inner_matches[6]);
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' $inner_matches[5] $value $inner_matches[8] ";
										}
									}
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								$where_sql_string .= ')';
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// HIDE PAGERS / SHOW PAGERS
					$pagers = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*hide\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*hide\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_list_block
					);
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_list_block
					);
					// SHOW *NUMBER* ROWS PER PAGE
					$rows_per_page = null;
					$rows_of_sql_table = null;
					$total_pages = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*show\s*([0-9]+)\s*([^;]+?)\s*per\s*page\s*;\s*/is',
						function($matches) use (&$rows_per_page, &$rows_of_sql_table) {
							$rows_per_page = $matches[1];
							if(strtolower($matches[2])!='rows') {
								// this is a paged relation list
								$rows_of_sql_table = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[2]));
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// SET *LOCAL-VARIABLE* TO TOTAL OF *SQL-TABLE-COLUMN*
					$total_var_by_column = array();
					$page_total_var_by_column = array();
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*set\s+([^;]*?)\s+to(\s+page|)\s+total\s+of\s+([^;]+?)\s*;\s*/is',
						function($matches) use (&$total_var_by_column, &$page_total_var_by_column, &$referenced_columns, $sql_table_name) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[3]);
							$table_column_parts = explode('.', $matches[3], 2);
							if(count($table_column_parts)==2) {
								$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
							} else {
								$bucket = $sql_table_name;
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[3]));
							}
							if($bucket==$sql_table_name) {
								$referenced_columns[$key] = true;
							}
							if(strtolower(trim($matches[2]))=='page') {
								$page_total_var_by_column["$bucket.$key"] = $matches[1];
							} else {
								$total_var_by_column["$bucket.$key"] = $matches[1];
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// SORT BY *SQL-TABLE-COLUMN* ASCENDING / DESCENDING
					$order_by_sql_string = '';
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*(sort|order)\s+by\s+([^;]+?)\s*;\s*/is',
						function($matches) use (&$order_by_sql_string, &$sql_table_name) {
							if($this->core->db && !$this->core->db_disabled) {
								$this->interpreter->inject_global_variables($matches[2]);
								$sort_by_directives	= explode(',', $matches[2]);
								foreach($sort_by_directives as $sort_by_directive) {
									$context_stack = $this->interpreter->extract_context_stack($sort_by_directive);
									$sort_by_directive = preg_replace('/[^a-z0-9_`,\*\. ]+/is', '', strtolower($sort_by_directive));
									$sort_by_directive = preg_replace('/in\s+descending\s+order/is', 'DESC', $sort_by_directive);
									$sort_by_directive = preg_replace('/descending\s+order/is', 'DESC', $sort_by_directive);
									$sort_by_directive = preg_replace('/descending/is', 'DESC', $sort_by_directive);
									$sort_by_directive = preg_replace('/in\s+ascending\s+order/is', 'ASC', $sort_by_directive);
									$sort_by_directive = preg_replace('/ascending\s+order/is', 'ASC', $sort_by_directive);
									$sort_by_directive = preg_replace('/ascending/is', 'ASC', $sort_by_directive);
									$table_column_parts = explode('.', $sort_by_directive, 2);
									if(count($table_column_parts)==1) {
										// the table name was not included, default to the main list table
										$sort_by_directive = $sql_table_name . '.' . $sort_by_directive;
									}
									if(count($context_stack) > 0) {
										if(preg_match('/^\s*(.*?)\s+desc\s*$/is', $sort_by_directive, $inner_matches)) {
											$sort_by_directive = $inner_matches[1];
											$order_type = 'DESC';
										} else {
											if(preg_match('/^\s*(.*?)\s+asc\s*$/is', $sort_by_directive, $inner_matches)) {
												$sort_by_directive = $inner_matches[1];
											}
											$order_type = 'ASC';
										}							
										foreach($context_stack as $context) {
											switch($context) {
												case 'dollars':
													$sort_by_directive = "CAST(replace(replace($sort_by_directive, '\$', ''), ',', '') AS decimal) $order_type";
													break;
												case 'integer':
												case 'int':
												case 'number':
												case 'numeric':
												case 'numerical':
												case 'decimal':
												case 'float':
												case 'long':
													$sort_by_directive = "CAST($sort_by_directive AS decimal) $order_type";
													break;
												default:
												$sort_by_directive = "CAST($sort_by_directive AS " . strtoupper($context) . ") $order_type";
											}
										}
									}
									if($order_by_sql_string) {
										$order_by_sql_string .= ", $sort_by_directive ";
									} else {
										$order_by_sql_string = " ORDER BY $sort_by_directive ";
									}
								}
							}
							return '';
						},
						$unprocessed_start_list_block
					);

					// SAVE TO *EASE-VARIABLE*
					$save_to_global_variable = null;
					$unprocessed_start_list_block = preg_replace_callback(
						'/\s*(store|save|cache)\s*(results|result|list|content|output|)\s+(to|in)\s+([^;]+)\s*;\s*/is',
						function($matches) use (&$save_to_global_variable) {
							$this->interpreter->inject_global_variables($matches[4]);
							$bucket_key_parts = explode('.', $matches[4], 2);
							if(count($bucket_key_parts)==2) {
								$bucket = strtolower(rtrim($bucket_key_parts[0]));
								$key = strtolower(ltrim($bucket_key_parts[1]));
								if(!in_array($bucket, $this->core->reserved_buckets)) {
									$save_to_global_variable = "{$bucket}.{$key}";
								}
							} else {
								$bucket = strtolower(trim($matches[4]));
								if(!in_array($bucket, $this->core->reserved_buckets)) {
									$save_to_global_variable = $bucket;
								}
							}
							return '';
						},
						$unprocessed_start_list_block
					);
					if(isset($save_to_global_variable)) {
						// the output of this LIST will be saved in a global variable;  initialize that variable to a blank string
						$this->core->globals[$save_to_global_variable] = '';
					}

					// !! ANY NEW LIST - FOR SQL TABLE DIRECTIVES GET ADDED HERE

					// if the START Block has any content remaining, there was an unrecognized LIST directive, so log a parse error
					$this->interpreter->remove_comments($unprocessed_start_list_block);
					if(trim($unprocessed_start_list_block)!='') {
						// ERROR! LIST with an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}

					// remove the START Block from the unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($start_list_block));

					// find the END LIST block and parse out the LIST body
					if(preg_match('/(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*list\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
						// END LIST found, parse out the LIST body
						$list_body = $matches[1];
						$list_length = strlen($matches[0]);
					} else {
						// END LIST not found... treat the entire remaining unprocessed body as the LIST body
						$list_body = $this->unprocessed_body;
						$list_length = strlen($list_body);
					}

					// parse out the LIST HEADER template
					$list_header_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+header\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*header\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_header_template) {
							$list_header_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_header_template_tokenized_ease = $this->string_to_tokenized_ease($list_header_template);

					// parse out the LIST ROW template
					$list_row_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+row\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*row\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_row_template) {
							$list_row_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_row_template_tokenized_ease = $this->string_to_tokenized_ease($list_row_template);

					// parse out the LIST FOOTER template
					$list_footer_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+footer\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*footer\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_footer_template) {
							$list_footer_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_footer_template_tokenized_ease = $this->string_to_tokenized_ease($list_footer_template);

					// parse out the LIST NO RESULTS template
					$list_no_results_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(start\s+|)no\s+results\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*no\s*results\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_no_results_template) {
							$list_no_results_template = $matches[2];
							return '';
						},
						$list_body
					);
					$list_no_results_template_tokenized_ease = $this->string_to_tokenized_ease($list_no_results_template);

					// inject anything left in the LIST body to the output buffer
					// TODO!! if no templates were set, treat the List body as the ROW template
					if(isset($save_to_global_variable)) {
						$this->core->globals[$save_to_global_variable] .= trim($list_body);
					} else {
						$this->output_buffer .= trim($list_body);
					}

					// remove the list body and the END LIST tag from the remaining unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, $list_length);

					if($this->core->db && !$this->core->db_disabled) {
						// query for all data relevant to the list
						if($show_unique_sql_table) {
							$select_clause_sql_string = "DISTINCT $select_clause_sql_string";
						}
						if($where_sql_string) {
							// there is a WHERE clause to add
							$where_sql_string = "WHERE $where_sql_string";
						}
						if($order_by_sql_string=='') {
							// no sorting options provided... default sorting by creation date
							$order_by_sql_string = " ORDER BY `$namespaced_sql_table_name`.created_on ASC ";
						}
						$query = "SELECT $select_clause_sql_string FROM `$namespaced_sql_table_name` $join_sql_string $where_sql_string $order_by_sql_string;";
						$list_rows = array();
						if($result = $this->core->db->query($query)) {
							$list_rows = $result->fetchAll(PDO::FETCH_ASSOC);
						} else {
							// the query failed...
							$db_error = $this->core->db->errorInfo();
						}

						// initialize parameters for output buffering and local variable injection when processing tokenized EASE
						$process_params = array();
						if(isset($save_to_global_variable)) {
							$process_params['save_to_global_variable'] = $save_to_global_variable;
						}
						$process_params['sql_table_name'] = $sql_table_name;
						$process_params['rows'] = &$list_rows;
						$process_params['row'] = array();

						// process the LIST results
						$this->local_variables['numberofrows'] = count($list_rows);
						if($this->local_variables['numberofrows'] > 0) {
							// LIST has results, process any pager settings to determine if pagers should be shown
							if(strlen(@$_REQUEST['index'])==32) {
								// the page index was requested as the UUID of a record expected to be in the list
								// show the page that includes the referenced record
								if($rows_of_sql_table!=null) {
									// this is a paged relation list
									$indexed_page = null;
									// calculate the total number of pages
									$total_pages = 1;
									$current_page_item_count = 0;
									$current_paged_item_uuid = '';
									foreach($list_rows as $row_key=>$row) {
										if((!isset($row["$rows_of_sql_table.uuid"])) || ($row["$rows_of_sql_table.uuid"]!=$current_paged_item_uuid)) {
											// this is a new paged item
											if($current_page_item_count==$rows_per_page) {
												// the current page is full... start a new page
												$total_pages++;
												$current_page_item_count = 1;
											} else {
												$current_page_item_count++;
											}
											$current_paged_item_uuid = $row["$rows_of_sql_table.uuid"];
										}
										if(is_null($indexed_page) && $row[$sql_table_name . '.uuid']==$_REQUEST['index']) {
											$indexed_page = $total_pages;
										}
									}
								} else {
									if($rows_per_page > 0) {
										foreach($list_rows as $row_key=>$row) {
											if($row[$sql_table_name . '.uuid']==$_REQUEST['index']) {
												$indexed_page = ceil(($row_key + 1) / $rows_per_page);
												break;
											}
										}
									} else {
										$indexed_page = 1;
									}
								}
								reset($list_rows);
							} else {
								$indexed_page = intval(@$_REQUEST['index']);
								if($rows_of_sql_table!=null) {
									// this is a paged relation list... calculate the total number of pages
									$start_and_end_by_page = array();
									$total_pages = 1;
									$current_item_count = 0;
									$current_page_item_count = 0;
									$current_paged_item_uuid = '';
									foreach($list_rows as $row_key=>$row) {
										$current_item_count++;
										if((!isset($row["$rows_of_sql_table.uuid"])) || ($row["$rows_of_sql_table.uuid"]!=$current_paged_item_uuid)) {
											// this is a new paged item
											if($current_page_item_count==$rows_per_page) {
												// the current page is full... start a new page
												$start_and_end_by_page[$total_pages]['end'] = $current_item_count - 1;
												$total_pages++;
												$start_and_end_by_page[$total_pages]['start'] = $current_item_count;
												$current_page_item_count = 1;
											} else {
												$current_page_item_count++;
											}
											$current_paged_item_uuid = $row["$rows_of_sql_table.uuid"];
										}
									}
									$start_and_end_by_page[$total_pages]['end'] = $current_item_count;							
									if((@strtolower($_REQUEST['index'])=='last') || ($indexed_page > $total_pages)) {
										// the requested page is either past the end of the list or explicitly the last page... show the last page
										$indexed_page = $total_pages;
									} elseif($indexed_page==0 || $rows_per_page==0) {
										$indexed_page = 1;
									}
								} else {
									if((@strtolower($_REQUEST['index'])=='last') || (($indexed_page * $rows_per_page) > $this->local_variables['numberofrows'])) {
										// the requested page is either past the end of the list or explicitly the last page... show the last page
										$indexed_page = ceil($this->local_variables['numberofrows'] / $rows_per_page);
									} elseif($indexed_page==0 || $rows_per_page==0) {
										$indexed_page = 1;
									}
								}
							}
							if((!isset($indexed_page)) || !$indexed_page) {
								$indexed_page = 1;
							}
							// remove the page index from the query string in the REQUEST URI
							$url_parts = parse_url($_SERVER['REQUEST_URI']);
							$query_string_no_index = preg_replace('/(^index=[a-z0-9]+(&|$)|&index=[a-z0-9]+)/is', '', @$url_parts['query']);
							$show_pagers = array();
							$pager_html = '';
							if(is_array($pagers)) {
								foreach($pagers as $type=>$value) {
									if($type=='both') {
										if($value=='show') {
											$show_pagers['top'] = true;
											$show_pagers['bottom'] = true;
										} else {
											unset($show_pagers['top']);
											unset($show_pagers['bottom']);
										}
									} else {
										if($value=='show') {
											$show_pagers[$type] = true;
										} else {
											unset($show_pagers[$type]);
										}
									}
								}
							}
							$indexed_page_start = 1;
							$indexed_page_end = $this->local_variables['numberofrows'];
							if($rows_per_page > 0) {
								// a rows per page value was set;  determine if any pagers should be defaulted to show
								if(count($pagers)==0) {
									// no pagers were explicitly shown or hidden; default to showing both pagers
									$show_pagers['top'] = true;
									$show_pagers['bottom'] = true;
								} elseif(!isset($pagers['both'])) {
									// a value wasn't set for 'both' pagers; if either 'top' or 'bottom' values weren't set, default to show
									if(!isset($pagers['top'])) {
										$show_pagers['top'] = true;
									}
									if(!isset($pagers['bottom'])) {
										$show_pagers['bottom'] = true;
									}
								}
								// calculate the row range for the indexed page
								if($rows_of_sql_table!=null) {
									// this is a paged relation list
									$indexed_page_start = @$start_and_end_by_page[$indexed_page]['start'];
									$indexed_page_end = @$start_and_end_by_page[$indexed_page]['end'];
								} else {
									$indexed_page_start = (($indexed_page - 1) * $rows_per_page) + 1;
									$indexed_page_end = $indexed_page_start + ($rows_per_page - 1);
								}
							}
							// apply the HEADER template
							if(count($show_pagers) > 0) {
								// pagers will be shown... include the default pager style once per request
								$this->inject_pager_style();
								// build the pager html
								$pager_html = $this->build_pager_html($rows_per_page, $this->local_variables['numberofrows'], $indexed_page, $query_string_no_index, $total_pages);
								// show the top pager if set
								if(isset($show_pagers['top'])) {
									if(isset($save_to_global_variable)) {
										$this->core->globals[$save_to_global_variable] .= $pager_html;
									} else {
										$this->output_buffer .= $pager_html;
									}
								}
							}
							// process the HEADER template
							$this->process_tokenized_ease($list_header_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
							// apply the ROW template for each data row and inject the results into the output buffer
							$this->local_variables['rownumber'] = 0;
							$update_sql_columns_already_added = array();
							$update_sql_columns_already_added_by_table = array();
							foreach($list_rows as $row_key=>$row) {
								$process_params['row'] = &$row;
								$process_params['row_key'] = &$row_key;
								$this->local_variables['row'] = &$row;
								$this->local_variables['rownumber']++;
								// apply any TOTAL directives from the START Block
								if(count($total_var_by_column) > 0) {
									foreach($total_var_by_column as $total_column=>$total_var) {
										$total_column_parts = explode('.', $total_column, 2);
										$total_var_parts = explode('.', $total_var, 2);
										if(count($total_var_parts)==2) {
											$total_var_parts[0] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($total_var_parts[0])));
											$total_var_parts[1] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($total_var_parts[1])));
											if(($total_var_parts[0]==$sql_table_name || $total_var_parts[0]=='row') && isset($row[$total_column])) {
												@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/s', '', $row[$total_column]);
												$row["$sql_table_name.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
												if(!in_array($total_var_parts[0], $this->core->reserved_buckets)) {
													$this->core->globals["{$total_var_parts[0]}.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
												}
											}
										} else {
											@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/is', '', $row[$total_column]);
											$row["$sql_table_name.$total_var"] = $totals_by_var[$total_column];
											if(!in_array($total_var, $this->core->reserved_buckets)) {
												$this->core->globals[$total_var] = $totals_by_var[$total_column];
											}
										}
									}
								}
								// if this is a paged list, skip rows outside of the indexed page range
								if($rows_per_page > 0) {
									if(($this->local_variables['rownumber'] < $indexed_page_start) || ($this->local_variables['rownumber'] > $indexed_page_end)) {
										continue;
									}
									// apply any PAGE TOTAL directives from the START Block
									if(count($page_total_var_by_column) > 0) {
										foreach($page_total_var_by_column as $total_column=>$total_var) {
											$total_column_parts = explode('.', $total_column, 2);
											$total_var_parts = explode('.', $total_var, 2);
											if(count($total_var_parts)==2) {
												$total_var_parts[0] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($total_var_parts[0])));
												$total_var_parts[1] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($total_var_parts[1])));
												if(($total_var_parts[0]==$sql_table_name || $total_var_parts[0]=='row') && isset($row[$total_column])) {
													@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/s', '', $row[$total_column]);
													$row["$sql_table_name.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
													if(!in_array($total_var_parts[0], $this->core->reserved_buckets)) {
														$this->core->globals["{$total_var_parts[0]}.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
													}
												}
											} else {
												@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/is', '', $row[$total_column]);
												$row["$sql_table_name.$total_var"] = $totals_by_var[$total_column];
												if(!in_array($total_var, $this->core->reserved_buckets)) {
													$this->core->globals[$total_var] = $totals_by_var[$total_column];
												}
											}
										}
									}
								}
								// process the row template
								$this->process_tokenized_ease($list_row_template_tokenized_ease, $process_params);
								if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
									// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
									return;
								}
							}
							// process the FOOTER template
							$this->process_tokenized_ease($list_footer_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
							// show the bottom pager if set
							if(isset($show_pagers['bottom'])) {
								if(isset($save_to_global_variable)) {
									$this->core->globals[$save_to_global_variable] .= $pager_html;
								} else {
									$this->output_buffer .= $pager_html;
								}
							}
						} else {
							// LIST has no results, process the NO RESULTS template
							$this->process_tokenized_ease($list_no_results_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
						}
					}
					// done processing LIST for SQL Table, return to process the remaining body
					return;
				}
			}

			###############################################
			##	START CHECKLIST FORM
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+check\s*list(\s+form|)\s+(.*?(;|})\h*(\/\/\V*|)(\v+\s*\/\/\V*)*)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				$start_checklist_block = $matches[0];
				$this->interpreter->remove_comments($matches[2]);
				$unprocessed_start_checklist_block = $matches[2];

				###############################################
				##	START CHECKLIST FORM - FOR GOOGLE SHEET
				// TODO!!

				###############################################
				##	START CHECKLIST FORM - FOR SQL TABLE
				if(preg_match('/^for\s+([^;]+?)\s*;\s*/is', $unprocessed_start_checklist_block, $matches)) {
					// determine the referenced SQL table
					$this->interpreter->inject_global_variables($matches[1]);
					$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[1])), '_');
					$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $sql_table_name), '_');
					$referenced_columns = array();
					$this->local_variables = array();
					$this->local_variables['sql_table_name'] = $sql_table_name;

					// validate the referenced SQL table exists by querying for all column names
					if($this->core->db && !$this->core->db_disabled) {
						$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_sql_table_name' AND TABLE_SCHEMA=database();");
						if($existing_columns = $result->fetchAll(PDO::FETCH_COLUMN)) {
							// build the SELECT clause of the list query
							$select_clause_sql_string = '';
							foreach($existing_columns as $column) {
								if($select_clause_sql_string!='') {
									$select_clause_sql_string .= ', ';
								}
								$select_clause_sql_string .= "`$namespaced_sql_table_name`.`$column` AS `$sql_table_name.$column`";
							}
						}
					}
				
					// the FOR attribute of the CHECKLIST FORM was successfully parsed, scan for any remaining CHECKLIST FORM directives
					$unprocessed_start_checklist_block = substr($unprocessed_start_checklist_block, strlen($matches[0]));

					// IF/ELSE - parse out and process any Conditionals from the start list block
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*(else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*)*(else\s*\{(.*?)\}\s*|)/is',
						function($matches) {
							// process the matches to build the conditional array and any catchall else condition
							$conditions[$matches[1]] = $matches[2];
							// any ELSE IF conditions will start at the 3rd item in the regular expression matches array
							$matches_index = 3;
							while(preg_match('/^else\s*if/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE IF condition
								$conditions[$matches[$matches_index + 1]] = $matches[$matches_index + 2];
								// advance the index to look for the next ELSE IF condition
								$matches_index += 3;
							}
							// if the index pointer is still at the initial setting 3, there were no ELSE IF conditions
							if($matches_index==3) {
								// advance the index to the 6th item in the regular expression matches array where an ELSE condition might be found
								$matches_index = 6;
							}
							// check for any ELSE condition
							if(preg_match('/^else/is', $matches[$matches_index], $inner_matches)) {
								// found an ELSE condition
								$else_text = $matches[$matches_index + 1];
							}
							// process each conditional in order to determine if any of the conditional EASE blocks should be processed
							foreach($conditions as $condition=>$conditional_text) {
								$remaining_condition = $condition;
								$php_condition_string = '';
								while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $inner_matches)) {
									if(strtolower($inner_matches[1])=='and') {
										$inner_matches[1] = '&&';
									}
									if(strtolower($inner_matches[1])=='or') {
										$inner_matches[1] = '||';
									}
									if(strtolower($inner_matches[2])=='not') {
										$inner_matches[2] = '!';
									}
									if(strtolower($inner_matches[5])=='=') {
										$inner_matches[5] = '==';
									}
									if(strtolower($inner_matches[5])=='is') {
										$inner_matches[5] = '==';
									}
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$this->interpreter->inject_global_variables($inner_matches[6]);
									$php_condition_string .= $inner_matches[1] . $inner_matches[2] . $inner_matches[3] . var_export($inner_matches[4], true) . $inner_matches[5] . var_export($inner_matches[6], true) . $inner_matches[7];
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
									return $conditional_text;
								}
							}
							if(isset($else_text)) {
								return $else_text;
							} else {
								return '';
							}
						},
						$unprocessed_start_checklist_block
					);

					// RELATE
					$join_sql_string = '';
					$checklist_form_relations = array();
					$existing_relation_columns = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*(must\s*|force\s*|require\s*|always\s*|)(relate|join|link|tie)\s+([^;]+?)\s+to\s+([^;]+?)\s*;\s*/is',
						function($matches) use (&$join_sql_string, &$checklist_form_relations, &$existing_relation_columns, &$sql_table_name, &$existing_columns, &$referenced_columns, &$select_clause_sql_string) {
							if($this->core->db && !$this->core->db_disabled) {
								$this->interpreter->inject_global_variables($matches[3]);
								$this->interpreter->inject_global_variables($matches[4]);
								$table_column_parts = explode('.', trim($matches[3]), 2);
								if(count($table_column_parts)==2) {
									$from_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
									$from_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
								} else {
									$from_bucket = $sql_table_name;
									$from_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[3]));
								}
								$table_column_parts = explode('.', $matches[4], 2);
								if(count($table_column_parts)==2) {
									$to_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
									$to_key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
								} else {
									$to_bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[4]));
									$to_key = $from_key;
								}
								if($from_bucket!=$sql_table_name && $to_bucket==$sql_table_name) {
									// the relate directive was given backwards
									$temp_bucket = $from_bucket;
									$temp_key = $from_key;
									$from_bucket = $to_bucket;
									$from_key = $to_key;
									$to_bucket = $temp_bucket;
									$to_key = $temp_key;
								}
								$namespaced_to_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $to_bucket), '_');
								$namespaced_from_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $from_bucket), '_');								
								if($from_bucket==$sql_table_name) {
									// validate the referenced SQL table exists by querying for all column names
									$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_to_sql_table_name' AND TABLE_SCHEMA=database();");
									if($existing_relation_columns[$to_bucket] = $result->fetchAll(PDO::FETCH_COLUMN)) {
										if($from_key=='id') {
											$from_key = 'uuid';
										}
										if($to_key=='id') {
											$to_key = 'uuid';
										}
										if($to_key==$from_key && !in_array($to_key, $existing_relation_columns[$to_bucket])) {
											// this was likely a relate directive like:   relate product_id to products;
											// that would first look for products.product_id, but if that column doesn't exist, join on the products.uuid
											$to_key = 'uuid';
										}
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											$checklist_form_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
											// append to the SELECT clause of the list query
											foreach($existing_relation_columns[$to_bucket] as $column) {
												if($select_clause_sql_string!='') {
													$select_clause_sql_string .= ', ';
												}
												$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
											}
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										} elseif(in_array($from_key, $existing_columns) && in_array($to_key, $existing_relation_columns[$to_bucket])) {
											$checklist_form_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
											// append to the SELECT clause of the list query
											foreach($existing_relation_columns[$to_bucket] as $column) {
												if($select_clause_sql_string!='') {
													$select_clause_sql_string .= ', ';
												}
												$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
											}
											$join_sql_string .= " LEFT OUTER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									} else {
										// the related SQL Table does not exist
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											// this was a forced relation, so include the JOIN clause even though the query will fail
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									}
								} else {
									// check if this is a relation to a table that was already related to the original relation table				
									if((!isset($existing_relation_columns[$from_bucket])) && isset($existing_relation_columns[$to_bucket])) {
										// the relate directive was given backwards
										$temp_bucket = $from_bucket;
										$temp_key = $from_key;
										$from_bucket = $to_bucket;
										$from_key = $to_key;
										$to_bucket = $temp_bucket;
										$to_key = $temp_key;
									}
									$namespaced_to_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $to_bucket), '_');
									$namespaced_from_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $from_bucket), '_');
									if(isset($existing_relation_columns[$from_bucket])) {
										// validate the referenced SQL table exists by querying for all column names
										$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_to_sql_table_name' AND TABLE_SCHEMA=database();");
										if($existing_relation_columns[$to_bucket] = $result->fetchAll(PDO::FETCH_COLUMN)) {
											if($from_key=='id') {
												$from_key = 'uuid';
											}
											if($to_key=='id') {
												$to_key = 'uuid';
											}
											if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
												$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
												// append to the SELECT clause of the list query
												foreach($existing_relation_columns[$to_bucket] as $column) {
													if($select_clause_sql_string!='') {
														$select_clause_sql_string .= ', ';
													}
													$select_clause_sql_string .= "`$to_bucket`.`$column` AS `$to_bucket.$column`";
												}
												$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											} elseif(in_array($from_key, $existing_relation_columns[$from_bucket]) && in_array($to_key, $existing_relation_columns[$to_bucket])) {
												$list_relations["{$from_bucket}.{$from_key}"] = "{$to_bucket}.{$to_key}";
												// append to the SELECT clause of the list query
												foreach($existing_relation_columns[$to_bucket] as $column) {
													if($select_clause_sql_string!='') {
														$select_clause_sql_string .= ', ';
													}
													$select_clause_sql_string .= "`$namespaced_to_sql_table_name`.`$column` AS `$to_bucket.$column`";
												}
												$join_sql_string .= " LEFT OUTER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											}
										} else {
											// the related SQL Table does not exist
											if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
												// this was a forced relation, so include the JOIN clause even though the query will fail
												$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
											}
										}
									} else {
										// the related SQL Table does not exist
										if(in_array(strtolower(trim($matches[1])), array('must', 'force', 'require', 'always'))) {
											// this was a forced relation, so include the JOIN clause even though the query will fail
											$join_sql_string .= " INNER JOIN `$namespaced_to_sql_table_name` ON `$namespaced_from_sql_table_name`.`$from_key`=`$namespaced_to_sql_table_name`.`$to_key` ";
										}
									}
								}
							}
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// INCLUDE WHEN *SQL-TABLE-COLUMN* *COMPARITOR* "quoted"  (full support for paren nesting and all boolean operators)
					$where_sql_string = '';
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*include\s+when(\s+|\s*\()((\s*&&\s*|\s*and\s+|\s*\|\|\s*|\s*or\s+|\s*xor\s+|\s*)(\()*([^;]+?)\s*(===|==|=|!==|!=|is\s*not|is|>|>=|<|<=|<>|contains\s*exactly|contains|~|regexp|regex|like|ilike|rlike|not\s*in|in)\s*"(.*?)"\s*(if\s*set){0,1}\s*(\))*)*(\s*' . preg_quote($this->core->ease_block_start, '/') . '\s*' . preg_quote($this->core->global_reference_start, '/') . '.*' . preg_quote($this->core->global_reference_end, '/') . '\s*' . preg_quote($this->core->ease_block_end, '/') . '\s*)*;\s*/is',
						function($matches) use (&$where_sql_string, &$existing_columns, &$existing_relation_columns, &$referenced_columns, $sql_table_name) {
							if($this->core->db && !$this->core->db_disabled) {
								if(isset($matches[10]) && trim($matches[10])!='') {
									$this->interpreter->inject_global_variables($matches[0]);
								}
								if($where_sql_string!='') {
									$where_sql_string .= ' AND ';
								}
								$where_sql_string .= '(';
								preg_match('/\s*include\s+when\s*(.*);\s*/is', $matches[0], $matches);
								$remaining_condition = $matches[1];
								while(preg_match('/^(&&|&|\|\||and\s+|or\s+|xor\s+){0,1}\s*(!|not\s+){0,1}\s*([(\s]*)([^;]+?)\s*(===|==|=|!==|!=|>|>=|<|<=|<>|is\s*not|is|contains\s*exactly|contains|~|regexp|regex|like|ilike|rlike|not\s*in|in)\s*"(.*?)"\s*(if\s*set){0,1}([)\s]*)/is', $remaining_condition, $inner_matches)) {
									$this->interpreter->inject_global_variables($inner_matches[4]);
									$table_column_parts = explode('.', $inner_matches[4], 2);
									if(count($table_column_parts)==2) {
										$table = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($table_column_parts[0])), '_');
										$column = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($table_column_parts[1])), '_');
									} else {
										$table = $sql_table_name;
										$column = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($inner_matches[4])), '_');
									}
									if($column=='id') {
										$column = 'uuid';
									}
									if($table==$sql_table_name) {
										$referenced_columns[$column] = true;
									}
									$namespaced_table = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $table), '_');
									$cleansed_comparator = strtolower(preg_replace('/\s+/s', ' ', trim($inner_matches[5])));
									switch($cleansed_comparator) {
										case '==':
										case '===':
										case 'is':
											$inner_matches[5] = '=';
											break;
										case '!=':
										case '!==':
										case 'is not':
											$inner_matches[5] = '<>';
											break;
										case 'like':
										case 'contains exactly':
											$inner_matches[5] = 'LIKE';
											break;
										case 'contains':
										case 'ilike':
											$inner_matches[5] = 'contains';
											break;
										case '~':
										case 'regex':
										case 'regexp':
											$inner_matches[5] = 'REGEXP';
											break;
										default:
									}
									$this->interpreter->inject_global_variables($inner_matches[6]);
									if(strtolower(preg_replace('/\s+/s', ' ', trim($inner_matches[7])))=='if set' && trim($inner_matches[6])=='') {
										// the term contained an IF SET directive, and the comparison value was not set, so never filter based on this term
										// the term will be replaced in the SQL WHERE clause with the boolean value TRUE, maintaining any grouped conditional logic
										$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] TRUE $inner_matches[8] ";
									} elseif(($table==$sql_table_name && @in_array($column, $existing_columns)) || @in_array($column, $existing_relation_columns[$table])) {
										// the term contained a valid column name to compare
										// add the comparison to the SQL WHERE clause, maintaining any grouped conditional logic
										if($cleansed_comparator=='in' || $cleansed_comparator=='not in') {
											$value = '';
											foreach(explode(',', $inner_matches[6]) as $sql_list_item) {
												if($value!='') {
													$value .= ',';
												}	
												$value .= $this->core->db->quote(trim($sql_list_item));
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] `$namespaced_table`.`$column` $cleansed_comparator ($value) $inner_matches[8] ";
										} elseif($inner_matches[5]=='contains') {
											$value = $this->core->db->quote('%' . $inner_matches[6] . '%');
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] UPPER(`$namespaced_table`.`$column`) LIKE UPPER($value) $inner_matches[8] ";
										} else {
											if(preg_match('/^\s*(-|)[0-9\s\.,]+$/is', $inner_matches[6], $number_matches) && preg_match('/^(=|<>|>|>=|<|<=)$/', $inner_matches[5], $comparitor_matches)) {
												$value = $inner_matches[6];
											} else {
												$value = $this->core->db->quote($inner_matches[6]);
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] `$namespaced_table`.`$column` $inner_matches[5] $value $inner_matches[8] ";
										}
									} else {
										// the term contained an invalid column name to compare
										// add the comparison to the SQL WHERE clause but treat the column value as a blank string, maintaining any grouped conditional logic
										if($cleansed_comparator=='in' || $cleansed_comparator=='not in') {
											$value = '';
											foreach(explode(',', $inner_matches[6]) as $sql_list_item) {
												if($value!='') {
													$value .= ',';
												}	
												$value .= $this->core->db->quote(trim($sql_list_item));
											}
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' $cleansed_comparator ($value) $inner_matches[8] ";
										} elseif($inner_matches[5]=='contains') {
											$value = $this->core->db->quote('%' . $inner_matches[6] . '%');
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' LIKE UPPER($value) $inner_matches[8] ";
										} else {
											$value = $this->core->db->quote($inner_matches[6]);
											$where_sql_string .= "$inner_matches[1] $inner_matches[2] $inner_matches[3] '' $inner_matches[5] $value $inner_matches[8] ";
										}
									}
									$remaining_condition = substr($remaining_condition, strlen($inner_matches[0]));
								}
								$where_sql_string .= ')';
							}
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// HIDE PAGERS / SHOW PAGERS
					$pagers = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*hide\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_checklist_block
					);
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*hide\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'hide';
							return '';
						},
						$unprocessed_start_checklist_block
					);
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*show\s+pager\s+(.*?)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_checklist_block
					);
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*show\s+(.*?)\s+pager(s|)\s*;\s*/is',
						function($matches) use (&$pagers) {
							$pagers[strtolower($matches[1])] = 'show';
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// SHOW *NUMBER* ROWS PER PAGE
					$rows_per_page = null;
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*show\s*([0-9]+)\s*rows\s*per\s*page\s*;\s*/is',
						function($matches) use (&$rows_per_page) {
							$rows_per_page = $matches[1];
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// SET *LOCAL-VARIABLE* TO TOTAL OF *SQL-TABLE-COLUMN*
					$total_var_by_column = array();
					$page_total_var_by_column = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*set\s+([^;]+?)\s+to(\s+page|)\s+total\s+of\s+([^;]+)\s*;\s*/is',
						function($matches) use (&$total_var_by_column, &$page_total_var_by_column, &$referenced_columns, $sql_table_name) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[3]);
							$table_column_parts = explode('.', $matches[3], 2);
							if(count($table_column_parts)==2) {
								$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
							} else {
								$bucket = $sql_table_name;
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($matches[3]));
							}
							if($bucket==$sql_table_name) {
								$referenced_columns[$key] = true;
							}
							if(strtolower(trim($matches[2]))=='page') {
								$page_total_var_by_column["$bucket.$key"] = $matches[1];
							} else {
								$total_var_by_column["$bucket.$key"] = $matches[1];
							}
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// SORT BY *SQL-TABLE-COLUMN* ASCENDING / DESCENDING
					$order_by_sql_string = '';
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*(sort|order)\s+by\s+([^;]+)\s*;\s*/is',
						function($matches) use (&$order_by_sql_string, &$sql_table_name) {
							$this->interpreter->inject_global_variables($matches[2]);
							$sort_by_directives	= explode(',', $matches[2]);
							foreach($sort_by_directives as $sort_by_directive) {
								$context_stack = $this->interpreter->extract_context_stack($sort_by_directive);
								$sort_by_directive = preg_replace('/[^a-z0-9_`,\*\. ]+/is', '', strtolower($sort_by_directive));
								$sort_by_directive = preg_replace('/in\s+descending\s+order/is', 'DESC', $sort_by_directive);
								$sort_by_directive = preg_replace('/descending\s+order/is', 'DESC', $sort_by_directive);
								$sort_by_directive = preg_replace('/descending/is', 'DESC', $sort_by_directive);
								$sort_by_directive = preg_replace('/in\s+ascending\s+order/is', 'ASC', $sort_by_directive);
								$sort_by_directive = preg_replace('/ascending\s+order/is', 'ASC', $sort_by_directive);
								$sort_by_directive = preg_replace('/ascending/is', 'ASC', $sort_by_directive);
								$table_column_parts = explode('.', $sort_by_directive, 2);
								if(count($table_column_parts)==1) {
									// the table name was not included, default to the main list table
									$sort_by_directive = $sql_table_name . '.' . $sort_by_directive;
								}
								if(count($context_stack) > 0) {
									if(preg_match('/^\s*(.*?)\s+desc\s*$/is', $sort_by_directive, $inner_matches)) {
										$sort_by_directive = $inner_matches[1];
										$order_type = 'DESC';
									} else {
										if(preg_match('/^\s*(.*?)\s+asc\s*$/is', $sort_by_directive, $inner_matches)) {
											$sort_by_directive = $inner_matches[1];
										}
										$order_type = 'ASC';
									}
									foreach($context_stack as $context) {
										switch($context) {
											case 'dollars':
												$sort_by_directive = "CAST(replace(replace($sort_by_directive, '\$', ''), ',', '') AS decimal) $order_type";
												break;
											case 'integer':
											case 'int':
											case 'number':
											case 'numeric':
											case 'numerical':
											case 'decimal':
											case 'float':
											case 'long':
												$sort_by_directive = "CAST($sort_by_directive AS decimal) $order_type";
												break;
											default:
											$sort_by_directive = "CAST($sort_by_directive AS " . strtoupper($context) . ") $order_type";
										}
									}
								}
								if($order_by_sql_string) {
									$order_by_sql_string .= ", $sort_by_directive ";
								} else {
									$order_by_sql_string = " ORDER BY $sort_by_directive ";
								}
							}
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// WHEN *ACTION* REDIRECT TO "QUOTED-STRING"
					$redirect_to_by_action = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*when\s+([\w ]+?)\s+redirect\s+to\s+"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$redirect_to_by_action) {
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $matches[1]);
							}
							$this->interpreter->inject_global_variables($matches[2]);
							$redirect_to_by_action[$action] = $matches[2];
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// WHEN *ACTION* SET *EASE-VARIABLE* TO "QUOTED-STRING"
					$set_to_list_by_action = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*when\s+([\w ]+?)\s+set\s+([^;]+?)\s+to\s*"(.*?)"\s*;\s*/is',
						function($matches) use (&$set_to_list_by_action) {
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $matches[1]);
							}
							$this->interpreter->inject_global_variables($matches[2]);
							$this->interpreter->inject_global_variables($matches[3]);
							$set_to_list_by_action[$action][$matches[2]] = $matches[3];
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// WHEN *ACTION* SET *EASE-VARIABLE* TO *MATH-EXPRESSION*
					$calculate_list_by_action = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*when\s+([\w ]+?)\s+set\s+([^;]+?)\s+to\s*(.*?)\s*;\s*/is',
						function($matches) use (&$calculate_list_by_action) {
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $matches[1]);
							}
							$this->interpreter->inject_global_variables($matches[2]);
							$this->interpreter->inject_global_variables($matches[3]);
							$calculate_list_by_action[$action][$matches[2]] = $matches[3];
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// WHEN *ACTION* CALL *JS-FUNCTION*
					$button_action_call_list_by_action = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*when\s+([\w ]+?)\s+call\s+(.*?)\s*;\s*/is',
						function($matches) use (&$button_action_call_list_by_action) {
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $matches[1]);
							}
							$this->interpreter->inject_global_variables($matches[2]);
							$button_action_call_list_by_action[$action] = $matches[2];
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// WHEN *ACTION* SEND EMAIL
					$send_email_list_by_action = array();
					$unprocessed_start_checklist_block = preg_replace_callback(
						'/\s*when\s+([\w ]+?)\s+send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is',
						function($matches) use (&$send_email_list_by_action) {
							$unprocessed_send_email_block = $matches[0];
							$send_email_attributes = array();
							$unprocessed_send_email_block = preg_replace('/^\s*when\s+([\w ]+?)\s+send\s+email\s*;/is', '', $unprocessed_send_email_block);

							// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
							$unprocessed_send_email_block = preg_replace_callback(
								'/([a-z_]*)\s*=\s*"""(.*?)\v\s*"""\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);

							// *EMAIL-ATTRIBUTE* = "quoted"
							$unprocessed_send_email_block = preg_replace_callback(
								'/\s*([a-z_]*)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
								function($matches) use (&$send_email_attributes) {
									$this->interpreter->inject_global_variables($matches[2]);
									$send_email_attributes[strtolower($matches[1])] = $matches[2];
									return '';
								},
								$unprocessed_send_email_block
							);

							// build the email message and headers according to the type
							$mail_options = array();
							if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
								// parse the bodypage using any supplied HTTP ?query string
								$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
								if(count($send_email_espx_body_url_parts)==2) {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
									$send_email_url_params = array();
									parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
								} else {
									$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
									$send_email_url_params = null;
								}
								$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
								$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
								$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
								$send_email_page_parser = null;
							}
							if(isset($send_email_attributes['from_name'])) {
								$mail_options['sender'] = $send_email_attributes['from_name'];
							}
							if(isset($send_email_attributes['to'])) {
								$mail_options['to'] = $send_email_attributes['to'];
							}
							if(isset($send_email_attributes['cc'])) {
								$mail_options['cc'] = $send_email_attributes['cc'];
							}
							if(isset($send_email_attributes['bcc'])) {
								$mail_options['bcc'] = $send_email_attributes['bcc'];
							}
							if(isset($send_email_attributes['subject'])) {
								$mail_options['subject'] = $send_email_attributes['subject'];
							}
							if(@$send_email_attributes['type']=='html') {
								$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
							} else {
								$mail_options['textBody'] = (string)$send_email_attributes['body'];
							}
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $matches[1]);
							}
							$send_email_list_by_action[$action][] = $mail_options;
							return '';
						},
						$unprocessed_start_checklist_block
					);

					// continue processing the remaining START Block as long CHECKLIST FORM directives are found at the beginning
					$create_sql_record_list_by_action = array();
					$update_sql_record_list_by_action = array();
					$delete_sql_record_list_by_action = array();
					$create_spreadsheet_row_list_by_action = array();
					$update_spreadsheet_row_list_by_action = array();
					$delete_spreadsheet_row_list_by_action = array();
					$additional_form_attributes = '';
					do {
						$checklist_directive_found = false;
						// WHEN *ACTION* CREATE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+([\w ]+?)\s+create\s+(new\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_checklist_block, $checklist_directive_matches)) {
							$checklist_directive_found = true;
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $checklist_directive_matches[1]);
							}
							$create_sql_record = array();
							$this->interpreter->inject_global_variables($checklist_directive_matches[3]);
							$create_sql_record['for'] = strtolower($checklist_directive_matches[3]);
							$this->interpreter->inject_global_variables($checklist_directive_matches[7]);
							$create_sql_record['as'] = strtolower($checklist_directive_matches[7]);
							$unprocessed_create_sql_record_block = $checklist_directive_matches[8];
							do {
								$create_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+(.*?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[2]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($create_sql_record_directive_matches[1]);
									}
									$create_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+(.*?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($create_sql_record_directive_matches[1]);
									$name_parts = explode('.', $create_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($create_sql_record_directive_matches[1]));
									}
									$create_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$create_sql_record_directive_matches[2]
									);
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_create_sql_record_block, $create_sql_record_directive_matches)) {
									$create_sql_record_directive_found = true;
									$unprocessed_create_sql_record_block = substr($unprocessed_create_sql_record_block, strlen($create_sql_record_directive_matches[0]));
								}
							} while($create_sql_record_directive_found);
							$create_sql_record_list_by_action[$action][] = $create_sql_record;
							$unprocessed_start_checklist_block = $unprocessed_create_sql_record_block;
						}
						// WHEN *ACTION* UPDATE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+([\w ]+?)\s+update\s+(old\s+|existing\s+|)record\s+for\s*"\s*(.*?)\s*"((\s*and|)(\s*reference|)\s+as\s*"\s*(.*?)\s*"|)\s*;(.*)$/is', $unprocessed_start_checklist_block, $checklist_directive_matches)) {
							$checklist_directive_found = true;
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $checklist_directive_matches[1]);
							}
							$update_sql_record = array();
							$this->interpreter->inject_global_variables($checklist_directive_matches[3]);
							$update_sql_record['for'] = strtolower($checklist_directive_matches[3]);
							$this->interpreter->inject_global_variables($checklist_directive_matches[7]);
							$update_sql_record['as'] = strtolower($checklist_directive_matches[7]);
							$unprocessed_update_sql_record_block = $checklist_directive_matches[8];
							do {
								$update_sql_record_directive_found = false;
								// SET TO
								if(preg_match('/^\s*set\s+([^;]+?)\s+to\s+("\s*(.*?)\s*"|(.*?))\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[2]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower($update_sql_record_directive_matches[1]);
									}
									$update_sql_record['set_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'value'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
								}
								// ROUND TO x DECIMALS
								if(preg_match('/^\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$this->interpreter->inject_global_variables($update_sql_record_directive_matches[1]);
									$name_parts = explode('.', $update_sql_record_directive_matches[1], 2);
									if(count($name_parts)==2) {
										$bucket = strtolower(rtrim($name_parts[0]));
										$key = strtolower(ltrim($name_parts[1]));
									} else {
										$bucket = '';
										$key = strtolower(trim($update_sql_record_directive_matches[1]));
									}
									$update_sql_record['round_to_commands'][] = array(
										'bucket'=>$bucket,
										'key'=>$key,
										'decimals'=>$update_sql_record_directive_matches[2]
									);
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
								}
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_update_sql_record_block, $update_sql_record_directive_matches)) {
									$update_sql_record_directive_found = true;
									$unprocessed_update_sql_record_block = substr($unprocessed_update_sql_record_block, strlen($update_sql_record_directive_matches[0]));
								}
							} while($update_sql_record_directive_found);
							$update_sql_record_list_by_action[$action][] = $update_sql_record;
							$unprocessed_start_checklist_block = $unprocessed_update_sql_record_block;
						}
						// WHEN *ACTION* DELETE RECORD FOR SQL TABLE
						if(preg_match('/^\s*when\s+([\w ]+?)\s+delete\s+record\s+for\s*"\s*(.*?)\s*"\s*;(.*)$/is', $unprocessed_start_checklist_block, $checklist_directive_matches)) {
							$checklist_directive_found = true;
							if(preg_match('/(^checked\s*and\s*(.*)|(.*?)\s*and\s*checked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-checked';
							} elseif(preg_match('/(^unchecked\s*and\s*(.*)|(.*?)\s*and\s*unchecked$)/is', $checklist_directive_matches[1], $action_matches)) {
								$action = preg_replace('/\s+/s', '', @$action_matches[2] . @$action_matches[3]) . '-unchecked';
							} else {
								$action = preg_replace('/\s+/s', '', $checklist_directive_matches[1]);
							}
							$delete_sql_record = array();
							$this->interpreter->inject_global_variables($checklist_directive_matches[2]);
							$delete_sql_record['for'] = strtolower($checklist_directive_matches[2]);
							$unprocessed_delete_sql_record_block = $checklist_directive_matches[3];
							do {
								$delete_sql_record_directive_found = false;
								// GO TO NEXT RECORD - remove these references required by the python core, as they aren't required for the PHP version
								if(preg_match('/^\s*go\s*to\s*next\s*record\s*;\s*/is', $unprocessed_delete_sql_record_block, $delete_sql_record_directive_matches)) {
									$delete_sql_record_directive_found = true;
									$unprocessed_delete_sql_record_block = substr($unprocessed_delete_sql_record_block, strlen($delete_sql_record_directive_matches[0]));
								}
							} while($delete_sql_record_directive_found);
							$delete_sql_record_list_by_action[$action][] = $delete_sql_record;
							$unprocessed_start_checklist_block = $unprocessed_delete_sql_record_block;
						}
						// SET FORM ATTRIBUTE
						if(preg_match('/^\s*set\s+form\s*\.\s*([^;]+?)\s*to\s*"(.*?)"\s*;(.*)$/is', $unprocessed_start_checklist_block, $checklist_directive_matches)) {
							$checklist_directive_found = true;
							$this->interpreter->inject_global_variables($checklist_directive_matches[1]);
							$this->interpreter->inject_global_variables($checklist_directive_matches[2]);
							$additional_form_attributes .= " {$checklist_directive_matches[1]}=\"{$checklist_directive_matches[2]}\"";
							$unprocessed_start_checklist_block = $checklist_directive_matches[3];
							continue;
						}

						// !! ANY NEW CHECKLIST FORM - FOR SQL TABLE DIRECTIVES GET ADDED HERE

					} while($checklist_directive_found);

					// if the START Block has any content remaining, there was an unrecognized CHECKLIST FORM directive, so log a parse error
					$this->interpreter->remove_comments($unprocessed_start_checklist_block);
					if(trim($unprocessed_start_checklist_block)!='') {
						// ERROR! CHECKLIST FORM with an unrecognized directive... log the block and don't attempt to process it further
						if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(.*?)' . preg_quote($this->core->ease_block_end, '/') . '/s', $this->unprocessed_body, $matches)) {
							$error = $matches[0];
						} else {
							$error = $this->unprocessed_body;
						}
						$this->errors[] = $error;
						$this->unprocessed_body = substr($this->unprocessed_body, strlen($error));
						return;
					}

					// generate a form ID, and store information about the form in the user session
					$form_id = $this->core->new_uuid();
					$_SESSION['ease_forms'][$form_id]['created_on'] = time();
					@$_SESSION['ease_forms'][$form_id]['sql_table_name'] = $sql_table_name;
					@$_SESSION['ease_forms'][$form_id]['namespaced_sql_table_name'] = $namespaced_sql_table_name;
					@$_SESSION['ease_forms'][$form_id]['set_to_list_by_action'] = $set_to_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['calculate_list_by_action'] = $calculate_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['send_email_list_by_action'] = $send_email_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['create_sql_record_list_by_action'] = $create_sql_record_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['update_sql_record_list_by_action'] = $update_sql_record_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['delete_sql_record_list_by_action'] = $delete_sql_record_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['create_spreadsheet_row_list_by_action'] = $create_spreadsheet_row_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['update_spreadsheet_row_list_by_action'] = $update_spreadsheet_row_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['delete_spreadsheet_row_list_by_action'] = $delete_spreadsheet_row_list_by_action;
					@$_SESSION['ease_forms'][$form_id]['redirect_to_by_action'] = $redirect_to_by_action;

					// remove the START Block from the unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, strlen($start_checklist_block));

					// find the END CHECKLIST FORM block and parse out the CHECKLIST FORM body
					if(preg_match('/(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*check\s*list(\s*form|)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
						// END CHECKLIST FORM found, parse out the CHECKLIST FORM body
						$list_body = $matches[1];
						$list_length = strlen($matches[0]);
					} else {
						// END CHECKLIST FORM not found... treat the entire remaining unprocessed body as the CHECKLIST FORM body
						$list_body = $this->unprocessed_body;
						$list_length = strlen($list_body);
					}

					// inject global variable values into the CHECKLIST FORM body
					$this->interpreter->inject_global_variables($list_body);

					// process INPUT tags in the CHECKLIST body with an EASE tag attribute
					$list_body = preg_replace_callback(
						'/<\s*input\s+((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*+\s*)' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '((\s*(\w+)\s*=\s*(\'(\\\\\\\\|\\\\\'|[^\'])*\'|"(\\\\\\\\|\\\\"|[^"])*"|(\w+))|\s*(\w+))*\s*)(\/\s*|)>/is',
						function($matches) use (&$form_id, &$button_action_call_list_by_action) {
							// an EASE tag was found as an HTML INPUT tag attribute
							$input_ease_reference = $matches[9];
							// process all of the HTML INPUT tag attributes (other than the EASE tag)
							$input_attributes = "{$matches[1]} {$matches[10]}";
							$input_attributes_by_key = array();
							preg_match_all('/\s*(\w+)\s*=\s*((\')((\\\\\\\\|\\\\\'|[^\'])*)\'|(")((\\\\\\\\|\\\\"|[^"])*)"|(\w+))|\s*(\w+)/is', $input_attributes, $input_attribute_matches);
							foreach($input_attribute_matches[1] as $key=>$value) {
								if(trim($value)!='') {
									// input attribute assigned a value with =
									$input_attribute_key = strtolower($value);
									if($input_attribute_matches[3][$key]=="'") {
										// the value was wrapped in single quotes
										$input_attributes_by_key[$input_attribute_key] = array(
											'quote'=>"'",
											'value'=>$input_attribute_matches[4][$key]
										);
									} elseif($input_attribute_matches[6][$key]=='"') {
										// the value was wrapped in double quotes
										$input_attributes_by_key[$input_attribute_key] = array(
											'quote'=>'"',
											'value'=>$input_attribute_matches[7][$key]
										);
									} else {
										// the value was not wrapped in quotes
										$input_attributes_by_key[$input_attribute_key] = array(
											'quote'=>'',
											'value'=>$input_attribute_matches[9][$key]
										);
									}
								} else {
									// input attribute with no assigned value
									$input_attribute_key = strtolower($input_attribute_matches[10][$key]);
									$input_attributes_by_key[$input_attribute_key] = array(
										'quote'=>'',
										'value'=>''
									);
								}
							}
							$input_attributes_by_key['type']['value'] = strtolower($input_attributes_by_key['type']['value']);
							// process the INPUT tag by type
							switch($input_attributes_by_key['type']['value']) {
								case 'checkbox':
									$input_ease_reference = preg_replace('/\s+/s', ' ', strtolower($input_ease_reference));
									if($input_ease_reference=='checklist item') {
										$input_attributes_by_key['name'] = array(
											'quote'=>'"',
											'value'=>"ease_checklist_item_<# id #>"
										);
									}
									if(!isset($input_attributes_by_key['value'])) {
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>'checked'
										);
									}
									break;
								case 'submit':
								case 'button':
									// process the button name to determine the type
									preg_match('/(.*?)\s*button/is', $input_ease_reference, $button_matches);
									$button_reference = preg_replace('/[^a-z0-9\._-]+/is', '', strtolower($button_matches[1]));
									$input_attributes_by_key['name'] = array(
										'quote'=>'"',
										'value'=>"button_{$button_reference}"
									);
									// this is a custom form action
									$input_attributes_by_key['type'] = array(
										'quote'=>'"',
										'value'=>'submit'
									);
									if(isset($button_action_call_list_by_action[$button_reference])) {
										$input_attributes_by_key['onclick'] = array(
											'quote'=>'"',
											'value'=>'return ' . htmlspecialchars($button_action_call_list_by_action[$button_reference])
										);
									}
									// if a value wasn't set for the button, default it to the custom action name
									if((!isset($input_attributes_by_key['value']['value'])) || trim($input_attributes_by_key['value']['value'])=='') {
										$input_attributes_by_key['value'] = array(
											'quote'=>'"',
											'value'=>$button_matches[1]
										);
									}
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['action'] = $button_reference;
									$_SESSION['ease_forms'][$form_id]['buttons']["button_{$button_reference}"]['handler'] = 'process_checklist';
									break;
							}
							// replace the original HTML INPUT tag with the processed attributes
							$input_attributes_string = '';
							foreach($input_attributes_by_key as $key=>$value) {
								$input_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
							}
							return "<input $input_attributes_string/>";
						},
						$list_body
					);
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*checklist\s*item\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$form_id) {
							$input_attributes_by_key = array();
							$input_attributes_by_key['type'] = array(
								'quote'=>'"',
								'value'=>'checkbox'
							);
							$input_attributes_by_key['name'] = array(
								'quote'=>'"',
								'value'=>"ease_checklist_item_<# id #>"
							);
							$input_attributes_by_key['value'] = array(
								'quote'=>'"',
								'value'=>'checked'
							);
							// replace the original HTML INPUT tag with the processed attributes
							$input_attributes_string = '';
							foreach($input_attributes_by_key as $key=>$value) {
								$input_attributes_string .= "$key={$value['quote']}{$value['value']}{$value['quote']} ";
							}
							return "<input $input_attributes_string/>";
						},
						$list_body
					);

					// parse out the CHECKLIST FORM HEADER template
					$list_header_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+header\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*header\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_header_template) {
							$list_header_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_header_template_tokenized_ease = $this->string_to_tokenized_ease($list_header_template);

					// parse out the CHECKLIST FORM ROW template
					$list_row_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+row\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*row\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_row_template) {
							$list_row_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_row_template_tokenized_ease = $this->string_to_tokenized_ease($list_row_template);

					// parse out the CHECKLIST FORM FOOTER template
					$list_footer_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*start\s+footer\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*footer\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_footer_template) {
							$list_footer_template = $matches[1];
							return '';
						},
						$list_body
					);
					$list_footer_template_tokenized_ease = $this->string_to_tokenized_ease($list_footer_template);

					// parse out the CHECKLIST FORM NO RESULTS template
					$list_no_results_template = '';
					$list_body = preg_replace_callback(
						'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(start\s+|)no\s+results\s*' . preg_quote($this->core->ease_block_end, '/') . '(.*?)' . preg_quote($this->core->ease_block_start, '/') . '\s*end\s*no\s*results\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
						function($matches) use (&$list_no_results_template) {
							$list_no_results_template = $matches[2];
							return '';
						},
						$list_body
					);
					$list_no_results_template_tokenized_ease = $this->string_to_tokenized_ease($list_no_results_template);

					// inject anything left in the CHECKLIST FORM body to the output buffer
					if(isset($save_to_global_variable)) {
						$this->core->globals[$save_to_global_variable] .= trim($list_body);
					} else {
						$this->output_buffer .= trim($list_body);
					}

					// remove the list body and the END CHECKLIST FORM block from the remaining unprocessed body
					$this->unprocessed_body = substr($this->unprocessed_body, $list_length);

					if($this->core->db && !$this->core->db_disabled) {
						// determine the columns referenced in the list body
						preg_match_all('/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $list_row_template, $matches);
						foreach($matches[1] as $table_column) {
							// extract the context stack from the SQL Table Column name
							$context_stack = $this->interpreter->extract_context_stack($table_column);
							$table_column_parts = explode('.', $table_column, 2);
							if(count($table_column_parts)==2) {
								$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($table_column_parts[0])));
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($table_column_parts[1])));
							} else {
								$bucket = $sql_table_name;
								$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower($table_column));
							}
							if($key=='id') {
								$key = 'uuid';
							}
							if($bucket==$sql_table_name) {
								$referenced_columns[$key] = true;
							}
							$context_stack_by_column[$key] = $context_stack;
						}
						$referenced_columns_csv = "`" . implode("`,`", array_keys($referenced_columns)) . "`";
						if($where_sql_string) {
							$where_sql_string = "WHERE $where_sql_string";
						}
						if($order_by_sql_string=='') {
							$order_by_sql_string = " ORDER BY `$namespaced_sql_table_name`.created_on ASC ";
						}
						// store the SQL query strings in the session so a query can be reconstructed by the form handler
						@$_SESSION['ease_forms'][$form_id]['join_sql_string'] = $join_sql_string;
						@$_SESSION['ease_forms'][$form_id]['where_sql_string'] = $where_sql_string;
						@$_SESSION['ease_forms'][$form_id]['order_by_sql_string'] = $order_by_sql_string;
						// query for the list data
						$query = "SELECT $select_clause_sql_string FROM `$namespaced_sql_table_name` $join_sql_string $where_sql_string $order_by_sql_string;";
						$list_rows = array();
						if($result = $this->core->db->query($query)) {
							$list_rows = $result->fetchAll(PDO::FETCH_ASSOC);
						} else {
							// the query failed...
							$db_error = $this->core->db->errorInfo();
						}

						$process_params = array();
						$process_params['sql_table_name'] = $sql_table_name;
						$process_params['row'] = array();

						// check if the CHECKLIST FORM has any results
						$this->local_variables['numberofrows'] = count($list_rows);
						if($this->local_variables['numberofrows'] > 0) {
							// CHECKLIST FORM has results, initialize row buffer
							$row = array();
							// inject the start tag for an HTML form in the output buffer
							$this->output_buffer .= "<form method='post' action='{$this->core->service_endpoints['form']}' enctype='multipart/form-data'{$additional_form_attributes}>\n";
							$this->output_buffer .= "<input type='hidden' id='ease_form_id' name='ease_form_id' value='$form_id' />\n";
							// process any pager settings to determine if pagers should be shown
							if(strlen(@$_REQUEST['index'])==32) {
								// the page index was requested as the UUID of a record expected to be in the list
								// show the page that includes the referenced record
								$indexed_page = null;
								foreach($list_rows as $row_key=>$row) {
									if($row[$sql_table_name . '.uuid']==$_REQUEST['index']) {
										$indexed_page = ceil(($row_key + 1) / $rows_per_page);
										break;
									}
								}
								reset($list_rows);
							} else {
								$indexed_page = intval(@$_REQUEST['index']);
								if(($indexed_page * $rows_per_page) > $this->local_variables['numberofrows'] || @$_REQUEST['index']=='last') {
									// the requested page is past the end of the list... default to the last page
									$indexed_page = ceil($this->local_variables['numberofrows'] / $rows_per_page);
								} elseif($indexed_page==0 || $rows_per_page==0) {
									$indexed_page = 1;
								}
							}
							if((!isset($indexed_page)) || !$indexed_page) {
								$indexed_page = 1;
							}
							// remove the page index from the query string in the REQUEST URI
							$url_parts = parse_url($_SERVER['REQUEST_URI']);
							$query_string_no_index = preg_replace('/(^index=[a-z0-9]+(&|$)|&index=[a-z0-9]+)/is', '', @$url_parts['query']);
							$show_pagers = array();
							$pager_html = '';
							if(is_array($pagers)) {
								foreach($pagers as $type=>$value) {
									if($type=='both') {
										if($value=='show') {
											$show_pagers['top'] = true;
											$show_pagers['bottom'] = true;
										} else {
											unset($show_pagers['top']);
											unset($show_pagers['bottom']);
										}
									} else {
										if($value=='show') {
											$show_pagers[$type] = true;
										} else {
											unset($show_pagers[$type]);
										}
									}
								}
							}
							$indexed_page_start = 1;
							$indexed_page_end = $this->local_variables['numberofrows'];
							if($rows_per_page > 0) {
								// a rows per page value was set;  determine if any pagers should be defaulted to show
								if(count($pagers)==0) {
									// no pagers were explicitly shown or hidden; default to showing both pagers
									$show_pagers['top'] = true;
									$show_pagers['bottom'] = true;
								} elseif(!isset($pagers['both'])) {
									// a value wasn't set for 'both' pagers; if either 'top' or 'bottom' values weren't set, default to show
									if(!isset($pagers['top'])) {
										$show_pagers['top'] = true;
									}
									if(!isset($pagers['bottom'])) {
										$show_pagers['bottom'] = true;
									}
								}
								// calculate the row range for the indexed page
								$indexed_page_start = (($indexed_page - 1) * $rows_per_page) + 1;
								$indexed_page_end = $indexed_page_start + ($rows_per_page - 1);
							}
							if(count($show_pagers) > 0) {
								// pagers will be shown... include the default pager style once per request
								$this->inject_pager_style();
								// build the pager html
								$pager_html = $this->build_pager_html($rows_per_page, $this->local_variables['numberofrows'], $indexed_page, $query_string_no_index);
								// show the top pager if set
								if(isset($show_pagers['top'])) {
									$this->output_buffer .= $pager_html;
								}
							}

							// process the HEADER template
							$this->process_tokenized_ease($list_header_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}

							// apply the ROW template for each data row and inject the results into the output buffer
							$this->local_variables['rownumber'] = 0;
							$update_sql_columns_already_added = array();
							$update_sql_columns_already_added_by_table = array();
							foreach($list_rows as $row_key=>$row) {
								$process_params['row'] = &$row;
								$this->local_variables['row'] = &$row;
								$this->local_variables['rownumber']++;
								// apply any TOTAL directives from the START Block
								if(count($total_var_by_column) > 0) {
									foreach($total_var_by_column as $total_column=>$total_var) {
										$total_column_parts = explode('.', $total_column, 2);
										$total_var_parts = explode('.', $total_var, 2);
										if(count($total_var_parts)==2) {
											if(($total_var_parts[0]==$sql_table_name || $total_var_pars[0]=='row') && isset($row[$total_column])) {
												$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/s', '', $row[$total_column]);
												$row["$sql_table_name.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
											}
										} else {
											@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/is', '', $row[$total_column]);
											$row["$sql_table_name.$total_var"] = $totals_by_var[$total_column];
										}
									}
								}
								// if this is a paged list, skip rows outside of the indexed page range
								if($rows_per_page > 0) {
									if(($this->local_variables['rownumber'] < $indexed_page_start) || ($this->local_variables['rownumber'] > $indexed_page_end)) {
										continue;
									} else {
										// apply any TOTAL directives from the START Block
										if(count($page_total_var_by_column) > 0) {
											foreach($page_total_var_by_column as $total_column=>$total_var) {
												$total_column_parts = explode('.', $total_column, 2);
												$total_var_parts = explode('.', $total_var, 2);
												if(count($total_var_parts)==2) {
													if(($total_var_parts[0]==$sql_table_name || $total_var_pars[0]=='row') && isset($row[$total_column])) {
														$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/s', '', $row[$total_column]);
														$row["$sql_table_name.{$total_var_parts[1]}"] = $totals_by_var[$total_column];
													}
												} else {
													@$totals_by_var[$total_column] += preg_replace('/[^0-9\.-]+/is', '', $row[$total_column]);
													$row["$sql_table_name.$total_var"] = $totals_by_var[$total_column];
												}
											}
										}
									}
								}
								// process the ROW template
								$this->process_tokenized_ease($list_row_template_tokenized_ease, $process_params);
								if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
									// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
									return;
								}
							}

							// process the FOOTER template
							$this->process_tokenized_ease($list_footer_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
							// show the bottom pager if set
							if(isset($show_pagers['bottom'])) {
								$this->output_buffer .= $pager_html;
							}
							// inject the end tag for the HTML form
							$this->output_buffer .= "</form>\n";
						} else {
							// CHECKLIST FORM has no results, process the NO RESULTS template
							$this->process_tokenized_ease($list_no_results_template_tokenized_ease, $process_params);
							if(isset($this->core->redirect) && trim($this->core->redirect)!='') {
								// processing the tokenized EASE resulted in a redirect that was caught... halt all further processing
								return;
							}
						}
					}
					// done processing CHECKLIST FORM for SQL Table, return to process the remaining body
					return;
				}
			}

			###############################################
			##	JSON FOR GOOGLESHEETS - only works as a standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*json\s+for\s+(google\s*|)(spread|)sheets\s*(\.|;){0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				// initialize a Google Sheets API client
				$this->core->validate_google_access_token();
				require_once 'ease/lib/Spreadsheet/Autoloader.php';
				$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
				$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
				Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
				$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
				$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
				$spreadsheets = $spreadsheetFeed->getList();
				// inject the JSON encoded list of Google Sheets
				$this->output_buffer .= json_encode($spreadsheets);
				// remove the JSON for Google Sheets block from the remaining unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				return;
			}

			###############################################
			##	SHOW PARSE ERRORS - only works as a standalone tag
			if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*show\s+parse\s+errors\s*;*\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $this->unprocessed_body, $matches)) {
				if(count($this->errors) > 0) {
					$this->output_buffer .= '<pre>EASE PARSE ERRORS: ' . htmlspecialchars(print_r($this->errors, true)) . '</pre>';
				}
				// done processing EASE block, remove it from the unprocessed body
				$this->unprocessed_body = substr($this->unprocessed_body, strlen($matches[0]));
				// return to process the remaining body
				return;
			}

			###############################################
			// a valid EASE command was not found at the start of the EASE block
			// extract the first line of the EASE block, inject any global variables then append that value to the output buffer
			// TODO!! check for code that doesn't inject any global variables, and contains the stop sequence for EASE blocks
			//  * example:    for a start list block with syntax error, the parser will hang on <# start row #> tag as it tries to process each line as EASE
			preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '(\V*)(\v*)(.*)/s', $this->unprocessed_body, $matches);
			$this->interpreter->inject_global_variables($matches[1]);
			$this->output_buffer .= $matches[1];
			// remove the line from the unprocessed body
			if((!isset($matches[3])) || trim($matches[3])=='' || trim($matches[3])==$this->core->ease_block_end) {
				// the remainder was just whitespace or empty... done processing
				$this->unprocessed_body = '';
				return;
			} else {
				// process the remainder of the EASE block
				$this->unprocessed_body = $this->core->ease_block_start . $matches[3];
				continue;
			}
		}
	}

	// this function should only be called when $this->unprocessed_body begins with a PHP start sequence
	function process_php_block() {
		// tokenize the PHP code, stopping when an end sequence for a PHP Block is found
		$tokens = token_get_all($this->unprocessed_body);
		$php_block_size = 0;
		$php_block = '';
		foreach($tokens as $current_token) {
			if(is_string($current_token)) {
				$php_block_size += strlen($current_token);
				$php_block .= $current_token;
			} else {
				$php_block_size += strlen($current_token[1]);
				if(in_array($current_token[1], $this->php_functions) && $current_token[0]===T_STRING && $previous_non_whitespace_token_type!==T_OBJECT_OPERATOR && $previous_non_whitespace_token_type!==T_DOUBLE_COLON && $previous_non_whitespace_token_type!==T_FUNCTION) {
					// the token was a function call to an EASE Framework core function
					$php_block .= '$GLOBALS[\'__ease_parser\']->' . $current_token[1];
				} else {
					$php_block .= $current_token[1];
				}
				if($current_token[0]!==T_WHITESPACE) {
					$previous_non_whitespace_token_type = $current_token[0];
				}
			}
			if(is_array($current_token) && $current_token[0]===T_CLOSE_TAG) {
				// the token was an end sequence for a PHP Block... done
				break;
			}
		}
		// remove the PHP Block from the remaining unprocessed body
		$this->unprocessed_body = substr($this->unprocessed_body, $php_block_size);
		// only evaluate the PHP Block if PHP hasn't been disabled
		if(!$this->core->php_disabled) {
			// remove the start and end sequences from the PHP Block
			$php_block = trim($php_block);
			$php_block = substr($php_block, 5);
			$php_block = substr($php_block, 0, -2);
			// evaluate the PHP Block in a localized namespace
			$GLOBALS['__ease_parser'] = &$this;
			$php_block = '$localized_php_block = function() { ' . $php_block . ' }; $localized_php_block();';
			// turn on PHP output buffering to record any output generated from evaluating the PHP Block
			ob_start();
			try {
				$eval_result = eval($php_block);
			} catch(Exception $e) {
				$this->output_buffer .= "<div style='color:red; font-weight:bold;'>PHP EXCEPTION! " . htmlspecialchars($e->getMessage()) . '</div>';
			}
			if($eval_result===false) {
				// there was an error evaluating the PHP Block
				$error = error_get_last();
				$this->output_buffer .= "<div style='color:red; font-weight:bold;'>PHP Error!  line {$error['line']}: " . htmlspecialchars($error['message']) . '</div>';
			}
			// dump any generated output to the output buffer
			$this->output_buffer .= ob_get_contents();
			// turn off output buffering... done
			ob_end_clean();			
		}
	}

	// this function will parse a string for EASE and PHP Blocks, and return a tokenized array
	public function string_to_tokenized_ease($string, $conditional_block=false) {
		// ensure an interpreter has been initiated for injecting EASE variable values and applying contexts
		if($this->interpreter===null) {
			require_once 'ease/interpreter.class.php';
			$this->interpreter = new ease_interpreter($this, $this->override_url_params);
		}
		// initialize the token array
		$tokenized_ease = array();
		// remove any multi-line EASE Comment Tags
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*:(.*?):\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) {
				return '';
			},
			$string
		);
		// check if the EASE start and end sequences were redundant   ex: <# <# ... #> #>
		if(preg_match('/^\s*' . preg_quote($this->core->ease_block_start, '/') . '\s*(' . preg_quote($this->core->ease_block_start, '/') .  '.*)' . preg_quote($this->core->ease_block_end, '/') .  '\s*$/is', $string, $matches)) {
			$string = $matches[1];
		}
		// process the remaining string by line, looking for the start sequence of EASE or PHP
		$counter = 0;
		$last_line = false;
		while(sizeof($string) > 0) {
			// a global variable injecting itself could result in an infinite loop
			// keep a counter to limit recursion
			$counter++;
			if($counter > 1000000) {
				echo 'Error!  EASE Parser recursion limit exceeded';
				exit;
			}
			// find the next end-of-line character in the remaining string
			$new_line_position = strpos($string, "\n");
			// if an end-of-line character was not found, process the last remaining line
			if($new_line_position===false) {
				$last_line = true;
				$new_line_position = strlen($string);
				if($new_line_position==0) {
					// the last line is blank
					break;
				}
			}
			// check if the line is blank
			if($new_line_position==0) {
				// the line is blank... unless this is the last line, add a print command for an end-of-line character
				if(!$last_line) {
					$tokenized_ease[] = array('print'=>"\n");
				}
				$string = substr($string, strlen("\n"));
				continue;
			}
			// parse out the first line from the remaining string
			$string_line = substr($string, 0, $new_line_position);
			// check if the line contains only whitespace
			if(trim($string_line)=='') {
				// the line contains only whitespace, add a print command for the line, appending an end-of-line character
				$tokenized_ease[] = array('print'=>$string_line . ($last_line ? '' : "\n"));
				$string = substr($string, $new_line_position + strlen("\n"));
				continue;
			}
			// scan the line for the start sequence of PHP
			while(preg_match('/(.*?)(' . preg_quote('<?php', '/') . '\s*([^\s\'"].*|$))/i', $string_line, $matches)) {
				// start sequence of PHP was found, process the preceeding text for global variable references
				// inject any global variables... if anything is injected, scan the string line again for the start sequence of EASE
				$injected = @$this->interpreter->inject_global_variables($matches[1]);
				if($injected) {
					// a global variable reference was injected into the content preceeding the start sequence of EASE
					$string_line = $matches[1] . $matches[2];
				} else {
					// no global variable EASE Tag found... check for a single line EASE Tag on the same line and before the start sequence of PHP
					if(preg_match('/(.*?)(' . preg_quote($this->core->ease_block_start, '/') . '\s*([^\s\[\'"].*|$))/i', $matches[1], $inner_matches)) {						
						// single line EASE Tag found before the PHP block... process that first
						goto scan_for_ease;
					}
					break;
				}
			}
			if(@strlen($matches[2]) > 0) {
				// the start sequence of a PHP Block was found
				if(@strlen($matches[1]) > 0) {
					// add a print command to output any text preceeding the PHP Block
					$tokenized_ease[] = array('print'=>$matches[1]);
				}
				// strip any text preceeding the start sequence of the PHP Block from the remaining unprocessed string
				$string = $matches[2] . substr($string, $new_line_position);
				// the remaining uprocessed string now begins with a PHP Block; process the PHP Block
				$php_tokens = token_get_all($string);
				// parse the PHP Block contents to find the first PHP close tag
				$php_block = '';
				$php_block_size = 0;
				$previous_non_whitespace_token_type = null;
				foreach($php_tokens as $current_token) {
					if(is_string($current_token)) {
						$php_block_size += strlen($current_token);
						$php_block .= $current_token;
						$previous_non_whitespace_token_type = null;
					} elseif(is_array($current_token)) {
						$php_block_size += strlen($current_token[1]);
						if(in_array($current_token[1], $this->php_functions) && $current_token[0]===T_STRING && $previous_non_whitespace_token_type!==T_OBJECT_OPERATOR) {
							// the token was a function call to an EASE Framework core function
							$php_block .= '$GLOBALS[\'__ease_parser\']->' . $current_token[1];
						} else {
							$php_block .= $current_token[1];
						}
						if($current_token[0]===T_CLOSE_TAG) {
							// the token was the end sequence of a PHP Block... done
							break;
						}
						if($current_token[0]!==T_WHITESPACE) {
							$previous_non_whitespace_token_type = $current_token[0];
						}
					}
				}
				// remove the start and end sequences from the PHP block, and add the PHP block to the tokenized EASE
				$php_block = trim($php_block);
				$php_block = substr($php_block, 5);
				$php_block = trim(substr($php_block, 0, -2));
				$tokenized_ease[] = array('php_block'=>$php_block);
				// remove the PHP Block from the remaining string to be tokenized
				$string = substr($string, $php_block_size);
				continue;
			}
			scan_for_ease:
			// scan the line for the start sequence of EASE
			if(!preg_match('/(.*?)(' . preg_quote($this->core->ease_block_start, '/') . '\s*([^\s\[\'"].*|$))/i', $string_line)) {
				// start sequence of EASE was not found... inject global variables then check again
				$this->interpreter->inject_global_variables($string_line);
			}
			while(preg_match('/(.*?)(' . preg_quote($this->core->ease_block_start, '/') . '\s*([^\s\[\'"].*|$))/i', $string_line, $matches)) {
				// start sequence of EASE was found, process the preceeding text for global variable references
				// inject any global variables... if anything is injected, scan the EASE string line again for the start sequence of EASE
				if(!isset($matches[1])) {
					// there was nothing before the EASE block; ready to interpret it
					break;
				}
				$injected = $this->interpreter->inject_global_variables($matches[1]);
				if($injected) {
					// a global variable reference was injected into the content preceeding the start sequence of EASE
					$string_line = $matches[1] . $matches[2];
				} else {
					// no global variable references were found; ready to interpret the EASE block
					break;
				}
			}
			if(@trim($matches[2])!='') {
				// the start sequence of EASE was found in the current string line; print any text preceeding it
				if(@strlen($matches[1]) > 0) {
					$tokenized_ease[] = array('print'=>$matches[1]);
				}
				// remove any text preceeding the EASE block from the string
				$string = $matches[2] . "\n" . substr($string, $new_line_position + strlen("\n"));
				// check for EASE tags containing only whitespace
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $string, $matches)) {
					$string = substr($string, strlen($matches[0]));
					continue;
				}
				// check for single line comments starting the EASE block
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*\/\/(\V*)/is', $string, $matches)) {
					// remove the comment line from the start of the EASE block and continue processing the remaining EASE
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				// parse for the first valid EASE directive in the block

				###############################################
				##	IF ELSE - Conditional EASE blocks
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*((?:else\s*if\s*\(\s*.*?\s*\)\s*\{.*?\}\s*)*)(?:else\s*\{(.*?)\}\s*|)(' . preg_quote($this->core->ease_block_end, '/') . '|.*?;\s*' . preg_quote($this->core->ease_block_end, '/') . ')/is', $string, $matches)) {
					// initialize variables to store the EASE blocks by condition, the else catchall conditional EASE block, and the EASE block for any matched condition
					$conditions = array();
					// process the regular expression matches to build an array of conditions, and any else condition
					$conditional_ease_block = $this->core->ease_block_start . $matches[2] . $this->core->ease_block_end;
					$conditions['if_conditions'][$matches[1]] = $this->string_to_tokenized_ease($conditional_ease_block, true);
					// process any ELSE IF conditions
					while(preg_match('/^else\s*if\s*\(\s*(.*?)\s*\)\s*\{(.*?)\}\s*/is', $matches[3], $inner_matches)) {
						// found an ELSE IF condition
						$conditional_ease_block = $this->core->ease_block_start . $inner_matches[2] . $this->core->ease_block_end;
						$conditions['if_conditions'][$inner_matches[1]] = $this->string_to_tokenized_ease($conditional_ease_block, true);
						$matches[3] = substr($matches[3], strlen($inner_matches[0]));
					}
					// store the EASE block for any ELSE condition
					if(trim($matches[4])!='') {
						// found an ELSE condition
						$conditional_ease_block = $this->core->ease_block_start . $matches[4] . $this->core->ease_block_end;
						$conditions['else_condition'] = $this->string_to_tokenized_ease($conditional_ease_block, true);
					}
					$tokenized_ease[] = array('conditional'=>$conditions);
					// remove the Conditional from the EASE block, then process any remains
					if($matches[5]!=$this->core->ease_block_end) {
						// there is EASE code after the conditional block
						$string = $this->core->ease_block_start . $matches[5] . substr($string, strlen($matches[0]));
					} else {
						$string = substr($string, strlen($matches[0]));
					}
					continue;
				}

				###############################################
				##	SET TO "QUOTED"
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+"(.*?)"\s*;\s*/is', $string, $matches)) {
					$this->interpreter->inject_global_variables($matches[2]);
					if(preg_match('/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $matches[1], $inner_matches)) {
						$tokenized_ease[] = array('local_set'=>array('key'=>$inner_matches[1], 'value'=>$matches[2]));
					} else {
						$name_parts = explode('.', $matches[1], 2);
						if(count($name_parts)==2) {
							$bucket = strtolower(rtrim($name_parts[0]));
							$key = strtolower(ltrim($name_parts[1]));
						} else {
							$bucket = '';
							$key = strtolower(trim($matches[1]));
						}
						$tokenized_ease[] = array('set'=>array('bucket'=>$bucket, 'key'=>$key, 'value'=>$matches[2]));
					}
					// remove the SET command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	SET TO TOTAL OF
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+total\s+of\s+(.*?)\s*;\s*/is', $string, $matches)) {
					$this->interpreter->inject_global_variables($matches[2]);
					if(preg_match('/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $matches[1], $inner_matches)) {
						$tokenized_ease[] = array('local_set_to_total'=>array('key'=>$inner_matches[1], 'of'=>$matches[2]));
					} else {
						$name_parts = explode('.', $matches[1], 2);
						if(count($name_parts)==2) {
							$bucket = strtolower(rtrim($name_parts[0]));
							$key = strtolower(ltrim($name_parts[1]));
						} else {
							$bucket = '';
							$key = strtolower($matches[1]);
						}
						$tokenized_ease[] = array('set_to_total'=>array('bucket'=>$bucket, 'key'=>$key, 'of'=>$matches[2]));
					}
					// remove the SET command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	SET TO EXPRESSION
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*set\s+([^;]+?)\s+to\s+([^;\v]*?)\s*;\s*/is', $string, $matches)) {
					$this->interpreter->inject_global_variables($matches[2]);
					$context_stack = $this->interpreter->extract_context_stack($matches[2]);
					if(preg_match('/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $matches[1], $inner_matches)) {
						$tokenized_ease[] = array('local_set_to_expression'=>array('key'=>$inner_matches[1], 'value'=>$matches[2], 'context_stack'=>$context_stack));
					} else {
						$name_parts = explode('.', $matches[1], 2);
						if(count($name_parts)==2) {
							$bucket = strtolower(rtrim($name_parts[0]));
							$key = strtolower(ltrim($name_parts[1]));
						} else {
							$bucket = '';
							$key = strtolower(trim($matches[1]));
						}
						$tokenized_ease[] = array('set_to_expression'=>array('bucket'=>$bucket, 'key'=>$key, 'value'=>$matches[2], 'context_stack'=>$context_stack));
					}
					// remove the SET command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	ROUND
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $string, $matches)) {
					$this->interpreter->inject_global_variables($matches[2]);
					if(preg_match('/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is', $matches[1], $inner_matches)) {
						$tokenized_ease[] = array('local_round'=>array('key'=>$inner_matches[1], 'decimals'=>$matches[2]));
					} else {
						$name_parts = explode('.', $matches[1], 2);
						if(count($name_parts)==2) {
							$bucket = strtolower(rtrim($name_parts[0]));
							$key = strtolower(ltrim($name_parts[1]));
						} else {
							$bucket = '';
							$key = strtolower(trim($matches[1]));
						}
						$tokenized_ease[] = array('round'=>array('bucket'=>$bucket, 'key'=>$key, 'decimals'=>$matches[2]));
					}
					// remove the ROUND command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	REDIRECT
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*redirect\s+to\s+"(.*?)"\s*;\s*/is', $string, $matches)) {
					$this->interpreter->inject_global_variables($matches[1]);
					$tokenized_ease[] = array('redirect'=>$matches[1]);
					// remove the REDIRECT command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	PRINT "QUOTED-STRING"
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*print\s*"(.*?)"(\s*[^\.;]*[\.;]{0,1})\s*/is', $string, $matches)) {
					// inject any global variables into the value to print
					$this->interpreter->inject_global_variables($matches[1]);
					// remove any trailing . or ; characters, then determine the context
					$print_context = rtrim($matches[2], '.;');
					$context_stack = $this->interpreter->extract_context_stack($print_context);
					// add the print command to the tokenized EASE array
					if(is_array($context_stack) && count($context_stack) > 0) {
						$tokenized_ease[] = array('print'=>array('string'=>$matches[1], 'context_stack'=>$context_stack));
					} else {
						$tokenized_ease[] = array('print'=>$matches[1]);
					}
					// remove the PRINT command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	EMPTY BUCKET *BUCKET-NAME* / PRINT BUCKET *BUCKET-NAME*
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(empty|clear|clean|flush|html\s*dump|show|print|echo)\s*(local\s*|)bucket\s*([^;]+?)\s*;/is', $string, $matches)) {
					// inject any global variables into the bucket name
					switch(preg_replace('/[^a-z]+/s', '', strtolower($matches[1]))) {
						case 'empty':
						case 'clear':
						case 'clean':
						case 'flush':
							if(trim(strtolower($matches[2]))=='local') {
								$tokenized_ease[] = array('empty_local_bucket'=>$matches[3]);
							} else {
								$tokenized_ease[] = array('empty_bucket'=>$matches[3]);
							}
							break;
						case 'htmldump':
						case 'show':
						case 'print':
						case 'echo':
							if(trim(strtolower($matches[2]))=='local') {
								$tokenized_ease[] = array('print_local_bucket'=>$matches[3]);
							} else {
								$tokenized_ease[] = array('print_bucket'=>$matches[3]);
							}
							break;
						default:
					}
					// remove the BUCKET command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	INCLUDE "QUOTED-STRING"
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*include\s*"(.*?)"\s*[\.;]{0,1}\s*/is', $string, $matches)) {
					// inject any global variables into the page filepath
					$this->interpreter->inject_global_variables($matches[1]);
					$included_file_content = '';
					if(!$this->core->include_disabled) {
						// get the content of the included file... don't allow doubly including the local header.espx or footer.espx files
						if($matches[1]!='header.espx' && $matches[1]!='footer.espx') {
							$include_file_path = str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
							if(substr($matches[1], 0, 1)=='/' && file_exists($this->core->application_root . $include_file_path)) {
								$included_file_content = @file_get_contents($this->core->application_root . $include_file_path);
							} else {
								$included_file_content = @file_get_contents($include_file_path, FILE_USE_INCLUDE_PATH);
							}
						} 
					}
					// replace the INCLUDE tag from the remaining unprocessed string with the contents of the included file
					$string = $included_file_content . $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}
				
				###############################################
				##	GRANT ACCESS / REVOKE ACCESS
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(grant|give|permit|allow|revoke)\s*access\s*to\s+(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . '|([^;]+?))\s*([;]{0,1}\s*' . preg_quote($this->core->ease_block_end, '/') . '|;)\s*/is', $string, $matches)) {
					// inject any global variables into the session.key lock reference
					$this->interpreter->inject_global_variables($matches[2]);
					switch(strtolower($matches[1])) {
						case 'revoke':
							$tokenized_ease[] = array('revoke_access'=>$matches[2]);
							break;
						default:
						$tokenized_ease[] = array('grant_access'=>$matches[2]);
					}
					// remove the tag from the remaining unprocessed body
					$string = ($matches[4]==';' ? $this->core->ease_block_start : '') . substr($string, strlen($matches[0]));
					continue;
				}
				
				###############################################
				##	SEND EMAIL
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*send\s+email\s*;(\s*(body)\s*=\s*"""\s*(.*?)\v\s*"""\s*;(\s*\/\/\V*\v+\s*|\s*)|\s*(from_name|to|cc|bcc|subject|type|body|bodypage)\s*=\s*"(.*?)"\s*;(\s*\/\/\V*\v+\s*|\s*))*\s*/is', $string, $matches)) {
					$unprocessed_send_email_block = $matches[0];
					$send_email_attributes = array();
					$unprocessed_send_email_block = preg_replace('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*send\s+email\s*;/is', '', $unprocessed_send_email_block);
					// *EMAIL-ATTRIBUTE* = """multi-line quoted"""
					$unprocessed_send_email_block = preg_replace_callback(
						'/([a-z_]*?)\s*=\s*"""\s*(.*?)\v\s*"""\s*;\s*/is',
						function($matches) use (&$send_email_attributes) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[2]);
							$send_email_attributes[strtolower($matches[1])] = $matches[2];
							return '';
						},
						$unprocessed_send_email_block
					);
					// *EMAIL-ATTRIBUTE* = "quoted"
					$unprocessed_send_email_block = preg_replace_callback(
						'/\s*([a-z_]*?)\s*=\s*"\s*(.*?)\s*"\s*;\s*/is',
						function($matches) use (&$send_email_attributes) {
							$this->interpreter->inject_global_variables($matches[1]);
							$this->interpreter->inject_global_variables($matches[2]);
							$send_email_attributes[strtolower($matches[1])] = $matches[2];
							return '';
						},
						$unprocessed_send_email_block
					);
					// build the email message and headers according to the type
					$mail_options = array();
					if(isset($send_email_attributes['bodypage']) && trim($send_email_attributes['bodypage'])!='' && !$this->core->include_disabled) {
						// parse the bodypage using any supplied HTTP ?query string
						$send_email_espx_body_url_parts = explode('?', ltrim($send_email_attributes['bodypage'], '/'), 2);
						if(count($send_email_espx_body_url_parts)==2) {
							$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_espx_body_url_parts[0]) . '.espx';
							$send_email_url_params = array();
							parse_str($send_email_espx_body_url_parts[1], $send_email_url_params);
						} else {
							$send_email_espx_filepath = $this->core->application_root . DIRECTORY_SEPARATOR . preg_replace('/\.espx$/i', '', $send_email_attributes['bodypage']) . '.espx';
							$send_email_url_params = null;
						}
						$send_email_espx_body = @file_get_contents($send_email_espx_filepath);
						$send_email_page_parser = new ease_parser($this->core, $send_email_url_params);
						$send_email_attributes['body'] = $send_email_page_parser->process($send_email_espx_body, true);
						$send_email_page_parser = null;
					}
					if(isset($send_email_attributes['from_name'])) {
						$mail_options['sender'] = $send_email_attributes['from_name'];
					}
					if(isset($send_email_attributes['to'])) {
						$mail_options['to'] = $send_email_attributes['to'];
					}
					if(isset($send_email_attributes['cc'])) {
						$mail_options['cc'] = $send_email_attributes['cc'];
					}
					if(isset($send_email_attributes['bcc'])) {
						$mail_options['bcc'] = $send_email_attributes['bcc'];
					}
					if(isset($send_email_attributes['subject'])) {
						$mail_options['subject'] = $send_email_attributes['subject'];
					}
					if(@$send_email_attributes['type']=='html') {
						$mail_options['htmlBody'] = "<html><head><title>{$send_email_attributes['subject']}</title></head><body>{$send_email_attributes['body']}</body></html>";
					} else {
						$mail_options['textBody'] = (string)$send_email_attributes['body'];
					}
					$tokenized_ease[] = array('send_email'=>$mail_options);
					// remove the SEND EMAIL block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}

				###############################################
				##	CREATE RECORD FOR SQL TABLE
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*create\s*(new\s*|)record\s+for\s*"\s*(.*?)\s*"\s*(and\s*|)(reference\s*|)(as\s*"\s*(.*?)\s*"\s*|);(.*)$/is', $string, $matches)) {
					$create_record = array();
					$this->interpreter->inject_global_variables($matches[2]);
					$create_record['for'] = strtolower($matches[2]);
					$create_record['as'] = strtolower($matches[6]);
					$unprocessed_create_record_block = $matches[7];
					// parse out any chained CREATE RECORD directives at the beginning of the unprocessed create record block
					while(true) {
						// SET
						if(preg_match('/^\s*set\s+([^;]+?)\s+to\s+("(.*?)"|([^;]+?))\s*;\s*/is', $unprocessed_create_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$this->interpreter->inject_global_variables($inner_matches[2]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$create_record['set'][] = array('bucket'=>$bucket, 'key'=>$key, 'value'=>$inner_matches[2]);
							$unprocessed_create_record_block = substr($unprocessed_create_record_block, strlen($inner_matches[0]));
							continue;
						}
						// ROUND
						if(preg_match('/^\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_create_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$create_record['round'][] = array('bucket'=>$bucket, 'key'=>$key, 'decimals'=>$inner_matches[2]);
							$unprocessed_create_record_block = substr($unprocessed_create_record_block, strlen($inner_matches[0]));
							continue;
						}
						// GO TO NEXT RECORD
						if(preg_match('/^\s*go\s*to\s+next\s+record\s*;\s*/is', $unprocessed_create_record_block, $inner_matches)) {
							$unprocessed_create_record_block = substr($unprocessed_create_record_block, strlen($inner_matches[0]));
						}
						break;
					}
					$tokenized_ease[] = array('create_sql_record'=>$create_record);
					// remove the CREATE RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_create_record_block;
					continue;
				}

				###############################################
				##	UPDATE RECORD FOR SQL TABLE
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*update\s+record\s+for\s*"\s*(.*?)\s*"(\s*reference\s+as\s*"\s*(.*?)\s*"\s*|\s*);(.*)$/is', $string, $matches)) {
					$update_record = array();
					$this->interpreter->inject_global_variables($matches[1]);
					$update_record['for'] = strtolower($matches[1]);
					$update_record['as'] = strtolower($matches[3]);
					$unprocessed_update_record_block = $matches[4];
					// parse any chained UPDATE RECORD directives
					while(true) {
						// SET
						if(preg_match('/^\s*set\s+([^;]+?)\s+to\s+("(.*?)"|([^;]+?))\s*;\s*/is', $unprocessed_update_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$this->interpreter->inject_global_variables($inner_matches[2]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$update_record['set'][] = array('bucket'=>$bucket, 'key'=>$key, 'value'=>$inner_matches[2]);
							$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($inner_matches[0]));
							continue;
						}
						// ROUND
						if(preg_match('/^\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_update_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$update_record['round'][] = array('bucket'=>$bucket, 'key'=>$key, 'decimals'=>$inner_matches[2]);
							$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($inner_matches[0]));
							continue;
						}
						// GO TO NEXT RECORD
						if(preg_match('/^\s*go\s*to\s+next\s+record\s*;\s*/is', $unprocessed_update_record_block, $inner_matches)) {
							$unprocessed_update_record_block = substr($unprocessed_update_record_block, strlen($inner_matches[0]));
						}
						break;
					}
					$tokenized_ease[] = array('update_sql_record'=>$update_record);
					// remove the UPDATE RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_update_record_block;
					continue;
				}

				###############################################
				##	DELETE RECORD FOR SQL TABLE
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*delete\s+record\s+for\s*"\s*(.*?)\s*"\s*;(.*)$/is', $string, $matches)) {
					$delete_record = array();
					$this->interpreter->inject_global_variables($matches[1]);
					$delete_record['for'] = strtolower($matches[1]);
					$unprocessed_delete_record_block = $matches[2];
					// parse any DELETE RECORD directives
					// GO TO NEXT RECORD
					$unprocessed_delete_record_block = preg_replace('/^\s*go\s*to\s+next\s+record\s*;\s*/is', '', $unprocessed_delete_record_block);
					$tokenized_ease[] = array('delete_sql_record'=>$delete_record);
					// remove the DELETE RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_delete_record_block;
					continue;
				}

				###############################################
				##	CREATE RECORD FOR GOOGLE SHEET
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*create\s+(new\s+|)record\s+for\s+(google\s*drive\s*|google\s*docs\s*|google\s*|g\s*|g\s*docs\s*|g\s*drive\s*|)spreadsheet(\s*"\s*(.*?)\s*"|\s+(\S+))(\s*"\s*(.*?)\s*"\s*?|\s*?)(and\s+reference\s+|reference\s+|)(as\s*"\s*(.*?)\s*"|as\s*([a-z0-9_\.]+)|)\s*;(.*)$/is', $string, $matches)) {
					$create_spreadsheet_record = array();
					$this->interpreter->inject_global_variables($matches[4]);
					$create_spreadsheet_record['spreadsheet_name'] = $matches[4];
					$this->interpreter->inject_global_variables($matches[5]);
					$create_spreadsheet_record['spreadsheet_id'] = $matches[5];
					$this->interpreter->inject_global_variables($matches[7]);
					$create_spreadsheet_record['worksheet_name'] = $matches[7];
					$create_spreadsheet_record['as'] = strtolower($matches[10] . $matches[11]);
					$unprocessed_create_spreadsheet_record_block = $matches[12];
					// parse any CREATE RECORD directives
					while(true) {
						// SET
						if(preg_match('/^\s*set\s+([^;]+?)\s+to\s+("(.*?)"|([^;]+?))\s*;\s*/is', $unprocessed_create_spreadsheet_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$this->interpreter->inject_global_variables($inner_matches[2]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$create_spreadsheet_record['set'][] = array('bucket'=>$bucket, 'key'=>$key, 'value'=>$inner_matches[2]);
							$unprocessed_create_spreadsheet_record_block = substr($unprocessed_create_spreadsheet_record_block, strlen($inner_matches[0]));
							continue;
						}
						// ROUND
						if(preg_match('/^\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_create_spreadsheet_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$create_spreadsheet_record['round'][] = array('bucket'=>$bucket, 'key'=>$key, 'decimals'=>$inner_matches[2]);
							$unprocessed_create_spreadsheet_record_block = substr($unprocessed_create_spreadsheet_record_block, strlen($inner_matches[0]));
							continue;
						}
						// GO TO NEXT RECORD
						if(preg_match('/^\s*go\s*to\s+next\s+record\s*;\s*/is', $unprocessed_create_spreadsheet_record_block, $inner_matches)) {
							$unprocessed_create_spreadsheet_record_block = substr($unprocessed_create_spreadsheet_record_block, strlen($inner_matches[0]));
						}
						break;
					}
					$tokenized_ease[] = array('create_spreadsheet_record'=>$create_spreadsheet_record);
					// remove the CREATE NEW RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_create_spreadsheet_record_block;
					continue;
				}

				###############################################
				##	UPDATE RECORD FOR GOOGLE SHEET
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*update\s+record\s+for\s+(?:google\s*drive\s*|google\s*docs\s*|google\s*|g\s*|g\s*docs\s*|g\s*drive\s*|)(?:spread|)sheet(?:\s*"\s*(\V*?)\s*"|\s*(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . ')|\s+(\S+?))(?:\s*"\s*(\V*?)\s*"\s*|\s*)(?:(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . ')\s*|(\S+?)\s*)(?:and\s+reference\s+|reference\s+|)(?:as\s*"\s*(\V*?)\s*"|as\s*([a-z0-9_\.]+)|)\s*;(.*)$/is', $string, $matches)) {
					$update_spreadsheet_record = array();
					$this->interpreter->inject_global_variables($matches[1]);
					$update_spreadsheet_record['spreadsheet_name'] = $matches[1];
					$this->interpreter->inject_global_variables($matches[2]);
					$this->interpreter->inject_global_variables($matches[3]);
					$update_spreadsheet_record['spreadsheet_id'] = $matches[2] . $matches[3];
					$this->interpreter->inject_global_variables($matches[4]);
					$update_spreadsheet_record['worksheet_name'] = $matches[4];
					$this->interpreter->inject_global_variables($matches[5]);
					$this->interpreter->inject_global_variables($matches[6]);
					$update_spreadsheet_record['row_uuid'] = $matches[5] . $matches[6];
					$this->interpreter->inject_global_variables($matches[7]);
					$update_spreadsheet_record['as'] = strtolower($matches[7] . $matches[8]);
					$unprocessed_update_spreadsheet_record_block = $matches[9];
					// parse any UPDATE RECORD directives
					while(true) {
						// SET
						if(preg_match('/^\s*set\s+([^;]+?)\s+to\s+("(.*?)"|([^;]+?))\s*;\s*/is', $unprocessed_update_spreadsheet_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$this->interpreter->inject_global_variables($inner_matches[2]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$update_spreadsheet_record['set'][] = array('bucket'=>$bucket, 'key'=>$key, 'value'=>$inner_matches[2]);
							$unprocessed_update_spreadsheet_record_block = substr($unprocessed_update_spreadsheet_record_block, strlen($inner_matches[0]));
							continue;
						}
						// ROUND
						if(preg_match('/^\s*round\s+([^;]+?)\s+to\s+([0-9]+)\s+decimals\s*;\s*/is', $unprocessed_update_spreadsheet_record_block, $inner_matches)) {
							$this->interpreter->inject_global_variables($inner_matches[1]);
							$name_parts = explode('.', $inner_matches[1], 2);
							if(count($name_parts)==2) {
								$bucket = strtolower(rtrim($name_parts[0]));
								$key = strtolower(ltrim($name_parts[1]));
							} else {
								$bucket = '';
								$key = strtolower(trim($inner_matches[1]));
							}
							$update_spreadsheet_record['round'][] = array('bucket'=>$bucket, 'key'=>$key, 'decimals'=>$inner_matches[2]);
							$unprocessed_update_spreadsheet_record_block = substr($unprocessed_update_spreadsheet_record_block, strlen($inner_matches[0]));
							continue;
						}
						// GO TO NEXT RECORD
						if(preg_match('/^\s*go\s*to\s+next\s+record\s*;\s*/is', $unprocessed_update_spreadsheet_record_block, $inner_matches)) {
							$unprocessed_update_spreadsheet_record_block = substr($unprocessed_update_spreadsheet_record_block, strlen($inner_matches[0]));
						}
						break;
					}
					$tokenized_ease[] = array('update_spreadsheet_record'=>$update_spreadsheet_record);
					// remove the UPDATE RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_update_spreadsheet_record_block;
					continue;
				}

				###############################################
				##	DELETE RECORD FOR GOOGLE SHEET
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*delete\s+record\s+for\s+(?:google\s*drive\s*|google\s*docs\s*|google\s*|g\s*|g\s*docs\s*|g\s*drive\s*|)(?:spread|)sheet(?:\s*"\s*(\V*?)\s*"|\s*(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . ')|\s+(\S+?))(?:\s*"\s*(\V*?)\s*"\s*|\s*)(?:(' . preg_quote($this->core->ease_block_start, '/') . '.*?' . preg_quote($this->core->ease_block_end, '/') . ')\s*|(\S+?)\s*)(?:and\s+reference\s+|reference\s+|)(?:as\s*"\s*(\V*?)\s*"|as\s*([a-z0-9_\.]+)|)\s*;(.*)$/is', $string, $matches)) {
					$delete_spreadsheet_record = array();
					$this->interpreter->inject_global_variables($matches[1]);
					$delete_spreadsheet_record['spreadsheet_name'] = $matches[1];
					$this->interpreter->inject_global_variables($matches[2]);
					$this->interpreter->inject_global_variables($matches[3]);
					$delete_spreadsheet_record['spreadsheet_id'] = $matches[2] . $matches[3];
					$this->interpreter->inject_global_variables($matches[4]);
					$delete_spreadsheet_record['worksheet_name'] = $matches[4];
					$this->interpreter->inject_global_variables($matches[5]);
					$this->interpreter->inject_global_variables($matches[6]);
					$delete_spreadsheet_record['row_uuid'] = $matches[5] . $matches[6];
					$this->interpreter->inject_global_variables($matches[7]);
					$delete_spreadsheet_record['as'] = strtolower($matches[7] . $matches[8]);
					$unprocessed_delete_spreadsheet_record_block = $matches[9];
					// parse any GO TO NEXT RECORD directives
					if(preg_match('/^\s*go\s*to\s+next\s+record\s*;\s*/is', $unprocessed_delete_spreadsheet_record_block, $inner_matches)) {
						$unprocessed_delete_spreadsheet_record_block = substr($unprocessed_delete_spreadsheet_record_block, strlen($inner_matches[0]));
					}
					$tokenized_ease[] = array('delete_spreadsheet_record'=>$delete_spreadsheet_record);
					// remove the DELETE RECORD block from the EASE block, then process any remains
					$string = $this->core->ease_block_start . $unprocessed_delete_spreadsheet_record_block;
					continue;
				}
				
				###############################################
				##	APPLY ROW
				if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*apply\s+row\s+(?:and\s+reference\s+|reference\s+|)(?:as\s*"\s*(\V*?)\s*"|as\s*([a-z0-9_\.]+)|)\s*;\s*/is', $string, $matches)) {
					$apply_row = array();
					$apply_row['as'] = $matches[1] . $matches[2];
					$tokenized_ease[] = array('apply_row'=>$apply_row);
					// remove the APPLY ROW command from the EASE block, then process any remains
					$string = $this->core->ease_block_start . substr($string, strlen($matches[0]));
					continue;
				}
				
				// !! ANY NEW EASE COMMANDS GET ADDED HERE

				// a valid EASE command was not found at the start of this block.  print it raw, then process the remains
				if($conditional_block) {
					// conditional blocks have EASE tags automatically wrapped around them, remove them and just print the block
					if(preg_match('/^' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*)\s*' . preg_quote($this->core->ease_block_end, '/') . '$/is', $string, $matches)) {
						$this->interpreter->inject_global_variables($matches[1]);
						$tokenized_ease[] = array('print'=>$matches[1]);
						$string = '';
						continue;
					} else {
						// start sequence of EASE with no end sequence... print the start sequence of EASE, then continue processing the remains
						$tokenized_ease[] = array('print'=>$this->core->ease_block_start);
						$string = substr($string, strlen($this->core->ease_block_start));
						continue;
					}
				} else {
					if(preg_match('/^(' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . ')(.*)$/is', $string, $matches)) {
						$this->interpreter->inject_global_variables($matches[2]);
						$tokenized_ease[] = array('print'=>$this->core->ease_block_start . $matches[2] . $this->core->ease_block_end);
						$string = substr($string, strlen($matches[1]));
						continue;
					} else {
						// start sequence of EASE with no end sequence... print the start sequence of EASE, then process then remains
						$tokenized_ease[] = array('print'=>$this->core->ease_block_start);
						$string = substr($string, strlen($this->core->ease_block_start));
						continue;
					}
				}
			} else {
				// the line did not contain the start sequence of EASE ;  inject the line into the output buffer
				$tokenized_ease[] = array('print'=>$string_line . ($last_line ? '' : "\n"));
				// remove the line from the remaining unprocessed string
				$string = substr($string, $new_line_position + strlen("\n"));
				continue;
			}
		}
		// done tokenizing the string... consolidate sequential uncontexted print commands
		$previous_command_was_print = false;
		$previous_print_key = null;
		foreach($tokenized_ease as $key=>$ease_token) {
			if(isset($ease_token['print']) && !is_array($ease_token['print'])) {
				// found an uncontexted print command (if it was contexted print command, the 'print' value would be an array)
				if($previous_command_was_print) {
					// already printing, so consolidate this print command with the previous one
					$tokenized_ease[$key]['print'] = $tokenized_ease[$previous_print_key]['print'] . $ease_token['print'];
					unset($tokenized_ease[$previous_print_key]);
				}
				$previous_command_was_print = true;
				$previous_print_key = $key;
			} else {
				$previous_command_was_print = false;
				// TODO!! update this to consolidate sequential contexted print commands in the same context
			}
		}
		// done optimizing the tokens... return the tokenized array for the string
		return $tokenized_ease;
	}

	// this function will process an array of tokenized EASE and PHP Blocks, applying parameters for output and variable injection
	public function process_tokenized_ease($tokenized_ease, &$params=array()) {
		// process any parameters for variable injection and output buffering
		if(isset($params['save_to_global_variable']) && trim($params['save_to_global_variable'])!='') {
			$save_to_global_variable = $params['save_to_global_variable'];
			if(!isset($this->core->globals[$save_to_global_variable])) {
				$this->core->globals[$save_to_global_variable] = '';
			}
		}
		$no_local_injection = (isset($params['no_local_injection']) && $params['no_local_injection']);
		if(isset($params['sql_table_name']) && trim($params['sql_table_name'])!='') {
			$inject_local_sql_row_variables = true;
			if(!isset($params['row']) || !is_array($params['row'])) {
				$params['row'] = array();
			}
		}
		if(isset($params['cell_value_by_column_letter']) && is_array($params['cell_value_by_column_letter'])) {
			$inject_local_spreadsheet_row_variables = true;
		} elseif(isset($params['cells_by_row_by_column_letter']) && is_array($params['cells_by_row_by_column_letter'])) {
			$inject_local_spreadsheet_variables = true;
		}
		foreach($tokenized_ease as $statement_tokens) {
			$process_queue = array($statement_tokens);
			// continue process while conditionals are injected into the process queue
			$continue_processing = true;
			while($continue_processing) {
				// processing will only continue if this statement is a conditional with a matching block
				$continue_processing = false;
				foreach($process_queue as $statement_tokens) {
					foreach($statement_tokens as $keyword=>$directives) {
						switch($keyword) {
							case 'print':
								if(is_array($directives)) {
									// the print command includes an array of directives, implying a context is involved
									$this->interpreter->inject_global_variables($directives['string']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['string'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['string'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['string'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									$this->interpreter->apply_context_stack($directives['string'], $directives['context_stack']);
									if(isset($save_to_global_variable)) {
										$this->core->globals[$save_to_global_variable] .= $directives['string'];
									} else {
										$this->output_buffer .= $directives['string'];
									}
								} else {
									$this->interpreter->inject_global_variables($directives);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									if(isset($save_to_global_variable)) {
										$this->core->globals[$save_to_global_variable] .= $directives;
									} else {
										$this->output_buffer .= $directives;
									}
								}
								break;
							case 'conditional':
								$matched_condition = false;
								$process_ease_block = null;
								foreach($directives['if_conditions'] as $condition=>$conditional_ease) {
									$remaining_condition = $condition;
									$php_condition_string = '';
									while(preg_match('/^(&&|\|\||and|or|xor){0,1}\s*(!|not){0,1}([(\s]*)"(.*?)"\s*(==|!=|>|>=|<|<=|<>|===|!==|=|is)\s*"(.*?)"([)\s]*)/is', $remaining_condition, $matches)) {
										if(strtolower($matches[1])=='and') {
											$matches[1] = '&&';
										}
										if(strtolower($matches[1])=='or') {
											$matches[1] = '||';
										}
										if(strtolower($matches[2])=='not') {
											$matches[2] = '!';
										}
										if($matches[5]=='=') {
											$matches[5] = '==';
										}
										if(strtolower($matches[5])=='is') {
											$matches[5] = '==';
										}
										$this->interpreter->inject_global_variables($matches[4]);
										if(!$no_local_injection) {
											if(isset($inject_local_sql_row_variables)) {
												$this->interpreter->inject_local_sql_row_variables($matches[4], $params['row'], $params['sql_table_name'], $this->local_variables);
											} elseif(isset($inject_local_spreadsheet_row_variables)) {
												$this->interpreter->inject_local_spreadsheet_row_variables($matches[4], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
											} elseif(isset($inject_local_spreadsheet_variables)) {
												$this->interpreter->inject_local_spreadsheet_variables($matches[4], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
											}
										}
										$this->interpreter->inject_global_variables($matches[6]);
										if(!$no_local_injection) {
											if(isset($inject_local_sql_row_variables)) {
												$this->interpreter->inject_local_sql_row_variables($matches[6], $params['row'], $params['sql_table_name'], $this->local_variables);
											} elseif(isset($inject_local_spreadsheet_row_variables)) {
												$this->interpreter->inject_local_spreadsheet_row_variables($matches[6], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
											} elseif(isset($inject_local_spreadsheet_variables)) {
												$this->interpreter->inject_local_spreadsheet_variables($matches[6], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
											}
										}
										$php_condition_string .= $matches[1]
											. $matches[2]
											. $matches[3]
											. var_export($matches[4], true)
											. $matches[5]
											. var_export($matches[6], true)
											. $matches[7];
										$remaining_condition = substr($remaining_condition, strlen($matches[0]));
									}
									if($php_condition_string!='') {
										if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
											$matched_condition = true;
											$process_queue = array();
											foreach(array_keys($conditional_ease) as $key) {
												if(count($conditional_ease[$key]) > 0) {
													$process_queue[] = $conditional_ease[$key];
												}
											}
											$continue_processing = true;
											break;
										}
									}
								}
								if((!$matched_condition) && isset($directives['else_condition']) && is_array($directives['else_condition'])) {
									$process_queue = array();
									foreach(array_keys($directives['else_condition']) as $key) {
										if(count($directives['else_condition'][$key]) > 0) {
											$process_queue[] = $directives['else_condition'][$key];
										}
									}
									$continue_processing = true;
								}
								break;
							case 'redirect':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								if($this->core->catch_redirect) {
									// EASE Framework configured to catch redirects
									$this->core->redirect = $directives;
									// halt processing anything after the redirect
									$this->unprocessed_body = '';
									return;
								} else {
									header("Location: $directives");
									exit;
								}
							case 'grant_access':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$directives = trim(preg_replace('/[^a-z0-9]+/is', '_', strtolower($directives)), '_');
								$_SESSION['ease_memberships.' . $directives] = 'unlocked';								
								break;
							case 'revoke_access':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$directives = trim(preg_replace('/[^a-z0-9]+/is', '_', strtolower($directives)), '_');
								unset($_SESSION['ease_memberships.' . $directives]);
								break;
							case 'set':
								$this->interpreter->inject_global_variables($directives['value']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								switch($directives['bucket']) {
									case 'session':
										$_SESSION[$directives['key']] = $directives['value'];
										break;
									case 'cookie':
										setcookie($directives['key'], $directives['value'], time() + 60 * 60 * 24 * 365, '/');
										$_COOKIE[$directives['key']] = $directives['value'];
										break;
									case 'cache':
										if($this->core->memcache) {
											if($this->core->namespace!='') {
												$this->core->memcache->set("{$this->core->namespace}.{$directives['key']}", $directives['value']);
											} else {
												$this->core->memcache->set($directives['key'], $directives['value']);
											}
										}
										break;
									case 'system':
									case 'config':
										// do nothing, global system and config values can not be set by EASE code
										break;
									default:
									if($directives['bucket']!='') {
										$this->core->globals["{$directives['bucket']}.{$directives['key']}"] = $directives['value'];
									} else {
										$this->core->globals[$directives['key']] = $directives['value'];
									}
								}
								break;
							case 'local_set':
								$this->interpreter->inject_global_variables($directives['value']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$this->local_variables[$directives['key']] = $directives['value'];
								break;
							case 'set_to_expression':
								$this->interpreter->inject_global_variables($directives['value']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$value = null;
								if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $directives['value'])) {
									// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
									$eval_result = @eval("\$value = {$directives['value']};");
									if($eval_result===false) {
										// there was an error evaluating the expression... set the value to the expression string
										$value = $directives['value'];
									}
								}
								if($value===null) {
									$value = $directives['value'];
								}
								$this->interpreter->apply_context_stack($value, $directives['context_stack']);
								switch($directives['bucket']) {
									case 'session':
										$_SESSION[$directives['key']] = $value;
										break;
									case 'cookie':
										setcookie($directives['key'], $value, time() + 60 * 60 * 24 * 365, '/');
										$_COOKIE[$directives['key']] = $value;
										break;
									case 'cache':
										if($this->core->memcache) {
											if($this->core->namespace!='') {
												$this->core->memcache->set("{$this->core->namespace}.{$directives['key']}", $value);
											} else {
												$this->core->memcache->set($directives['key'], $value);
											}
										}
										break;
									case 'system':
									case 'config':
										// do nothing, global system and config values can not be set by EASE code
										break;
									default:
									if($directives['bucket']!='') {
										$this->core->globals["{$directives['bucket']}.{$directives['key']}"] = $value;
									} else {
										$this->core->globals[$directives['key']] = $value;
									}
								}
								break;
							case 'local_set_to_expression':
								$this->interpreter->inject_global_variables($directives['value']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$value = null;
								if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $directives['value'])) {
									// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
									$eval_result = @eval("\$value = {$directives['value']};");
									if($eval_result===false) {
										// there was an error evaluating the expression... set the value to the expression string
										$value = $directives['value'];
									}
								}
								if($value===null) {
									$value = $directives['value'];
								}
								$this->interpreter->apply_context_stack($value, $directives['context_stack']);
								$this->local_variables[$directives['key']] = $value;
								break;
							case 'set_to_total':
								$total = 0;
								if(isset($params['rows']) && is_array($params['rows'])) {
									$this->interpreter->inject_global_variables($directives['of']);
									foreach($params['rows'] as $row) {
										$total_of = $directives['of'];
										if(!$no_local_injection) {
											if(isset($inject_local_sql_row_variables)) {
												$this->interpreter->inject_local_sql_row_variables($total_of, $row, $params['sql_table_name'], $this->local_variables);
											} elseif(isset($inject_local_spreadsheet_row_variables)) {
												$this->interpreter->inject_local_spreadsheet_row_variables($total_of, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
											} elseif(isset($inject_local_spreadsheet_variables)) {
												$this->interpreter->inject_local_spreadsheet_variables($total_of, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
											}
										}
										$total += $total_of;
									}
								}
								switch($directives['bucket']) {
									case 'session':
										$_SESSION[$directives['key']] = $total;
										break;
									case 'cookie':
										setcookie($directives['key'], $total, time() + 60 * 60 * 24 * 365, '/');
										$_COOKIE[$directives['key']] = $total;
										break;
									case 'cache':
										if($this->core->memcache) {
											if($this->core->namespace!='') {
												$this->core->memcache->set("{$this->core->namespace}.{$directives['key']}", $total);
											} else {
												$this->core->memcache->set($directives['key'], $total);
											}
										}
										break;
									case 'system':
									case 'config':
										// do nothing, global system and config values can not be set by EASE code
										break;
									default:
									if($directives['bucket']!='') {
										$this->core->globals["{$directives['bucket']}.{$directives['key']}"] = $total;
									} else {
										$this->core->globals[$directives['key']] = $total;
									}
								}
								break;
							case 'local_set_to_total':
								$this->local_variables[$directives['key']] = 0;
								if(isset($params['rows']) && is_array($params['rows'])) {
									$this->interpreter->inject_global_variables($directives['of']);
									foreach($params['rows'] as $row) {
										$total_of = $directives['of'];
										if(!$no_local_injection) {
											if(isset($inject_local_sql_row_variables)) {
												$this->interpreter->inject_local_sql_row_variables($total_of, $row, $params['sql_table_name'], $this->local_variables);
											} elseif(isset($inject_local_spreadsheet_row_variables)) {
												$this->interpreter->inject_local_spreadsheet_row_variables($total_of, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
											} elseif(isset($inject_local_spreadsheet_variables)) {
												$this->interpreter->inject_local_spreadsheet_variables($total_of, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
											}
										}
										$this->local_variables[$directives['key']] += $total_of;
									}
								}
								break;
							case 'round':
								if(isset($directives['decimals'])) {
									$this->interpreter->inject_global_variables($directives['decimals']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['decimals'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['decimals'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['decimals'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									switch($directives['bucket']) {
										case 'session':
											$_SESSION[$directives['key']] = round($_SESSION[$directives['key']], $directives['decimals']);
											break;
										case 'cookie':
											setcookie($directives['key'], round($_COOKIE[$directives['key']], $directives['decimals']), time() + 60 * 60 * 24 * 365, '/');
											$_COOKIE[$directives['key']] = round($_COOKIE[$directives['key']], $directives['decimals']);
											break;
										case 'cache':
											if($this->core->memcache) {
												if($this->core->namespace!='') {
													$this->core->memcache->set("{$this->core->namespace}.{$directives['key']}", round($this->core->memcache->get("{$this->core->namespace}.{$directives['key']}"), $directives['decimals']));
												} else {
													$this->core->memcache->set($directives['key'], round($this->core->memcache->get($directives['key']), $directives['decimals']));
												}
											}
											break;
										case 'system':
										case 'config':
											// do nothing, global system and config values can not be set by EASE code
											break;
										default:
										if($directives['bucket']!='') {
											$this->core->globals["{$directives['bucket']}.{$directives['key']}"] = round($this->core->globals["{$directives['bucket']}.{$directives['key']}"], $directives['decimals']);
										} else {
											$this->core->globals[$directives['key']] = round($this->core->globals[$directives['key']], $directives['decimals']);
										}
									}
								}
								break;
							case 'local_round':
								if(isset($directives['decimals'])) {
									$this->interpreter->inject_global_variables($directives['decimals']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['decimals'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['decimals'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['decimals'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									$this->local_variables[$directives['key']] = round($this->local_variables[$directives['key']], $directives['decimals']);
								}
								break;
							case 'php_block':
								// process the PHP Block if PHP wasn't disabled
								if(!$this->core->php_disabled) {
									$GLOBALS['__ease_parser'] = &$this;
									$php_block = '$localized_php_block = function() { ' . $directives . ' }; $localized_php_block();';
									// turn on PHP output buffering to record any output generated from evaluating the PHP Block
									ob_start();
									try {
										$eval_result = eval($php_block);
									} catch(Exception $e) {
										if(isset($save_to_global_variable)) {
											$this->core->globals[$save_to_global_variable] .= "<div style='color:red; font-weight:bold;'>PHP EXCEPTION! " . htmlspecialchars($e->getMessage()) . '</div>';
										} else {
											$this->output_buffer .= "<div style='color:red; font-weight:bold;'>PHP EXCEPTION! " . htmlspecialchars($e->getMessage()) . '</div>';
										}
									}
									if($eval_result===false) {
										// there was an error evaluating the PHP Block
										// TODO!! allow EASE config settings for suppressing errors
										$error = error_get_last();
										if(isset($save_to_global_variable)) {
											$this->core->globals[$save_to_global_variable] .= "<div style='color:red; font-weight:bold;'>PHP Error!  line {$error['line']}: " . htmlspecialchars($error['message']) . "</div>";
										} else {
											$this->output_buffer .= "<div style='color:red; font-weight:bold;'>PHP Error!  line {$error['line']}: " . htmlspecialchars($error['message']) . "</div>";
										}
									}
									// dump any generated output
									if(isset($save_to_global_variable)) {
										$this->core->globals[$save_to_global_variable] .= ob_get_contents();
									} else {
										$this->output_buffer .= ob_get_contents();
									}
									// turn off output buffering
									ob_end_clean();
								}
								break;
							case 'send_email':
								foreach(array_keys($directives) as $send_email_key) {
									$this->interpreter->inject_global_variables($directives[$send_email_key]);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives[$send_email_key], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives[$send_email_key], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives[$send_email_key], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
								}
								$result = $this->core->send_email($directives);
								break;
							case 'apply_spreadsheet_record':
								$this->core->html_dump($directives, 'apply_spreadsheet_record directives');
								break;	
							case 'create_spreadsheet_record':
								$this->interpreter->inject_global_variables($directives['spreadsheet_id']);
								$this->interpreter->inject_global_variables($directives['spreadsheet_name']);
								$this->interpreter->inject_global_variables($directives['worksheet_name']);
								$this->interpreter->inject_global_variables($directives['as']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_id'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['worksheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['as'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['as'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['as'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$listFeed = null;
								$new_row = array();
								// process any SET directives from the CREATE RECORD block
								if(isset($directives['set']) && is_array($directives['set'])) {
									foreach($directives['set'] as $set_command) {
										if($set_command['bucket']==$directives['as'] || $set_command['bucket']=='row') {
											$this->interpreter->inject_global_variables($set_command['value']);
											if(!$no_local_injection) {
												if(isset($inject_local_sql_row_variables)) {
													$this->interpreter->inject_local_sql_row_variables($set_command['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
												} elseif(isset($inject_local_spreadsheet_row_variables)) {
													$this->interpreter->inject_local_spreadsheet_row_variables($set_command['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
												} elseif(isset($inject_local_spreadsheet_variables)) {
													$this->interpreter->inject_local_spreadsheet_variables($set_command['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
												}
											}
											$context_stack = $this->interpreter->extract_context_stack($set_command['value']);
											if(preg_match('/^\s*"(.*)"\s*$/s', $set_command['value'], $matches)) {
												// set to quoted string
												$set_value = $matches[1];
											} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_command['value'])) {
												// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
												$eval_result = @eval("\$set_value = {$set_command['value']};");
												if($eval_result===false) {
													// there was an error evaluating the expression... set the value to the expression string
													$set_value = $set_command['value'];
												}
											} else {
												$set_value = '';
											}
											$this->interpreter->apply_context_stack($set_value, $context_stack);
											$new_row[$set_command['key']] = $set_value;
											$this->local_variables["{$set_command['bucket']}.{$set_command['key']}"] = $new_row[$set_command['key']];
										}
									}
								}
								// process any ROUND directives from the CREATE RECORD block
								if(isset($directives['round']) && is_array($directives['round'])) {
									foreach($directives['round'] as $round_command) {
										if($round_command['bucket']==$directives['as']) {
											if(isset($round_command['decimals'])) {
												$new_row[$round_command['key']] = round($new_row[$round_command['key']], $round_command['decimals']);
											}
											$this->local_variables["{$round_command['bucket']}.{$round_command['key']}"] = $new_row[$round_command['key']];
										}
									}
								}
								// initialize a Google Sheet API client
								$this->core->validate_google_access_token();
								require_once 'ease/lib/Spreadsheet/Autoloader.php';
								$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
								$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
								Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
								$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
								// determine if the Google Sheet was referenced by "Name" or ID
								$new_spreadsheet_created = false;
								if($directives['spreadsheet_id']) {
									$spreadSheet = $spreadsheetService->getSpreadsheetById($directives['spreadsheet_id']);
									if($spreadSheet===null) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										echo 'Error!  Invalid Spreadsheet ID: ' . htmlspecialchars($directives['spreadsheet_id']);
										exit;
									}
									$google_spreadsheet_id = $directives['spreadsheet_id'];
								} elseif($directives['spreadsheet_name']) {
									$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
									$spreadSheet = $spreadsheetFeed->getByTitle($directives['spreadsheet_name']);
									if($spreadSheet===null) {
										// the supplied Google Sheet name did not match an existing Google Sheet
										// create a new Google Sheet using the supplied name
										// initialize a Google Drive API client to convert a CSV file to a Google Sheet
										require_once 'ease/lib/Google/Client.php';
										$client = new Google_Client();
										$client->setClientId($this->core->config['gapp_client_id']);
										$client->setClientSecret($this->core->config['gapp_client_secret']);
										$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
										$client->setAccessToken($this->core->config['gapp_access_token_json']);
										if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
											// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
											require_once 'ease/lib/Google/Cache/Null.php';
											$cache = new Google_Cache_Null($client);
											$client->setCache($cache);
										} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
											// an external memcache host was configured, pass on that configuration to the Google API Client
											$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
											if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
												$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
											} else {
												$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
											}
											require_once 'ease/lib/Google/Cache/Memcache.php';
											$cache = new Google_Cache_Memcache($client);
											$client->setCache($cache);
										}
										require_once 'ease/lib/Google/Service/Drive.php';
										$service = new Google_Service_Drive($client);
										$file = new Google_Service_Drive_DriveFile();
										$file->setTitle($directives['spreadsheet_name']);
										$file->setDescription('EASE ' . $this->core->globals['system.domain']);
										$file->setMimeType('text/csv');
										// build the header row CSV string of column names
										$alphas = range('A', 'Z');
										$header_row_csv = '';
										$prefix = '';
										$column_keys = array_keys($new_row);
										sort($column_keys);
										foreach($column_keys as $column_key) {
											if(in_array(strtoupper($column_key), $alphas)) {
												$column_key = 'Column ' . strtoupper($column_key);
											}
											$header_row_csv .= $prefix . '"' . str_replace('"', '""', $column_key) . '"';
											$prefix = ', ';
										}
										// pad empty values up to column T
										$header_row_count = count($new_row);
										while($header_row_count < 19) {
											$header_row_csv .= $prefix . '""';
											$header_row_count++;
										}
										// add column for unique EASE Row ID used by the EASE core to enable row update and delete
										$header_row_csv .= $prefix . '"EASE Row ID"';
										$header_row_csv .= "\r\n";
										// add 100 blank rows
										for($i=1; $i<100; $i++) {
											$header_row_csv .= '""';
											for($j=1; $j<20; $j++) {
												$header_row_csv .= ',""';
											}
											$header_row_csv .= "\r\n";
										}
										// upload the CSV file and convert it to a Google Sheet
										$new_spreadsheet = null;
										$try_count = 0;
										while($new_spreadsheet===null && $try_count<=5) {
											if($try_count > 0) {
												// apply exponential backoff
												sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
											}
											try {
												$try_count++;
												$new_spreadsheet = $service->files->insert($file, array('data'=>$header_row_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
											} catch(Google_Service_Exception $e) {
												continue;
											}
										}
										// get the new Google Sheet ID
										$google_spreadsheet_id = $new_spreadsheet['id'];
										// check if there was an error creating the Google Sheet
										if(!$google_spreadsheet_id) {
											echo 'Error!  Unable to create Google Sheet named: ' . htmlspecialchars($directives['spreadsheet_name']);
											exit;
										}
										// load the newly created Google Sheet
										$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
										$new_spreadsheet_created = true;
									}
								}
								// check for unloaded Google Sheet
								if($spreadSheet===null) {
									echo 'Error!  Unable to load Google Sheet.';
									exit;
								}
								// load the worksheets in the Google Sheet
								$worksheetFeed = $spreadSheet->getWorksheets();
								if($directives['worksheet_name']) {
									$worksheet = $worksheetFeed->getByTitle($directives['worksheet_name']);
									if($worksheet===null) {
										// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
										$header_row = array();
										$column_keys = array_keys($new_row);
										sort($column_keys);
										foreach($column_keys as $column_key) {
											if(in_array(strtoupper($column_key), $alphas)) {
												$column_key = 'Column ' . strtoupper($column_key);
											}
											$header_row[] = $column_key;
										}
										// pad empty values up to column T
										$header_row_count = count($column_keys);
										while($header_row_count < 19) {
											$header_row[] = '';
											$header_row_count++;
										}
										// add column for unique EASE Row ID used by the EASE core to enable row update and delete
										$header_row[] = 'EASE Row ID';
										$new_worksheet_rows = 100;
										if(count($header_row) < 20) {
											$new_worksheet_cols = 20;
										} else {
											$new_worksheet_cols = 10 + count($header_row);
										}
										$worksheet = $spreadSheet->addWorksheet($directives['worksheet_name'], $new_worksheet_rows, $new_worksheet_cols);
										$worksheet->createHeader($header_row);
										if($new_spreadsheet_created && $directives['save_to_sheet']!='Sheet 1') {
											$worksheetFeed = $spreadSheet->getWorksheets();
											$old_worksheet = $worksheetFeed->getFirstSheet();
											$old_worksheet->delete();
										}
									}
								} else {
									$worksheet = $worksheetFeed->getFirstSheet();
								}
								// check that the worksheet was loaded
								if($worksheet===null) {
									echo 'Google Drive Spredsheet Error!  Unable to load Worksheet.';
									exit;
								}
								// get a list feed for the worksheet
								$listFeed = $worksheet->getListFeed();
								// TODO!! see if the listFeed can be stored in the global system variables so it can be reused
								// load the referenced Google Sheet
								if($directives['spreadsheet_id']) {
									// load the Google Sheet by the supplied ID
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								} elseif($directives['spreadsheet_name']) {
									// load the Google Sheet by the supplied name
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								}
								// process the new Google Sheet row to set header keys for every value
								$processed_new_row = array();
								foreach($new_row as $key=>$value) {
									$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($key));
									if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
										$processed_new_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
									} else {
										$processed_new_row[$key] = $value;
									}
								}
								// insert the new Google Sheet row, only if it has at least one non-empty value
								$new_row_has_nonempty_value = false;
								foreach($processed_new_row as $new_row_value) {
									if(trim($new_row_value)!='') {
										$new_row_has_nonempty_value = true;
										break;
									}
								}
								if($new_row_has_nonempty_value) {
									$processed_new_row['easerowid'] = $this->core->new_uuid();
									$listFeed->insert($processed_new_row);
								}
								break;
							case 'update_spreadsheet_record':
								$this->interpreter->inject_global_variables($directives['spreadsheet_id']);
								$this->interpreter->inject_global_variables($directives['spreadsheet_name']);
								$this->interpreter->inject_global_variables($directives['worksheet_name']);
								$this->interpreter->inject_global_variables($directives['row_uuid']);
								$this->interpreter->inject_global_variables($directives['as']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_id'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['worksheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['row_uuid'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['as'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['row_uuid'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['as'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['row_uuid'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['as'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								$listFeed = null;
								$updated_row = array();
								// process any SET directives from the UPDATE RECORD block
								if(isset($directives['set']) && is_array($directives['set'])) {
									foreach($directives['set'] as $set_command) {
										if($set_command['bucket']==$directives['as'] || $set_command['bucket']=='row') {
											$this->interpreter->inject_global_variables($set_command['value']);
											if(!$no_local_injection) {
												if(isset($inject_local_sql_row_variables)) {
													$this->interpreter->inject_local_sql_row_variables($set_command['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
												} elseif(isset($inject_local_spreadsheet_row_variables)) {
													$this->interpreter->inject_local_spreadsheet_row_variables($set_command['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
												} elseif(isset($inject_local_spreadsheet_variables)) {
													$this->interpreter->inject_local_spreadsheet_variables($set_command['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
												}
											}
											$context_stack = $this->interpreter->extract_context_stack($set_command['value']);
											if(preg_match('/^\s*"(.*)"\s*$/s', $set_command['value'], $matches)) {
												// set to quoted string
												$set_value = $matches[1];
											} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_command['value'])) {
												// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
												$eval_result = @eval("\$set_value = {$set_command['value']};");
												if($eval_result===false) {
													// there was an error evaluating the expression... set the value to the expression string
													$set_value = $set_command['value'];
												}
											} else {
												$set_value = '';
											}
											$this->interpreter->apply_context_stack($set_value, $context_stack);
											$updated_row[$set_command['key']] = $set_value;
											$this->local_variables["{$set_command['bucket']}.{$set_command['key']}"] = $updated_row[$set_command['key']];
										}
									}
								}
								// process any ROUND directives from the UPDATE RECORD block
								if(isset($directives['round']) && is_array($directives['round'])) {
									foreach($directives['round'] as $round_command) {
										if($round_command['bucket']==$directives['as']) {
											if(isset($round_command['decimals'])) {
												$updated_row[$round_command['key']] = round($updated_row[$round_command['key']], $round_command['decimals']);
											}
											$this->local_variables["{$round_command['bucket']}.{$round_command['key']}"] = $updated_row[$round_command['key']];
										}
									}
								}
								// initialize a Google Sheet API client
								$this->core->validate_google_access_token();
								require_once 'ease/lib/Spreadsheet/Autoloader.php';
								$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
								$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
								Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
								$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
								// determine if the Google Sheet was referenced by "Name" or ID
								$new_spreadsheet_created = false;
								if($directives['spreadsheet_id']) {
									$spreadSheet = $spreadsheetService->getSpreadsheetById($directives['spreadsheet_id']);
									if($spreadSheet===null) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										echo 'Error!  Invalid Spreadsheet ID: ' . htmlspecialchars($directives['spreadsheet_id']);
										exit;
									}
									$google_spreadsheet_id = $directives['spreadsheet_id'];
								} elseif($directives['spreadsheet_name']) {
									$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
									$spreadSheet = $spreadsheetFeed->getByTitle($directives['spreadsheet_name']);
									if($spreadSheet===null) {
										// the supplied Google Sheet name did not match an existing Google Sheet
										// create a new Google Sheet using the supplied name
										// initialize a Google Drive API client to convert a CSV file to a Google Sheet
										require_once 'ease/lib/Google/Client.php';
										$client = new Google_Client();
										$client->setClientId($this->core->config['gapp_client_id']);
										$client->setClientSecret($this->core->config['gapp_client_secret']);
										$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
										$client->setAccessToken($this->core->config['gapp_access_token_json']);
										if(isset($this->core->config['elasticache_config_endpoint']) && trim($this->core->config['elasticache_config_endpoint'])!='') {
											// the Google APIs default use of memcache is not supported while using the AWS ElastiCache Cluster Client
											require_once 'ease/lib/Google/Cache/Null.php';
											$cache = new Google_Cache_Null($client);
											$client->setCache($cache);
										} elseif(isset($this->core->config['memcache_host']) && trim($this->core->config['memcache_host'])!='') {
											// an external memcache host was configured, pass on that configuration to the Google API Client
											$client->setClassConfig('Google_Cache_Memcache', 'host', $this->core->config['memcache_host']);
											if(isset($this->core->config['memcache_port']) && trim($this->core->config['memcache_port'])!='') {
												$client->setClassConfig('Google_Cache_Memcache', 'port', $this->core->config['memcache_port']);
											} else {
												$client->setClassConfig('Google_Cache_Memcache', 'port', 11211);
											}
											require_once 'ease/lib/Google/Cache/Memcache.php';
											$cache = new Google_Cache_Memcache($client);
											$client->setCache($cache);
										}
										require_once 'ease/lib/Google/Service/Drive.php';
										$service = new Google_Service_Drive($client);
										$file = new Google_Service_Drive_DriveFile();
										$file->setTitle($directives['spreadsheet_name']);
										$file->setDescription('EASE ' . $this->core->globals['system.domain']);
										$file->setMimeType('text/csv');
										// build the header row CSV string of column names
										$alphas = range('A', 'Z');
										$header_row_csv = '';
										$prefix = '';
										$column_keys = array_keys($updated_row);
										sort($column_keys);
										foreach($column_keys as $column_key) {
											if(in_array(strtoupper($column_key), $alphas)) {
												$column_key = 'Column ' . strtoupper($column_key);
											}
											$header_row_csv .= $prefix . '"' . str_replace('"', '""', $column_key) . '"';
											$prefix = ', ';
										}
										// pad empty values up to column T
										$header_row_count = count($updated_row);
										while($header_row_count < 19) {
											$header_row_csv .= $prefix . '""';
											$header_row_count++;
										}
										// add column for unique EASE Row ID used by the EASE core to enable row update and delete
										$header_row_csv .= $prefix . '"EASE Row ID"';
										$header_row_csv .= "\r\n";
										// add 100 blank rows
										for($i=1; $i<100; $i++) {
											$header_row_csv .= '""';
											for($j=1; $j<20; $j++) {
												$header_row_csv .= ',""';
											}
											$header_row_csv .= "\r\n";
										}
										// upload the CSV file and convert it to a Google Sheet
										$new_spreadsheet = null;
										$try_count = 0;
										while($new_spreadsheet===null && $try_count<=5) {
											if($try_count > 0) {
												// apply exponential backoff
												sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
											}
											try {
												$try_count++;
												$new_spreadsheet = $service->files->insert($file, array('data'=>$header_row_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
											} catch(Google_Service_Exception $e) {
												continue;
											}
										}
										// get the new Google Sheet ID
										$google_spreadsheet_id = $new_spreadsheet['id'];
										// check if there was an error creating the Google Sheet
										if(!$google_spreadsheet_id) {
											echo 'Error!  Unable to create Google Sheet named: ' . htmlspecialchars($directives['spreadsheet_name']);
											exit;
										}
										// load the newly created Google Sheet
										$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
										$new_spreadsheet_created = true;
									}
								}
								// check for unloaded Google Sheet
								if($spreadSheet===null) {
									echo 'Error!  Unable to load Google Sheet.';
									exit;
								}
								// load the worksheets in the Google Sheet
								$worksheetFeed = $spreadSheet->getWorksheets();
								if(trim($directives['worksheet_name'])!='') {
									$worksheet = $worksheetFeed->getByTitle($directives['worksheet_name']);
									if($worksheet===null) {
										// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
										$header_row = array();
										$column_keys = array_keys($updated_row);
										sort($column_keys);
										foreach($column_keys as $column_key) {
											if(in_array(strtoupper($column_key), $alphas)) {
												$column_key = 'Column ' . strtoupper($column_key);
											}
											$header_row[] = $column_key;
										}
										// pad empty values up to column T
										$header_row_count = count($column_keys);
										while($header_row_count < 19) {
											$header_row[] = '';
											$header_row_count++;
										}
										// add column for unique EASE Row ID used by the EASE core to enable row update and delete
										$header_row[] = 'EASE Row ID';
										$new_worksheet_rows = 100;
										if(count($header_row) < 20) {
											$new_worksheet_cols = 20;
										} else {
											$new_worksheet_cols = 10 + count($header_row);
										}
										$worksheet = $spreadSheet->addWorksheet($directives['worksheet_name'], $new_worksheet_rows, $new_worksheet_cols);
										$worksheet->createHeader($header_row);
										if($new_spreadsheet_created && $directives['save_to_sheet']!='Sheet 1') {
											$worksheetFeed = $spreadSheet->getWorksheets();
											$old_worksheet = $worksheetFeed->getFirstSheet();
											$old_worksheet->delete();
										}
									}
								} else {
									$worksheet = $worksheetFeed->getFirstSheet();
								}
								// check that the worksheet was loaded
								if($worksheet===null) {
									echo 'Google Drive Spredsheet Error!  Unable to load Worksheet.';
									exit;
								}
								// load the referenced Google Sheet
								if($directives['spreadsheet_id']) {
									// load the Google Sheet by the supplied ID
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								} elseif($directives['spreadsheet_name']) {
									// load the Google Sheet by the supplied name
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								}
								// check if any values were set to update the Google Sheet Row
								if(isset($updated_row)) {
									// new values found... update the Google Sheet Row
									foreach($updated_row as $key=>$value) {
										$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($key));
										if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
											// the column was referenced by column letter... change it to reference the column header name
											unset($updated_row[$key]);
											$updated_row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
										} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
											// the column was referenced by an existing column header name
										} else {
											// the referenced column wasn't found in the cached Google Sheet meta data...
											//	dump the cache and reload it, then check again... if it still isn't found, create it
											// TODO!! consolidate all the code that does this into a core function
										}
									}
									// query for the row to update
									$listFeed = $worksheet->getListFeed('', '', "easerowid = \"{$directives['row_uuid']}\"");
									$listEntries = $listFeed->getEntries();
									// update the row that matched the requested EASE Row ID value
									foreach($listEntries as $listEntry) {
										$listEntry->update($updated_row);
									}
									// TODO!! if the record wasn't found, create a new record instead
								}
								break;
							case 'delete_spreadsheet_record':
								$this->interpreter->inject_global_variables($directives['spreadsheet_id']);
								$this->interpreter->inject_global_variables($directives['spreadsheet_name']);
								$this->interpreter->inject_global_variables($directives['worksheet_name']);
								$this->interpreter->inject_global_variables($directives['row_uuid']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_id'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['spreadsheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['worksheet_name'], $params['row'], $params['sql_table_name'], $this->local_variables);
										$this->interpreter->inject_local_sql_row_variables($directives['row_uuid'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['row_uuid'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_id'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['spreadsheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['worksheet_name'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										$this->interpreter->inject_local_spreadsheet_variables($directives['row_uuid'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								// initialize a Google Sheet API client
								$this->core->validate_google_access_token();
								require_once 'ease/lib/Spreadsheet/Autoloader.php';
								$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
								$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
								Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
								$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
								// determine if the Google Sheet was referenced by "Name" or ID
								$new_spreadsheet_created = false;
								if($directives['spreadsheet_id']) {
									$spreadSheet = $spreadsheetService->getSpreadsheetById($directives['spreadsheet_id']);
									if($spreadSheet===null) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										// the Google Sheet doesn't exist... nothing to delete
										break;
									}
									$google_spreadsheet_id = $directives['spreadsheet_id'];
								} elseif($directives['spreadsheet_name']) {
									$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
									$spreadSheet = $spreadsheetFeed->getByTitle($directives['spreadsheet_name']);
									if($spreadSheet===null) {
										// the Google Sheet doesn't exist... nothing to delete
										break;
									}
								}
								// check for unloaded Google Sheet
								if($spreadSheet===null) {
									// the Google Sheet doesn't exist... nothing to delete
									break;
								}
								// load the worksheets in the Google Sheet
								$worksheetFeed = $spreadSheet->getWorksheets();
								if(trim($directives['worksheet_name'])!='') {
									$worksheet = $worksheetFeed->getByTitle($directives['worksheet_name']);
									if($worksheet===null) {
										// the worksheet doesn't exist... nothing to delete
										break;
									}
								} else {
									$worksheet = $worksheetFeed->getFirstSheet();
								}
								// check that the worksheet was loaded
								if($worksheet===null) {
									// the worksheet doesn't exist... nothing to delete
									break;
								}
								// load the referenced Google Sheet
								if($directives['spreadsheet_id']) {
									// load the Google Sheet by the supplied ID
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($directives['spreadsheet_id'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								} elseif($directives['spreadsheet_name']) {
									// load the Google Sheet by the supplied name
									$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
									if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
										$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id']);
										$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($directives['spreadsheet_name'], $directives['worksheet_name']);
										if(!isset($spreadsheet_meta_data['column_name_by_letter'])) {
											echo 'Google Sheet Error!  Unable to load mappings.';
											exit;
										}
									}
								}
								// query for the row to delete
								$listFeed = $worksheet->getListFeed('', '', "easerowid = \"{$directives['row_uuid']}\"");
								$listEntries = $listFeed->getEntries();
								// delete all rows that matched the requested EASE Row ID value
								foreach($listEntries as $listEntry) {
									$listEntry->delete();
								}
								break;
							case 'apply_sql_record':
								$this->core->html_dump($directives, 'apply_sql_record directives');
								break;
							case 'create_sql_record':
								if($this->core->db && !$this->core->db_disabled) {
									$this->interpreter->inject_global_variables($directives['for']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['for'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['for'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['for'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									$for_parts = explode('.', trim($directives['for']), 2);
									if(count($for_parts)==2) {
										$directives['for_bucket'] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($for_parts[0])));
										$directives['for_key'] = ltrim($for_parts[1]);
									} else {
										$directives['for_bucket'] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($directives['for'])));
										$directives['for_key'] = $this->core->new_uuid();
									}
									$new_row = array();
									if(isset($directives['set']) && is_array($directives['set'])) {
										foreach($directives['set'] as $set_command) {
											if($set_command['bucket']==$directives['as'] || $set_command['bucket']=='') {
												$this->interpreter->inject_global_variables($set_command['value']);
												if(!$no_local_injection) {
													if(isset($inject_local_sql_row_variables)) {
														$this->interpreter->inject_local_sql_row_variables($set_command['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
													} elseif(isset($inject_local_spreadsheet_row_variables)) {
														$this->interpreter->inject_local_spreadsheet_row_variables($set_command['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
													} elseif(isset($inject_local_spreadsheet_variables)) {
														$this->interpreter->inject_local_spreadsheet_variables($set_command['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
													}
												}
												$context_stack = $this->interpreter->extract_context_stack($set_command['value']);
												if(preg_match('/^\s*"(.*)"\s*$/s', $set_command['value'], $matches)) {
													// set to quoted string
													$set_value = $matches[1];
												} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_command['value'])) {
													// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
													$eval_result = @eval("\$set_value = {$set_command['value']};");
													if($eval_result===false) {
														// there was an error evaluating the expression... set the value to the expression string
														$set_value = $set_command['value'];
													}
												} else {
													$set_value = $set_command['value'];
												}
												$this->interpreter->apply_context_stack($set_value, $context_stack);
												$new_row[$set_command['key']] = $set_value;
												$this->local_variables["{$set_command['bucket']}.{$set_command['key']}"] = $new_row[$set_command['key']];
											}
										}
									}
									if(isset($directives['round']) && is_array($directives['round'])) {
										foreach($directives['round'] as $round_command) {
											if($round_command['bucket']==$directives['as']) {
												if(isset($round_command['decimals'])) {
													$new_row[$round_command['key']] = round($new_row[$round_command['key']], $round_command['decimals']);
												}
												$this->local_variables["{$round_command['bucket']}.{$round_command['key']}"] = $new_row[$round_command['key']];
											}
										}
									}
									// create the record in the database
									$create_params = array();
									$create_columns_sql = '';
									foreach($new_row as $key=>$value) {
										$create_columns_sql .= ",`$key`=:$key";
										$create_params[":$key"] = (string)$value;
									}
									$create_params[':uuid'] = $directives['for_key'];
									$namespaced_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $directives['for_bucket']), '_');
									$query = $this->core->db->prepare(" REPLACE INTO `{$namespaced_for_sql_table_name}`
																		SET uuid=:uuid
																			$create_columns_sql;	");
									// execute the create query, then check for any query errors
									$result = $query->execute($create_params);
									if(!$result) {
										// there was a query error... make sure the SQL table exists and has all the columns referenced in the new row
										$result = $this->core->db->query("DESCRIBE `{$namespaced_for_sql_table_name}`;");
										if($result) {
											// the SQL table exists; make sure all of the columns referenced in the new row exist in the table
											$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
											foreach(array_keys($new_row) as $column) {
												if(!in_array($column, $existing_columns)) {
													$this->core->db->exec("ALTER TABLE `{$namespaced_for_sql_table_name}` ADD COLUMN `$column` text NOT NULL default '';");
												}
											}
										} else {
											// the SQL table doesn't exist; create it with all of the columns referenced in the new row
											$custom_columns_sql = '';
											foreach(array_keys($new_row) as $column) {
												if(!in_array($column, $this->core->reserved_sql_columns)) {
													$custom_columns_sql .= ", `$column` text NOT NULL default ''";
												}
											}
											$sql = "CREATE TABLE `{$namespaced_for_sql_table_name}` (
														instance_id int NOT NULL PRIMARY KEY auto_increment,
														created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
														updated_on timestamp NOT NULL,
														uuid varchar(32) NOT NULL UNIQUE
														$custom_columns_sql
													);	";
											$this->core->db->exec($sql);
										}
										// reattempt to execute the original create query
										$result = $query->execute($create_params);
										if(!$result) {
											// another query error... this should never happen
											// TODO!! log an error
										}
									}
								}
								break;
							case 'update_sql_record':
								if($this->core->db && !$this->core->db_disabled) {
									$this->interpreter->inject_global_variables($directives['for']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['for'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['for'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['for'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									$for_parts = explode('.', trim($directives['for']), 2);
									if(count($for_parts)==2) {
										$directives['for_bucket'] = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($for_parts[0])));
										$directives['for_key'] = ltrim($for_parts[1]);
									} else {
										$directives['for_bucket'] = $this->local_variables['sql_table_name'];
										$directives['for_key'] = trim($directives['for']);
									}
									if($directives['for_bucket']==$this->local_variables['sql_table_name']) {
										if(isset($directives['set']) && is_array($directives['set'])) {
											$this->local_variables['update_sql_columns_already_added'] = array();
											foreach($directives['set'] as $set_command) {
												if($set_command['bucket']==$directives['as']) {
													if((!isset($params['row'][$set_command['key']])) && !in_array($set_command['key'], $this->local_variables['update_sql_columns_already_added'])) {
														$result = $this->core->db->exec("ALTER TABLE `{$this->local_variables['sql_table_name']}` ADD COLUMN `{$set_command['key']}` text NOT NULL default '';");
														$this->local_variables['update_sql_columns_already_added'][] = $set_command['key'];
													}
													$this->interpreter->inject_global_variables($set_command['value']);
													if(!$no_local_injection) {
														if(isset($inject_local_sql_row_variables)) {
															$this->interpreter->inject_local_sql_row_variables($set_command['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
														} elseif(isset($inject_local_spreadsheet_row_variables)) {
															$this->interpreter->inject_local_spreadsheet_row_variables($set_command['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
														} elseif(isset($inject_local_spreadsheet_variables)) {
															$this->interpreter->inject_local_spreadsheet_variables($set_command['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
														}
													}
													$context_stack = $this->interpreter->extract_context_stack($set_command['value']);
													if(preg_match('/^\s*"(.*)"\s*$/s', $set_command['value'], $matches)) {
														// set to quoted string
														$set_value = $matches[1];
													} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_command['value'])) {
														// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
														$eval_result = @eval("\$set_value = {$set_command['value']};");
														if($eval_result===false) {
															// there was an error evaluating the expression... set the value to the expression string
															$set_value = $set_command['value'];
														}
													} else {
														$set_value = $set_command['value'];
													}
													$this->interpreter->apply_context_stack($set_value, $context_stack);
													$params['row']["{$this->local_variables['sql_table_name']}.{$set_command['key']}"] = $set_value;
													$params['rows'][$params['row_key']]["{$this->local_variables['sql_table_name']}.{$set_command['key']}"] = $set_value;
													$this->local_variables["{$set_command['bucket']}.{$set_command['key']}"] = $params['row']["{$this->local_variables['sql_table_name']}.{$set_command['key']}"];
												}
											}
										}
										if(isset($directives['round']) && is_array($directives['round'])) {
											foreach($directives['round'] as $round_command) {
												if($round_command['bucket']==$directives['as']) {
													if(isset($round_command['decimals'])) {
														$params['row']["{$this->local_variables['sql_table_name']}.{$round_command['key']}"] = round($params['row']["{$this->local_variables['sql_table_name']}.{$round_command['key']}"], $round_command['decimals']);
													}
													$this->local_variables["{$round_command['bucket']}.{$round_command['key']}"] = $params['row']["{$this->local_variables['sql_table_name']}.{$round_command['key']}"];
												}
											}
										}
										// update the record in the database
										$update_params = array();
										$update_columns_sql = '';
										foreach($params['row'] as $bucket_key=>$value) {
											$bucket_key_parts = explode('.', $bucket_key, 2);
											if(count($bucket_key_parts)==2) {
												$key = $bucket_key_parts[1];
												$update_columns_sql .= ",`$key`=:$key";
												$update_params[":$key"] = (string)$value;
											} else {
												$update_columns_sql .= ",`$bucket_key`=:$bucket_key";
												$update_params[":$bucket_key"] = (string)$value;
											}
										}
										$update_params[':uuid'] = $directives['for_key'];
										$namespaced_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $directives['for_bucket']), '_');
										$query = $this->core->db->prepare(" UPDATE `$namespaced_for_sql_table_name`
																			SET updated_on=NOW()
																				$update_columns_sql
																			WHERE uuid=:uuid;	");
										$result = $query->execute($update_params);
									} else {
										// the update record for bucket does not match the SQL Table being listed
										$update_row = array();
										if(isset($directives['set']) && is_array($directives['set'])) {
											foreach($directives['set'] as $set_command) {
												if($set_command['bucket']==$directives['as']) {
													$this->interpreter->inject_global_variables($set_command['value']);
													if(!$no_local_injection) {
														if(isset($inject_local_sql_row_variables)) {
															$this->interpreter->inject_local_sql_row_variables($set_command['value'], $params['row'], $params['sql_table_name'], $this->local_variables);
														} elseif(isset($inject_local_spreadsheet_row_variables)) {
															$this->interpreter->inject_local_spreadsheet_row_variables($set_command['value'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
														} elseif(isset($inject_local_spreadsheet_variables)) {
															$this->interpreter->inject_local_spreadsheet_variables($set_command['value'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
														}
													}
													$context_stack = $this->interpreter->extract_context_stack($set_command['value']);
													if(preg_match('/^\s*"(.*)"\s*$/s', $set_command['value'], $matches)) {
														// set to quoted string
														$set_value = $matches[1];
													} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_command['value'])) {
														// possible math expression (safe to evaluate), attempt to evaluate the expression to calculate the result value
														$eval_result = @eval("\$set_value = {$set_command['value']};");
														if($eval_result===false) {
															// there was an error evaluating the expression... set the value to the expression string
															$set_value = $set_command['value'];
														}
													} else {
														$set_value = '';
													}
													$this->interpreter->apply_context_stack($set_value, $context_stack);
													$update_row[$set_command['key']] = $set_value;
													$this->local_variables["{$set_command['bucket']}.{$set_command['key']}"] = $update_row[$set_command['key']];
												}
											}
										}
										if(isset($directives['round']) && is_array($directives['round'])) {
											foreach($directives['round'] as $round_command) {
												if($round_command['bucket']==$directives['as']) {
													if(isset($round_command['decimals'])) {
														$update_row[$round_command['key']] = round($update_row[$round_command['key']], $round_command['decimals']);
													}
													$this->local_variables["{$round_command['bucket']}.{$round_command['key']}"] = $update_row[$round_command['key']];
												}
											}
										}
										// update the record in the database
										$update_params = array();
										$update_columns_sql = '';
										foreach($update_row as $key=>$value) {
											$update_columns_sql .= ",`$key`=:$key";
											$update_params[":$key"] = (string)$value;
										}
										$update_params[':uuid'] = $directives['for_key'];
										$namespaced_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $directives['for_bucket']), '_');
										$query = $this->core->db->prepare(" UPDATE `$namespaced_for_sql_table_name`
																			SET updated_on=NOW()
																				$update_columns_sql
																			WHERE uuid=:uuid;	");
										$query->execute($update_params);
										// TODO!! check for query errors and add any missing tables or columns
									}
								}
								break;
							case 'delete_sql_record':
								if($this->core->db && !$this->core->db_disabled) {
									$this->interpreter->inject_global_variables($directives['for']);
									if(!$no_local_injection) {
										if(isset($inject_local_sql_row_variables)) {
											$this->interpreter->inject_local_sql_row_variables($directives['for'], $params['row'], $params['sql_table_name'], $this->local_variables);
										} elseif(isset($inject_local_spreadsheet_row_variables)) {
											$this->interpreter->inject_local_spreadsheet_row_variables($directives['for'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
										} elseif(isset($inject_local_spreadsheet_variables)) {
											$this->interpreter->inject_local_spreadsheet_variables($directives['for'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
										}
									}
									$for_parts = explode('.', trim($directives['for']), 2);
									if(count($for_parts)==2) {
										$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($for_parts[0])));
										$key = ltrim($for_parts[1]);
									} else {
										$bucket = $this->local_variables['sql_table_name'];
										$key = trim($directives['for']);
									}
									$namespaced_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $bucket), '_');
									// delete the record from the database
									$query = $this->core->db->prepare("DELETE FROM `$namespaced_for_sql_table_name` WHERE uuid=:uuid;");
									$query->execute(array(':uuid'=>$key));
								}
								break;
							case 'apply_row':
								$this->interpreter->inject_global_variables($directives['as']);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives['as'], $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives['as'], $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives['as'], $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								if(!in_array(strtolower($directives['as']), $this->core->reserved_buckets)) {
									$bucket_name = strtolower($directives['as']) . '.';
									foreach($this->local_variables['row'] as $key=>$value) {
										$this->core->globals["$bucket_name.$key"] = $value;
									}
								}
								break;
							case 'empty_bucket':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								if(!in_array(strtolower($directives), $this->core->reserved_buckets)) {
									$bucket_name = strtolower($directives) . '.';
									$bucket_name_length = strlen($bucket_name);
									// process every global variable looking for the referenced bucket
									foreach($this->core->globals as $key=>$value) {
										if(substr($key, 0, $bucket_name_length)==$bucket_name) {
											// a global variable was found matching the referenced bucket... unset it
											unset($this->core->globals[$key]);
										}
									}
								}
								break;
							case 'empty_local_bucket':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								unset($this->local_variables[strtolower($directives)]);
								break;
							case 'print_bucket':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								if(!in_array(strtolower($directives), $this->core->reserved_buckets)) {
									$bucket_name = strtolower($directives) . '.';
									$bucket_name_length = strlen($bucket_name);
									// process every global variable looking for the referenced bucket
									$bucket_array = array();
									foreach($this->core->globals as $key=>$value) {
										if(substr($key, 0, $bucket_name_length)==$bucket_name) {
											// a global variable was found matching the referenced bucket
											$bucket_pointer = &$bucket_array;							
											// process the key to build an associative array of the bucket
											// indexes are denoted with a period .  (ex: my_bucket.name, my_bucket.address.city)
											$key_parts = explode('.', $key);
											unset($key_parts[0]);
											foreach($key_parts as $key_part) {
												if(!isset($bucket_pointer[$key_part])) {
													$bucket_pointer[$key_part] = null;
												}
												$bucket_pointer = &$bucket_pointer[$key_part];
											}
											$bucket_pointer = $value;
										}
									}
									$bucket_dump = print_r($bucket_array, true);
									$this->output_buffer .= '<pre style="tab-size:4;">';
									$this->output_buffer .= "<b>" . htmlspecialchars($directives) . ":</b> ";
									$this->output_buffer .= htmlspecialchars($bucket_dump);
									$this->output_buffer .= '</pre>';
								}
								break;
							case 'print_local_bucket':
								$this->interpreter->inject_global_variables($directives);
								if(!$no_local_injection) {
									if(isset($inject_local_sql_row_variables)) {
										$this->interpreter->inject_local_sql_row_variables($directives, $params['row'], $params['sql_table_name'], $this->local_variables);
									} elseif(isset($inject_local_spreadsheet_row_variables)) {
										$this->interpreter->inject_local_spreadsheet_row_variables($directives, $params['column_letter_by_name'], $params['cell_value_by_column_letter'], $this->local_variables, 'html');
									} elseif(isset($inject_local_spreadsheet_variables)) {
										$this->interpreter->inject_local_spreadsheet_variables($directives, $params['column_letter_by_name'], $params['cells_by_row_by_column_letter'], 'html');
									}
								}
								// process every local variable looking for the referenced bucket
								$bucket_array = $this->local_variables[strtolower($directives)];
								$bucket_dump = print_r($bucket_array, true);
								$this->output_buffer .= '<pre style="tab-size:4;">';
								$this->output_buffer .= "<b>" . htmlspecialchars($directives) . ":</b> ";
								$this->output_buffer .= htmlspecialchars($bucket_dump);
								$this->output_buffer .= '</pre>';
								break;
							default:
							// unrecognized token
						}
					}
				}
			}
		}
	}

	// this function is called at most once per request to inject pager style when an EASE List with a pager is processed
	function inject_pager_style() {
		if(!isset($this->core->globals['system.included_default_pager_style'])) {
			// default the pager colors
			if(!isset($this->core->globals['system.pager-link-box-color'])) {
				$this->core->globals['system.pager-link-box-color'] = '#9AAFE5';
			}
			if(!isset($this->core->globals['system.pager-link-number-color'])) {
				$this->core->globals['system.pager-link-number-color'] = '#0E509E';
			}
			if(!isset($this->core->globals['system.pager-link-hover-box-color'])) {
				$this->core->globals['system.pager-link-hover-box-color'] = '#0E509E';
			}
			if(!isset($this->core->globals['system.pager-prevnext-off-box-color'])) {
				$this->core->globals['system.pager-prevnext-off-box-color'] = '#DEDEDE';
			}
			if(!isset($this->core->globals['system.pager-prevnext-off-font-color'])) {
				$this->core->globals['system.pager-prevnext-off-font-color'] = '#888888';
			}
			if(!isset($this->core->globals['system.pager-active-background-color'])) {
				$this->core->globals['system.pager-active-background-color'] = '#2E6AB1';
			}
			if(!isset($this->core->globals['system.pager-active-number-color'])) {
				$this->core->globals['system.pager-active-number-color'] = '#FFFFFF';
			}
			$this->output_buffer .= "
<style>
	#ease-pager { border:0; margin:0; padding:0; height:22px; margin-bottom:3px; margin-top:5px; }
	#ease-pager li { border:0; margin:0; padding:0; font-size:11px; list-style:none; margin-right:2px; }
	#ease-pager a { border:solid 1px {$this->core->globals['system.pager-link-box-color']}; margin-right:2px; }
	#ease-pager a:link,
	#ease-pager a:visited { color:{$this->core->globals['system.pager-link-number-color']}; display:block; float:left; padding:3px 6px; text-decoration:none; }
	#ease-pager a:hover { border:solid 1px {$this->core->globals['system.pager-link-hover-box-color']}; }
	#ease-pager .ease-pager-previous-off,
	#ease-pager .ease-pager-next-off { border:hidden 1px {$this->core->globals['system.pager-prevnext-off-box-color']}; color:{$this->core->globals['system.pager-prevnext-off-font-color']}; display:block; float:left; font-weight:bold; margin-right:2px; padding:4px 6px; -webkit-touch-callout:none; -webkit-user-select:none; -khtml-user-select:none; -moz-user-select:none; -ms-user-select:none; user-select:none; }
	#ease-pager .ease-pager-next a,
	#ease-pager .ease-pager-previous a { border:hidden 1px; font-weight:bold; }
	#ease-pager .ease-pager-next a:link,
	#ease-pager .ease-pager-previous a:link,
	#ease-pager .ease-pager-next a:visited,
	#ease-pager .ease-pager-previous a:visited { padding:4px 6px; }
	#ease-pager .ease-pager-next a:hover,
	#ease-pager .ease-pager-previous a:hover { border:solid 1px {$this->core->globals['system.pager-link-hover-box-color']}; padding:3px 5px; }
	#ease-pager .ease-pager-active { background:{$this->core->globals['system.pager-active-background-color']}; color:{$this->core->globals['system.pager-active-number-color']}; font-weight:bold; display:block; float:left; padding:4px 7px; -webkit-touch-callout:none; -webkit-user-select:none; -khtml-user-select:none; -moz-user-select:none; -ms-user-select:none; user-select:none; }
	#ease-pager .ease-pager-gap { border:hidden 1px; display:block; float:left; padding:3px 4px; -webkit-touch-callout:none; -webkit-user-select:none; -khtml-user-select:none; -moz-user-select:none; -ms-user-select:none; user-select:none; }
</style>
";
			$this->core->globals['system.included_default_pager_style'] = true;
		}
	}

	function build_pager_html($rows_per_page, $total_rows, $current_page, $query_string_without_index, $total_pages=null) {
		if($total_pages==null) {
			// the number of pages was not precalculated, calculate it now
			if($rows_per_page > 0) {
				$total_pages = ceil($total_rows / $rows_per_page);
			} else {
				$total_pages = 1;
			}
		}
		if($total_pages > 1) {
			if(trim($query_string_without_index)!='') {
				$page_url = "?$query_string_without_index&index=";
			} else {
				$page_url = "?index=";
			}
			$pager_html = "\n<ul id='ease-pager'>\n";
			if($current_page > 1) {
				$pager_html .= "	<li class='ease-pager-previous'><a href='$page_url" . ($current_page - 1) . "'>« Previous</a></li>\n";
			} else {
				$pager_html .= "	<li class='ease-pager-previous-off'>« Previous</li>\n";
			}
			for($i=1; $i<=$total_pages; $i++) {
				if($i==$current_page) {
					$pager_html .= "	<li class='ease-pager-active'>$i</li>\n";
				} else {
					if($total_pages > 13) {
						if($current_page < 7) {
							// show a  ⋯ gap to hide pages from 11 through the 3rd to last
							if($i==11) {
								$pager_html .= "	<li class='ease-pager-gap'>⋯</li>\n";
							}
							if(($i>=11) && ($i<=($total_pages - 2))) {
								continue;
							}
						} elseif(($total_pages - $current_page) < 6) {
							// show a  ⋯ gap to hide pages 3 through 9th from last
							if($i==3) {
								$pager_html .= "	<li class='ease-pager-gap'>⋯</li>\n";
							}
							if(($i>=3) && ($i<=($total_pages - 10))) {
								continue;
							}
						} elseif((!($i==3 && $current_page==7)) && (!($i==($total_pages - 2) && $current_page==($total_pages - 6)))) {
							// show a  ⋯ gap to hide pages 3 through 4th before current page, and 4th after current page through 3rd to last
							if(($i==3) || ($i==($current_page + 4))) {
								$pager_html .= "	<li class='ease-pager-gap'>⋯</li>\n";
							}
							if((($i>=3) && ($i<=($current_page - 4))) || (($i>=($current_page + 4)) && ($i<=($total_pages - 2)))) {
								continue;
							}
						}
					}
					$pager_html .= "	<li><a href='$page_url" . $i . "'>$i</a></li>\n";
				}
			}
			if($total_pages > $current_page) {
				$pager_html .= "	<li class='ease-pager-next'><a href='$page_url" . ($current_page + 1) . "'>Next »</a></li>\n";
			} else {
				$pager_html .= "	<li class='ease-pager-next-off'>Next »</li>\n";
			}
			$pager_html .= "</ul>\n<div style='clear:both; margin:0px; padding:0px; border:0px;'></div>\n";
		} else {
			$pager_html = "\n<div style='clear:both; margin:5px; padding:0px; border:0px;'></div>\n";
		}
		return $pager_html;
	}

	################################################################################
	##	EASE Framework Functions
	##
	##	the following functions are all made globally callable from EASE PHP Blocks

	function ease_get_value($name) {
		$name = trim($name);
		$name_lower = strtolower($name);
		$bucket_key_parts = explode('.', $name, 2);
		if(count($bucket_key_parts)==2) {
			$bucket = strtolower(rtrim($bucket_key_parts[0]));
			$key = ltrim($bucket_key_parts[1]);
			$key_lower = strtolower($key);
			if($bucket=='session') {
				if(isset($_SESSION[$key])) {
					return $_SESSION[$key];
				} elseif(isset($_SESSION[$key_lower])) {
					return $_SESSION[$key_lower];
				} else {
					return '';
				}
			} elseif($bucket=='cookie') {
				if(isset($_COOKIE[$key])) {
					return $_COOKIE[$key];
				} elseif(isset($_COOKIE[$key_lower])) {
					return $_COOKIE[$key_lower];
				} else {
					return '';
				}
			} elseif($bucket=='url') {
				if(is_array($this->override_url_params)) {
					return @$this->override_url_params[$key];
				} else {
					return @$_GET[$key];
				}
			} elseif($bucket=='request') {
				return @$_REQUEST[$key];
			} elseif($bucket=='config') {
				return @$this->core->config[$key];
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
				return @$spreadsheet_meta_data['id'];
			} elseif($bucket=='spreadsheet_name_by_id') {
				$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($key);
				return @$spreadsheet_meta_data['name'];
			} elseif(isset($this->local_variables['sql_table_name']) && $bucket==$this->local_variables['sql_table_name']) {
				if($key_lower=='id') {
					$key = 'uuid';
				}
				return @$this->local_variables['row']["$bucket.$key"];
			} elseif(isset($this->local_variables[$name])) {
				return $this->local_variables[$name];
			} elseif(isset($this->local_variables[$name_lower])) {
				return $this->local_variables[$name_lower];
			} elseif(isset($this->core->globals[$name])) {
				return $this->core->globals[$name];
			} elseif(isset($this->core->globals[$name_lower])) {
				return $this->core->globals[$name_lower];
			} elseif($this->core->namespace!='' && $this->core->memcache && (($value = $this->core->memcache->get("{$this->core->namespace}.{$name}"))!==false)) {
				return($value);
			} elseif($this->core->namespace!='' && $this->core->memcache && (($value = $this->core->memcache->get("{$this->core->namespace}.{$name_lower}"))!==false)) {
				return($value);
			} elseif($this->core->memcache && (($value = $this->core->memcache->get($name))!==false)) {
				return($value);
			} elseif($this->core->memcache && (($value = $this->core->memcache->get($name_lower))!==false)) {
				return($value);
			}
		} else {
			// the variable name did not contain a '.' character...
			// treat the entire name as a global key
			if(isset($this->core->globals[$name])) {
				return $this->core->globals[$name];
			}
			if(isset($this->core->globals[$name_lower])) {
				return $this->core->globals[$name_lower];
			}
			if(isset($this->local_variables[$name])) {
				return $this->local_variables[$name];
			}
			if(isset($this->local_variables[$name_lower])) {
				return $this->local_variables[$name_lower];
			}
			if($name_lower=='id') {
				$name = $name_lower = 'uuid';
			}
			if(isset($this->local_variables['row'][$name])) {
				return $this->local_variables['row'][$name];
			}
			if(isset($this->local_variables['row'][$name_lower])) {
				return $this->local_variables['row'][$name_lower];
			}
			if(isset($this->local_variables['row'][$name_lower]) && isset($this->local_variables['sql_table_name'])) {
				return $this->local_variables['row']["{$this->local_variables['sql_table_name']}.$name_lower"];
			}
		}
		return '';
	}

	function ease_set_value($name, $value) {
		$name = trim($name);
		$bucket_key_parts = explode('.', $name, 2);
		if(count($bucket_key_parts)==2) {
			$bucket = strtolower(rtrim($bucket_key_parts[0]));
			$key = ltrim($bucket_key_parts[1]);
			$key_lower = strtolower($key);
			if($bucket=='session') {
				$_SESSION[$key_lower] = $value;
			} elseif($bucket=='cookie') {
				setcookie($key_lower, $value, time() + 60 * 60 * 24 * 365, '/');
				$_COOKIE[$key_lower] = $value;
			} elseif($bucket=='cache') {
				if($this->core->memcache) {
					if($this->core->namespace!='') {
						$this->core->memcache->set("{$this->core->namespace}.{$key}", $value);
					} else {
						$this->core->memcache->set($key, $value);
					}
				} else {
					return false;
				}
			} elseif($bucket=='config') {
				$this->core->config[$key] = $value;
			} elseif(!in_array($bucket, $this->core->reserved_buckets)) {
				$this->core->globals["{$bucket}.{$key_lower}"] = $value;
			} else {
				return false;
			}
		} elseif(!in_array(strtolower($name), $this->core->reserved_buckets)) {
			// the variable name did not contain a '.' character...
			// treat the entire name as a global key
			$this->core->globals[$name] = $value;
		} else {
			return false;
		}
		return $value;
	}

	function ease_html_dump($var, $label=null) {
		return $this->core->html_dump($var, $label);
	}

	function ease_new_uuid() {
		return $this->core->new_uuid();
	}

	function ease_db_query($sql) {
		return $this->core->db->query($sql);
	}

	function ease_db_exec($sql) {
		return $this->core->db->exec($sql);
	}

	function ease_db_query_params($sql, $params=array()) {
		$query = $this->core->db->prepare($sql);
		$result = $query->execute($params);
		if($result===false) {
			return false;
		} else {
			return $query;
		}
	}

	function ease_db_fetch($result) {
		if(is_object($result)) {
			return $result->fetch(PDO::FETCH_ASSOC);
		} else {
			return false;
		}
	}

	function ease_db_fetch_column($result, $column=null) {
		if(is_object($result)) {
			return $result->fetchColumn($column);
		} else {
			return false;
		}
	}

	function ease_db_fetch_all($result) {
		if(is_object($result)) {
			return $result->fetchAll(PDO::FETCH_ASSOC);
		} else {
			return array();
		}
	}

	function ease_db_get_instance_value($instance, $column) {
		$instance_parts = explode('.', $instance, 2);
		if(count($instance_parts)==2) {
			$sql_table_name = preg_replace('/[^a-z0-9-]+/s', '_', strtolower(rtrim($instance_parts[0])));
			$instance_uuid = ltrim($instance_parts[1]);
			$column = preg_replace('/[^a-z0-9-]+/is', '_', $column);
			$query = $this->core->db->prepare("SELECT `$column` FROM `$sql_table_name` WHERE uuid=:uuid;");
			$params[':uuid'] = $instance_uuid;
			if($query->execute($params)) {
				return $query->fetchColumn();
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	function ease_db_get_instance($instance) {
		$instance_parts = explode('.', $instance, 2);
		if(count($instance_parts)==2) {
			$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($instance_parts[0])), '_');
			$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($this->core->namespace . '_' . $sql_table_name)), '_');
			$instance_uuid = ltrim($instance_parts[1]);
			$column = preg_replace('/[^a-z0-9-]+/is', '_', strtolower($column));
			$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
			$params[':uuid'] = $instance_uuid;
			if($query->execute($params)) {
				return $query->fetch(PDO::FETCH_ASSOC);
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	function ease_db_set_instance_value($instance, $column, $value) {
		$instance_parts = explode('.', $instance, 2);
		if(count($instance_parts)==2) {
			$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($instance_parts[0])), '_');
			$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($this->core->namespace . '_' . $sql_table_name)), '_');
			$instance_uuid = ltrim($instance_parts[1]);
			$column = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($column)), '_');
			$query = $this->core->db->prepare("UPDATE `$namespaced_sql_table_name` SET `$column`=:$column WHERE uuid=:uuid;");
			$params[":$column"] = (string)$value;
			$params[':uuid'] = $instance_uuid;
			$result = $query->execute($params);
			if(!$result) {
				// the query failed... attempt to add the column then try again
				$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text NOT NULL default '';");
				$result = $query->execute($params);
				if(!$result) {
					// the query failed again... attempt to create the table with the referenced column then try again
					$sql = "CREATE TABLE `$namespaced_sql_table_name` (
								instance_id int NOT NULL PRIMARY KEY auto_increment,
								created_on timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
								updated_on timestamp NOT NULL,
								uuid varchar(32) NOT NULL UNIQUE,
								`$column` text NOT NULL default ''
							);	";
					$this->core->db->exec($sql);
					$result = $query->execute($params);
				}
			}
			return $result;
		} else {
			return false;
		}
	}

	function ease_db_create_instance($sql_table_name) {
		$sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($sql_table_name)), '_');
		$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/s', '_', strtolower($this->core->namespace . '_' . $sql_table_name)), '_');
		if(!in_array($sql_table_name, $this->core->reserved_sql_tables)) {
			$new_uuid = $this->core->new_uuid();
			$query = $this->core->db->prepare("INSERT INTO `$namespaced_sql_table_name` SET uuid=:uuid;");
			$params[':uuid'] = $new_uuid;
			if(!$query->execute($params)) {
				// the query failed... create the table and try again
				$sql = "CREATE TABLE `$namespaced_sql_table_name` (
							instance_id int NOT NULL PRIMARY KEY auto_increment,
							created_on timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
							updated_on timestamp NOT NULL,
							uuid varchar(32) NOT NULL UNIQUE
						);	";
				$this->core->db->exec($sql);
				$query->execute($params);
			}
			return $new_uuid;
		} else {
			return false;
		}
	}

	function ease_db_error() {
		$error = $this->core->db->errorInfo();
		if($error[0]=='00000') {
			// no error
			return false;
		} else {
			return $error;
		}
	}

	function ease_insert_row_into_googlesheet_by_id($row, $spreadsheet_id, $worksheet=null) {
	}

	function ease_insert_row_into_googlesheet_by_name($row, $spreadsheet_name, $worksheet=null) {
	}

	function ease_process($string) {
		$ease_core = new ease_core();
		$this->output_buffer .= $ease_core->process_ease($string, true);
		$ease_core = null;
	}

	function ease_empty_bucket($bucket) {
		if(!in_array(strtolower($bucket), $this->core->reserved_buckets)) {
			// process every global variable looking for the referenced bucket
			$bucket_name = strtolower($bucket) . '.';
			$bucket_name_length = strlen($bucket_name);
			$bucket_array = array();
			foreach($this->core->globals as $key=>$value) {
				if(substr($key, 0, $bucket_name_length)==$bucket_name) {
					// a global variable was found matching the referenced bucket
					unset($this->core->globals[$key]);
				}
			}
		}
	}

	function ease_array_to_bucket($array, $bucket) {
		if(!in_array(strtolower($bucket), $this->core->reserved_buckets)) {
			foreach($array as $key=>$value) {
				if(is_array($value)) {
					$this->core->globals[$bucket . '.' . $key] = null;
					$this->ease_array_to_bucket($value, $bucket . '.' . $key);
				} else {
					$this->core->globals[$bucket . '.' . $key] = $value;
				}
			}
		}
	}

	function ease_bucket_to_array($bucket) {
		$bucket_array = array();
		if(!in_array(strtolower($bucket), $this->core->reserved_buckets)) {
			// process every global variable looking for the referenced bucket
			$bucket_name = strtolower($bucket) . '.';
			$bucket_name_length = strlen($bucket_name);
			foreach($this->core->globals as $key=>$value) {
				if(substr($key, 0, $bucket_name_length)==$bucket_name) {
					// a global variable was found matching the referenced bucket
					$bucket_pointer = &$bucket_array;							
					// process the key to build an associative array of the bucket
					// indexes are denoted with a period .  (ex: my_bucket.name, my_bucket.address.city)
					$key_parts = explode('.', $key);
					unset($key_parts[0]);
					foreach($key_parts as $key_part) {
						if(!isset($bucket_pointer[$key_part])) {
							$bucket_pointer[$key_part] = null;
						}
						$bucket_pointer = &$bucket_pointer[$key_part];
					}
					$bucket_pointer = $value;
				}
			}
		}
		return $bucket_array;
	}

}
