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
 * Form Handler for use with the EASE Framework to handle data posted from EASE Forms
 *
 * @author Mike <mike@cloudward.com>
 */
class ease_form_handler
{
	public $core;
	public $interpreter;
	public $form_info;

	function __construct(&$core) {
		$this->core = $core;
	}

	function process() {
		// require an interpreter for extracting and applying contexts from variable references in form actions
		require_once('interpreter.class.php');

		// validate the requested EASE Form ID
		// TODO! if the user session has expired, form posts will fail... perhaps store the form info in the DB as well, 
		// - but that eliminates the added layer of security of restricting the post to the current session...
		if(!isset($_SESSION['ease_forms'][$_REQUEST['ease_form_id']])) {
			echo 'Invalid EASE Form ID: ' . htmlspecialchars($_REQUEST['ease_form_id']);
			exit;
		}
		// load the form info from the session
		$this->form_info = $_SESSION['ease_forms'][$_REQUEST['ease_form_id']];

		// confirm that all post restrictions were met  (CAPTCHA, form password, etc...)
		if(isset($this->form_info['restrict_post']) && is_array($this->form_info['restrict_post'])) {
			foreach($this->form_info['restrict_post'] as $input_key=>$required_value) {
				$input_key_parts = explode('.', $input_key, 2);
				if(count($input_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[1])));
				} else {
					$bucket = '';
					$key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($input_key)));
				}
				if(isset($this->form_info['sql_table_name']) && trim($this->form_info['sql_table_name'])!='') {
					// this is a form for an SQL Table
					if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
						foreach($this->form_info['inputs'] as $post_key=>$map_bucket_key) {
							$map_bucket_key_parts = explode('.', $map_bucket_key, 2);
							if(count($map_bucket_key_parts)==2) {
								if($map_bucket_key_parts[1]==$key) {
									if($map_bucket_key_parts[0]=='form' || $map_bucket_key_parts[0]==$this->form_info['sql_table_name']) {
										if(isset($_POST[$post_key]) && $_POST[$post_key]==$required_value) {
											// post restriction was met
											break;
										} else {
											echo 'EASE Form Error → Post Restricted - Invalid ' . htmlspecialchars(strtoupper($key));
											exit;
										}
									}
								}
							}
						}
					}
				} else {
					// this is a non-SQL form... inputs are stored slightly differently as they also include the uncleansed field names
					// this allows spreadsheet forms to set the column header to "Original Name" while referencing the column as "originalname"
					$key = preg_replace('/^[0-9]+/', '', str_replace('_', '', $key));
					if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
						foreach($this->form_info['inputs'] as $post_key=>$map_array) {
							if(isset($map_array['header_reference']) && $map_array['header_reference']==$key) {
								if(isset($_POST[$post_key]) && $_POST[$post_key]==$required_value) {
									// post restriction was met
									break;
								} else {
									// post restriction was not met... error out
									// TODO!!  allow users to define error pages or automatically redirect back to the form
									echo 'EASE Form Error → Post Restricted - Invalid ' . htmlspecialchars(strtoupper($key));
									exit;
								}
							}
						}
					}
				}
			}
		}

		// validate any posted data with validated input types
		$invalid_inputs = array();
		if(isset($this->form_info['input_validations']) && is_array($this->form_info['input_validations'])) {
			foreach($this->form_info['input_validations'] as $post_key=>$validation_type) {
				if(isset($_POST[$post_key]) && $_POST[$post_key]!='') {
					switch($validation_type) {
						case 'email':
							if(isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine')!==false) {
								// load the Google Mail Service API for validating email addresses
								require_once 'google/appengine/api/mail/Message.php';
								$message = new google\appengine\api\mail\Message();
								try {
									$message->addTo($_POST[$post_key]);
								} catch(InvalidArgumentException $e) {
									$invalid_inputs[$post_key] = '* Valid Email Address Required';
								}
							} elseif(!filter_var($_POST[$post_key], FILTER_VALIDATE_EMAIL)) {
								$invalid_inputs[$post_key] = '* Valid Email Address Required';
							}
							break;
						case 'integer':
							if(!preg_match('/^[0-9]+$/', $_POST[$post_key])) {
								$invalid_inputs[$post_key] = '* Integer Number Required';
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['min']) && $_POST[$post_key] < $this->form_info['input_validation_attributes'][$post_key]['min']) {
								$invalid_inputs[$post_key] = "* Minimum value is {$this->form_info['input_validation_attributes'][$post_key]['min']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['max']) && $_POST[$post_key] > $this->form_info['input_validation_attributes'][$post_key]['max']) {
								$invalid_inputs[$post_key] = "* Maximum value is {$this->form_info['input_validation_attributes'][$post_key]['max']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['step']) && ($_POST[$post_key] % $this->form_info['input_validation_attributes'][$post_key]['step'])!=0) {
								$invalid_inputs[$post_key] = "* Value must be a multiple of {$this->form_info['input_validation_attributes'][$post_key]['step']}";
							}
							break;
						case 'number':
						case 'decimal':
							if(!preg_match('/^[0-9\., -]+$/', $_POST[$post_key])) {
								$invalid_inputs[$post_key] = '* Number Required';
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['min']) && $_POST[$post_key] < $this->form_info['input_validation_attributes'][$post_key]['min']) {
								$invalid_inputs[$post_key] = "* Minimum value is {$this->form_info['input_validation_attributes'][$post_key]['min']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['max']) && $_POST[$post_key] > $this->form_info['input_validation_attributes'][$post_key]['max']) {
								$invalid_inputs[$post_key] = "* Maximum value is {$this->form_info['input_validation_attributes'][$post_key]['max']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['step'])
								&& strtolower($this->form_info['input_validation_attributes'][$post_key]['step'])!='any'
								&& ($_POST[$post_key] % $this->form_info['input_validation_attributes'][$post_key]['step'])!=0) {
								$invalid_inputs[$post_key] = "* Value must be a multiple of {$this->form_info['input_validation_attributes'][$post_key]['step']}";
							}
							break;
						case 'price':
						case 'dollars':
						case 'usd':
						case 'cost':
							if(!preg_match('/^[0-9\.$, -]+$/', $_POST[$post_key])) {
								$invalid_inputs[$post_key] = '* Dollar Value Required';
							}
							$number_value = floatval(preg_replace('/[^0-9\.-]+/', '', $_POST[$post_key]));
							if(isset($this->form_info['input_validation_attributes'][$post_key]['min']) && $number_value < $this->form_info['input_validation_attributes'][$post_key]['min']) {
								$invalid_inputs[$post_key] = "* Minimum value is {$this->form_info['input_validation_attributes'][$post_key]['min']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['max']) && $number_value > $this->form_info['input_validation_attributes'][$post_key]['max']) {
								$invalid_inputs[$post_key] = "* Maximum value is {$this->form_info['input_validation_attributes'][$post_key]['max']}";
							}
							if(isset($this->form_info['input_validation_attributes'][$post_key]['step']) && ($number_value % $this->form_info['input_validation_attributes'][$post_key]['step'])!=0) {
								$invalid_inputs[$post_key] = "* Value must be a multiple of {$this->form_info['input_validation_attributes'][$post_key]['step']}";
							}
							break;
						case 'url':
							if(!filter_var($_POST[$post_key], FILTER_VALIDATE_URL)) {
								$invalid_inputs[$post_key] = '* Valid URL Required';
							}
							break;
						case 'date':
							$datetime = strtotime($_POST[$post_key]);
							if(!($datetime > 0) || !checkdate(date('n', $datetime), date('j', $datetime), date('Y', $datetime))) {
								$invalid_inputs[$post_key] = '* Valid Date Required';
							}
							$_POST[$post_key] = date('Y-m-d', $datetime);
							break;
						case 'datetime':
							$datetime = strtotime($_POST[$post_key]);
							if(!($datetime > 0) || !checkdate(date('n', $datetime), date('j', $datetime), date('Y', $datetime))) {
								$invalid_inputs[$post_key] = '* Valid Date & Time Required';
							}
							$_POST[$post_key] = date('Y-m-d H:i:s', $datetime);
							break;
						default:
					}
				}
			}
		}
		if(isset($this->form_info['input_patterns']) && is_array($this->form_info['input_patterns'])) {
			foreach($this->form_info['input_patterns'] as $post_key=>$validation_pattern) {
				if(!preg_match("/^$validation_pattern$/", $_POST[$post_key])) {
					$invalid_inputs[$post_key] = "* Pattern Match Required: $validation_pattern";
				}
			}
		}
		if(isset($this->form_info['input_requirements']) && is_array($this->form_info['input_requirements'])) {
			foreach($this->form_info['input_requirements'] as $post_key=>$required) {
				if($required && (!isset($_POST[$post_key]) || trim($_POST[$post_key])=='')) {
					$invalid_inputs[$post_key] = '* Value Required';
				}
			}
		}
		// if any inputs were invalid, redirect back to the form which will indicate the invalid inputs
		if(count($invalid_inputs) > 0) {
			$form_url = $_SERVER['HTTP_REFERER'];
			if(strpos($form_url, '?ease_form_id=')===false && strpos($form_url, '&ease_form_id=')===false) {
				if(strpos($form_url, '?')===false) {
					$form_url .= "?ease_form_id={$_REQUEST['ease_form_id']}";
				} else {
					$form_url .= "&ease_form_id={$_REQUEST['ease_form_id']}";
				}
			}
			$_SESSION['ease_forms'][$_REQUEST['ease_form_id']]['post_values'] = $_POST;
			$_SESSION['ease_forms'][$_REQUEST['ease_form_id']]['invalid_inputs'] = $invalid_inputs;
			header("Location: $form_url");
			exit;
		}

		// clean out old forms from the session... consider anything over 3 hours as stale
		foreach($_SESSION['ease_forms'] as $key=>$value) {
			if($value['created_on'] < (time() - 60 * 60 * 3)) {
				unset($_SESSION['ease_forms'][$key]);
			}
		}

		// check if this form was routed through the Google Cloud Storage form handler which irregularly encodes data as "Quoted-Printable"
		// TODO!! remove this when google fixes the bug
		if(@$_SERVER['HTTP_X_APPENGINE_BLOBUPLOAD']) {
			foreach($_POST as $key=>$value) {
				// determine if the value was likely quoted-printable encoded
				$is_quoted_printable = true;
				$any_line_ended_with_equals = false;
				$lines = preg_split('/[\v]+/', $value);
				foreach($lines as $line) {
					if(strlen($line) > 76) {
						$is_quoted_printable = false;
						break;
					}
					if(strlen($line)==76) {
						if(substr($line, 75, 1)=='=') {
							$any_line_ended_with_equals = true;
						}
					}
				}
				if(!$any_line_ended_with_equals) {
					$is_quoted_printable = false;
				}
				if($is_quoted_printable) {
					$_POST[$key] = quoted_printable_decode($value);
				}
			}
		}

		// process the form buttons to determine which handler to call
		if(isset($this->form_info['buttons']) && is_array($this->form_info['buttons'])) {
			foreach($this->form_info['buttons'] as $button=>$button_attributes) {
				if(isset($_POST[$button])) {
					// this button was pressed... call the handler associated with this button
					$this->{$button_attributes['handler']}($button_attributes['action']);
					$handled = true;
					break;
				}
			}
		}
		if(!$handled) {
			echo 'EASE Form Handler Error!  A Form Action was not provided.<p>If this form was submitted with javascript, use the button.click method instead of form.submit';
			exit;
		}
	}

	function add_instance_to_sql_table($action) {
		// build an array $new_row containing the record values to insert into the SQL Table
		if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
			foreach($this->form_info['inputs'] as $post_key=>$bucket_key) {
				$bucket_key_parts = explode('.', $bucket_key, 2);
				if(count($bucket_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
					if($bucket==$this->form_info['sql_table_name'] || $bucket=='row') {
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
						if($key=='id') {
							$key = 'uuid';
						}
						if(isset($_POST[$post_key])) {
							$new_row[$key] = $_POST[$post_key];
						} else {
							$new_row[$key] = '';
						}
					}
				} else {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
					if($key=='id') {
						$key = 'uuid';
					}
					if(isset($_POST[$post_key])) {
						$new_row[$key] = $_POST[$post_key];
					} else {
						$new_row[$key] = '';
					}
				}
			}
		}
		// only process file uploads for SQL backed forms if a database connection exists and hasn't been disabled
		if(isset($this->form_info['files']) && is_array($this->form_info['files']) && $this->core->db && !$this->core->db_disabled) {
			// there were file upload fields in the form, check for uploaded files
			foreach($this->form_info['files'] as $post_key=>$file_attributes) {
				$bucket_key_parts = explode('.', $file_attributes['map'], 2);
				if(count($bucket_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
					if($bucket==$this->form_info['sql_table_name'] || $bucket=='row') {
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
						$upload_file = true;
					} else {
						$upload_file = false;
					}
				} else {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($file_attributes['map'])));
					$upload_file = true;
				}
				if($upload_file) {
					if(isset($_FILES[$post_key]['tmp_name']) && trim($_FILES[$post_key]['tmp_name'])!='') {
						// a file was uploaded for this field
						$file_attributes = array_merge($file_attributes, $_FILES[$post_key]);
						$new_row[$key] = $file_attributes['name'];
						if(isset($_SERVER['SERVER_SOFTWARE'])
						  && strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine')!==false
						  && isset($this->core->config['gs_bucket_name'])
						  && trim($this->core->config['gs_bucket_name'])!='') {
							// the GCS form handler adds broken quoted-printable encoding if the bucket name is longer than 37 characters
							// removing '=' characters fixes the problem...
							$file_attributes['tmp_name'] = str_replace('=', '', $file_attributes['tmp_name']);
						}
						if(isset($file_attributes['folder']) && trim($file_attributes['folder'])!='') {
							// initialize a Google Drive API client
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
							$parent = new Google_Service_Drive_ParentReference();
							$parent->SetId(trim($file_attributes['folder'], '/'));
							$file = new Google_Service_Drive_DriveFile();
							$file->setTitle($file_attributes['name']);
							$file->setDescription('EASE Upload - ' . $this->core->globals['system.domain']);
							$file->setMimeType($file_attributes['type']);
							$file->setParents(array($parent));
							// upload the file to google drive, then set the row value to the URL the file can be downloaded at
							$uploaded_file = null;
							$try_count = 0;
							while($uploaded_file===null && $try_count<=5) {
								if($try_count > 0) {
									sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
								}
								$try_count++;
								try {
									if($file_attributes['type']=='message/external-body') {
										// this is likely an upload from a local AppEngine dev environment
										// TODO! parse the file headers to get the X-AppEngine-Cloud-Storage-Object setting and process that file instead
										break;
									} else {
										$uploaded_file = $service->files->insert($file, array('data'=>file_get_contents($file_attributes['tmp_name']), 'mimeType'=>$file_attributes['type'], 'uploadType'=>'multipart'));
									}
								} catch(Google_Service_Exception $e) {
									continue;
								}
							}
							if(isset($uploaded_file['webContentLink'])) {
								// the web content link is what is provided to users to download
								$new_row[$key . '_drive_web_url'] = $uploaded_file['webContentLink'];
								$new_row[$key . '_web_url'] = $uploaded_file['webContentLink'];
							}
							if(isset($uploaded_file['selfLink'])) {
								// the self link can be used to update the file
								$new_row[$key . '_drive_url'] = $uploaded_file['selfLink'];
							}
						} else {
							// the uploaded file was not saved in google drive, store it in the file share for the environment
							if(isset($_SERVER['SERVER_SOFTWARE'])
							  && strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine')!==false
							  && isset($this->core->config['gs_bucket_name'])
							  && trim($this->core->config['gs_bucket_name'])!='') {
								// Google App Engine file uploads are automatically routed to a Google Cloud Storage Bucket
								if(isset($file_attributes['private']) && $file_attributes['private']) {
									// this is a private file, keep the file where it is
									$new_row[$key . '_gs_url'] = $file_attributes['tmp_name'];
									$new_row[$key . '_path'] = $file_attributes['tmp_name'];
								} else {
									// this is not a private file, copy the file with public access rights, and generate a public web URL
									$public_context = stream_context_create(array('gs'=>array('acl'=>'public-read')));
									$new_unique_directory = 'gs://' . $this->core->config['gs_bucket_name'] . '/' . $this->core->new_uuid();
									mkdir($new_unique_directory);
									$public_file_path = $new_unique_directory . '/' . $file_attributes['name'];
									rename($file_attributes['tmp_name'], $public_file_path, $public_context);
									require_once 'google/appengine/api/cloud_storage/CloudStorageTools.php';
									$new_row[$key . '_gs_url'] = $public_file_path;
									$new_row[$key . '_path'] = $public_file_path;
									$new_row[$key . '_web_url'] = google\appengine\api\cloud_storage\CloudStorageTools::getPublicUrl($public_file_path, true);
								}
							} elseif(isset($this->core->config['s3_access_key_id'])
							  && trim($this->core->config['s3_access_key_id'])!=''
							  && isset($this->core->config['s3_secret_key'])
							  && trim($this->core->config['s3_secret_key'])!='') {
								// Amazon Elastic Beanstalk uploads need to be copied to a shared S3 bucket
								require_once 'ease/lib/S3.php';
								$s3 = new S3($this->core->config['s3_access_key_id'], $this->core->config['s3_secret_key']);
								if(isset($file_attributes['private']) && $file_attributes['private']) {
									$this->core->load_system_config_var('s3_bucket_private');
									if(isset($this->core->config['s3_bucket_private']) && trim($this->core->config['s3_bucket_private'])!='') {
										$s3_bucket_private = $this->core->config['s3_bucket_private'];
									} else {
										$s3_bucket_private = 'ease-private-' . $this->core->new_uuid();
										$this->core->set_system_config_var('s3_bucket_private', $s3_bucket_private);
									}
									$existing_s3_buckets = $s3->listBuckets();
									if(!in_array($s3_bucket_private, $existing_s3_buckets)) {
										if(!$s3->putBucket($s3_bucket_private, S3::ACL_PRIVATE)) {
											echo 'Error!  Unable to create Private AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_private);
											exit;
										}
									}
									$s3_file_uri = rawurlencode($file_attributes['name']);
									$s3_folder = $this->core->new_uuid();
									if($s3->putObjectFile($file_attributes['tmp_name'], $s3_bucket_private, $s3_folder . '/' . $s3_file_uri, S3::ACL_PRIVATE, array(), $file_attributes['type'])) {
										// file upload to AWS S3 was successful
										$new_row[$key . '_s3_bucket'] = $s3_bucket_private;
										$new_row[$key . '_s3_uri'] = $s3_folder . '/' . $s3_file_uri;
										$new_row[$key . '_path'] = 's3://' . $new_row[$key . '_s3_bucket'] . '/' . $new_row[$key . '_s3_uri'];
									} else {
										echo 'Error!  Unable to upload file to Private AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_private);
										exit;
									}
								} else {
									$this->core->load_system_config_var('s3_bucket_public');
									if(isset($this->core->config['s3_bucket_public']) && trim($this->core->config['s3_bucket_public'])!='') {
										$s3_bucket_public = $this->core->config['s3_bucket_public'];
									} else {
										$s3_bucket_public = 'ease-public-' . $this->core->new_uuid();
										$this->core->set_system_config_var('s3_bucket_public', $s3_bucket_public);
									}
									$existing_s3_buckets = $s3->listBuckets();
									if(!in_array($s3_bucket_public, $existing_s3_buckets)) {
										if(!$s3->putBucket($s3_bucket_public, S3::ACL_PUBLIC_READ)) {
											echo 'Error!  Unable to create Public AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_public);
											exit;
										}
									}
									$s3_file_uri = rawurlencode($file_attributes['name']);
									$s3_folder = $this->core->new_uuid();
									if($s3->putObjectFile($file_attributes['tmp_name'], $s3_bucket_public, $s3_folder . '/' . $s3_file_uri, S3::ACL_PUBLIC_READ, array(), $file_attributes['type'])) {
										// file upload to AWS S3 was successful
										$new_row[$key . '_s3_bucket'] = $s3_bucket_public;
										$new_row[$key . '_s3_uri'] = $s3_folder . '/' . $s3_file_uri;
										$new_row[$key . '_path'] = 's3://' . $new_row[$key . '_s3_bucket'] . '/' . $new_row[$key . '_s3_uri'];
										$new_row[$key . '_web_url'] = 'https://s3.amazonaws.com/' . $new_row[$key . '_s3_bucket'] . '/' . $new_row[$key . '_s3_uri'];
									} else {
										echo 'Error!  Unable to upload file to Public AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_public);
										exit;
									}
								}
							} else {
								// a cloud file share is not configured for the environment, check for local upload dir configuration
								$this->core->load_system_config_var('private_upload_dir');
								$this->core->load_system_config_var('public_upload_dir');
								if(isset($file_attributes['private']) && $file_attributes['private'] && isset($this->core->config['private_upload_dir']) && trim($this->core->config['private_upload_dir'])!='') {
									// this is a private file upload, and a private upload dir was configured
									if(!is_dir($this->core->config['private_upload_dir'])) {
										mkdir($this->core->config['private_upload_dir'], 0777, true);
									}
									$new_folder = $this->core->new_uuid();
									mkdir($this->core->config['private_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder);
									$new_row[$key . '_path'] = $this->core->config['private_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder . DIRECTORY_SEPARATOR . $file_attributes['name'];
									move_uploaded_file($file_attributes['tmp_name'], $new_row[$key . '_path']);
								} elseif(isset($this->core->config['public_upload_dir']) && trim($this->core->config['public_upload_dir'])!='') {
									// this is a public file upload, and a public upload dir was configured
									if(!is_dir($this->core->config['public_upload_dir'])) {
										mkdir($this->core->config['public_upload_dir'], 0777, true);
									}
									$new_folder = $this->core->new_uuid();
									mkdir($this->core->config['public_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder);
									$new_row[$key . '_path'] = $this->core->config['public_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder . DIRECTORY_SEPARATOR . $file_attributes['name'];
									move_uploaded_file($file_attributes['tmp_name'], $new_row[$key . '_path']);
									if(preg_match('@^' . preg_quote($_SERVER['DOCUMENT_ROOT'], '@') . '(.*)$@i', $this->core->config['public_upload_dir'], $matches)) {
										$matches[1] = str_replace(DIRECTORY_SEPARATOR, '/', $matches[1]);
										$new_row[$key . '_web_url'] = ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])=='on') ? 'https' : 'http') . '://';
										$new_row[$key . '_web_url'] .= $_SERVER['HTTP_HOST'];
										if(substr($matches[1], 0, 1)!='/') {
											$new_row[$key . '_web_url'] .= '/';
										}
										$new_row[$key . '_web_url'] .= $matches[1];
										if(substr($matches[1], -1, 1)!='/') {
											$new_row[$key . '_web_url'] .= '/';
										}
										$new_row[$key . '_web_url'] .= $new_folder . '/' . rawurlencode($file_attributes['name']);
									}
								} else {
									// a local upload dir was not configured... keep the file where it is
									$new_row[$key . '_path'] = $file_attributes['tmp_name'];
								}
							}
						}
					}
				}
			}
		}
		if(!isset($new_row['uuid'])) {
			$new_row['uuid'] = $this->core->new_uuid();
		}
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$this->inject_form_variables($value, $new_row['uuid'], $new_row);
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
				if($bucket==$this->form_info['sql_table_name'] || $bucket=='row') {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
					if($key=='id') {
						$key = 'uuid';
					}
					$new_row[$key] = $value;
				}
			} else {
				$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
				if($key=='id') {
					$key = 'uuid';
				}
				$new_row[$key] = $value;
			}
		}
		@$this->form_info['calculate_list_by_action'][$action] = array_merge(
			(array)$this->form_info['calculate_list_by_action'][$action],
			(array)$this->form_info['calculate_list_by_action']['done']
		);
		foreach($this->form_info['calculate_list_by_action'][$action] as $bucket_key=>$expression) {
			$context_stack = ease_interpreter::extract_context_stack($expression);
			$this->inject_form_variables($expression, $new_row['uuid'], $new_row);
			if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $expression, $math_expression_matches)) {
				// math expression value, evaluate the expression to calculate value
				$eval_result = @eval("\$set_value = $expression;");
				if($eval_result===false) {
					// there was an error evaluating a math expression... set the value to the broken math expression
					$set_value = $expression;
				} else {
					ease_interpreter::apply_context_stack($set_value, $context_stack);
				}
			} else {
				// there were invalid characters in the math expression... set the value to the broken math expression
				$set_value = $expression;
			}
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
				if($bucket==$this->form_info['sql_table_name'] || $bucket=='row') {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
					if($key=='id') {
						$key = 'uuid';
					}
					$new_row[$key] = $set_value;
				}
			} else {
				$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
				if($key=='id') {
					$key = 'uuid';
				}
				$new_row[$key] = $set_value;
			}
		}
		$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $this->form_info['sql_table_name']), '_');		
		// if new row data exists, insert a new row into the SQL Table, otherwise do nothing
		if(isset($new_row) && $this->core->db && !$this->core->db_disabled) {
			// make sure the SQL Table exists and has all the columns referenced in the new row
			$result = $this->core->db->query("DESCRIBE `$namespaced_sql_table_name`;");
			if($result) {
				// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
				$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
				foreach(array_keys($new_row) as $column) {
					if(!in_array($column, $existing_columns)) {
						if(sizeof($new_row[$column]) > 65535) {
							$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
						} else {
							$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
						}
					}
				}
				if(!in_array('updated_on', $existing_columns)) {
					$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN updated_on TIMESTAMP;");
				}
			} else {
				// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
				$custom_columns_sql = '';
				foreach(array_keys($new_row) as $column) {
					if(!in_array($column, $this->core->reserved_sql_columns)) {
						if(sizeof($new_row[$column]) > 65535) {
							$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
						} else {
							$custom_columns_sql .= ", `$column` text NOT NULL default ''";
						}
					}
				}
				$sql = "	CREATE TABLE `$namespaced_sql_table_name` (
								instance_id int NOT NULL PRIMARY KEY auto_increment,
								created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
								updated_on timestamp NOT NULL,
								uuid varchar(32) NOT NULL UNIQUE
								$custom_columns_sql
							);	";
				$this->core->db->exec($sql);
			}
			// insert the new row
			$params = array();
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
			$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_sql_table_name`
												SET uuid=:uuid
													$insert_columns_sql;	");
			$query->execute($params);
			// TODO! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
		}
		// new instance was added to SQL Table... process any Form Actions
		// execute any set cookie or session variable commands for the form action
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = rtrim($bucket_key_parts[0]);
				$key = ltrim($bucket_key_parts[1]);
				$this->inject_form_variables($value, $params[':uuid'], $new_row);
				switch(strtolower($bucket)) {
					case 'session':
						$_SESSION[$key] = $value;
						break;
					case 'cookie':
						setcookie($key, $value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $value;
						break;
					default:
				}
			}
		}
		// execute any SEND EMAIL commands for the form action
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $params[':uuid'], $new_row);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $params[':uuid'], $new_row);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid'], $new_row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');		
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
				// TODO! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $params[':uuid'], $new_row);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid'], $new_row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');		
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $params[':uuid'], $new_row);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');		
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(isset($this->form_info['redirect_to_by_action'][$action])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $params[':uuid'], $new_row);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(isset($this->form_info['redirect_to_by_action']['done'])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $params[':uuid'], $new_row);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function update_instance_in_sql_table($action) {
		// check if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $this->form_info['sql_table_name']), '_');		
			// query for existing data for the record being updated
			$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
			$params = array(':uuid'=>(string)$this->form_info['instance_uuid']);
			if($query->execute($params)) {
				$existing_record = $query->fetch(PDO::FETCH_ASSOC);
			} else {
				// this is an update form for a record that doesn't exist
				$existing_record = array();
			}
		} else {
			// database connection is not available or has been disabled
			$existing_record = array();
		}
		// build the updated row for the SQL Table instance
		if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
			foreach($this->form_info['inputs'] as $post_key=>$bucket_key) {
				$bucket_key_parts = explode('.', $bucket_key, 2);
				if(count($bucket_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
					if($bucket==$this->form_info['sql_table_name']) {
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
						if($key=='id') {
							$key = 'uuid';
						}
						if(isset($_POST[$post_key])) {
							$updated_row[$key] = $_POST[$post_key];
						} else {
							$updated_row[$key] = '';
						}
					}
				} else {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
					if($key=='id') {
						$key = 'uuid';
					}
					if(isset($_POST[$post_key])) {
						$updated_row[$key] = $_POST[$post_key];
					} else {
						$updated_row[$key] = '';
					}
				}
			}
		}
		if(isset($this->form_info['files']) && is_array($this->form_info['files']) && $this->core->db && !$this->core->db_disabled) {
			foreach($this->form_info['files'] as $post_key=>$file_attributes) {
				$bucket_key_parts = explode('.', $file_attributes['map'], 2);
				if(count($bucket_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
					if($bucket==$this->form_info['sql_table_name']) {
						$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
						$upload_file = true;
					} else {
						$upload_file = false;
					}
				} else {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($file_attributes['map'])));
					$upload_file = true;
				}
				if($upload_file) {
					if(isset($_FILES[$post_key]['tmp_name']) && trim($_FILES[$post_key]['tmp_name'])!='') {
						// a file was uploaded for this field
						$file_attributes = array_merge($file_attributes, $_FILES[$post_key]);
						$updated_row[$key] = $file_attributes['name'];
						// the GCS form handler adds broken quoted-printable encoding if the bucket name is longer than 37 characters
						// removing '=' characters fixes the problem...
						$file_attributes['tmp_name'] = str_replace('=', '', $file_attributes['tmp_name']);
						if(isset($file_attributes['folder']) && trim($file_attributes['folder'])!='') {
							// initialize a Google Drive API client
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
							$parent = new Google_Service_Drive_ParentReference();
							$parent->SetId(trim($file_attributes['folder'], '/'));
							$file = new Google_Service_Drive_DriveFile();
							$file->setTitle($file_attributes['name']);
							$file->setDescription('');
							$file->setMimeType($file_attributes['type']);
							$file->setParents(array($parent));
							// upload the file to google drive, then set the row value to the URL the file can be downloaded at
							$uploaded_file = null;
							$try_count = 0;
							while($uploaded_file===null && $try_count<=5) {
								if($try_count > 0) {
									sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
								}
								$try_count++;
								try {
									if($file_attributes['type']=='message/external-body') {
										// this is likely an upload from a local dev environment
										// TODO!! parse the file headers to get the X-AppEngine-Cloud-Storage-Object setting and process that file instead
										break;
									} else {
										$uploaded_file = $service->files->insert($file, array('data'=>file_get_contents($file_attributes['tmp_name']), 'mimeType'=>$file_attributes['type'], 'uploadType'=>'multipart'));
									}
								} catch(Google_Service_Exception $e) {
									continue;
								}
							}
							$updated_row[$key] = $file_attributes['name'];
							if(isset($uploaded_file['webContentLink'])) {
								// the web content link is what is provided to users to download
								$updated_row[$key . '_drive_web_url'] = $uploaded_file['webContentLink'];
								$updated_row[$key . '_web_url'] = $uploaded_file['webContentLink'];
							}
							if(isset($uploaded_file['selfLink'])) {
								// the self link can be used to update the file
								$updated_row[$key . '_drive_url'] = $uploaded_file['selfLink'];
							}
						} else {
							// the uploaded file was not saved in google drive, store it in the file share for the environment
							if(isset($_SERVER['SERVER_SOFTWARE'])
							  && strpos($_SERVER['SERVER_SOFTWARE'], 'Google App Engine')!==false
							  && isset($this->core->config['gs_bucket_name'])
							  && trim($this->core->config['gs_bucket_name'])!='') {
								// Google App Engine file uploads are automatically routed to a Google Cloud Storage Bucket
								if(isset($file_attributes['private']) && $file_attributes['private']) {
									// this is a private file, keep the file where it is
									$updated_row[$key . '_gs_url'] = $file_attributes['tmp_name'];
									$updated_row[$key . '_path'] = $file_attributes['tmp_name'];
								} else {
									// this is not a private file, copy the file with public access rights, and generate a public web URL
									$public_context = stream_context_create(array('gs'=>array('acl'=>'public-read')));
									$new_unique_directory = 'gs://' . $this->core->config['gs_bucket_name'] . '/' . $this->core->new_uuid();
									mkdir($new_unique_directory);
									$public_file_path = $new_unique_directory . '/' . $file_attributes['name'];
									rename($file_attributes['tmp_name'], $public_file_path, $public_context);
									require_once 'google/appengine/api/cloud_storage/CloudStorageTools.php';
									$updated_row[$key . '_gs_url'] = $public_file_path;
									$updated_row[$key . '_path'] = $public_file_path;
									$updated_row[$key . '_web_url'] = google\appengine\api\cloud_storage\CloudStorageTools::getPublicUrl($public_file_path, true);
								}
							} elseif(isset($this->core->config['s3_access_key_id'])
							  && trim($this->core->config['s3_access_key_id'])!=''
							  && isset($this->core->config['s3_secret_key'])
							  && trim($this->core->config['s3_secret_key'])!='') {
								// Amazon Elastic Beanstalk uploads need to be copied to a shared S3 bucket
								require_once 'ease/lib/S3.php';
								$s3 = new S3($this->core->config['s3_access_key_id'], $this->core->config['s3_secret_key']);
								if(isset($file_attributes['private']) && $file_attributes['private']) {
									$this->core->load_system_config_var('s3_bucket_private');
									if(isset($this->core->config['s3_bucket_private']) && trim($this->core->config['s3_bucket_private'])!='') {
										$s3_bucket_private = $this->core->config['s3_bucket_private'];
									} else {
										$s3_bucket_private = 'ease-private-' . $this->core->new_uuid();
										$this->core->set_system_config_var('s3_bucket_private', $s3_bucket_private);
									}
									$existing_s3_buckets = $s3->listBuckets();
									if(!in_array($s3_bucket_private, $existing_s3_buckets)) {
										if(!$s3->putBucket($s3_bucket_private, S3::ACL_PRIVATE)) {
											echo 'Error!  Unable to create Private AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_private);
											exit;
										}
									}
									$s3_file_uri = rawurlencode($file_attributes['name']);
									$s3_folder = $this->core->new_uuid();
									if($s3->putObjectFile($file_attributes['tmp_name'], $s3_bucket_private, $s3_folder . '/' . $s3_file_uri, S3::ACL_PRIVATE, array(), $file_attributes['type'])) {
										// file upload to AWS S3 was successful
										$updated_row[$key . '_s3_bucket'] = $s3_bucket_private;
										$updated_row[$key . '_s3_uri'] = $s3_folder . '/' . $s3_file_uri;
										$updated_row[$key . '_path'] = 's3://' . $updated_row[$key . '_s3_bucket'] . '/' . $updated_row[$key . '_s3_uri'];
									} else {
										echo 'Error!  Unable to upload file to Private AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_private);
										exit;
									}
								} else {
									$this->core->load_system_config_var('s3_bucket_public');
									if(isset($this->core->config['s3_bucket_public']) && trim($this->core->config['s3_bucket_public'])!='') {
										$s3_bucket_public = $this->core->config['s3_bucket_public'];
									} else {
										$s3_bucket_public = 'ease-public-' . $this->core->new_uuid();
										$this->core->set_system_config_var('s3_bucket_public', $s3_bucket_public);
									}
									$existing_s3_buckets = $s3->listBuckets();
									if(!in_array($s3_bucket_public, $existing_s3_buckets)) {
										if(!$s3->putBucket($s3_bucket_public, S3::ACL_PUBLIC_READ)) {
											echo 'Error!  Unable to create Public AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_public);
											exit;
										}
									}
									$s3_file_uri = rawurlencode($file_attributes['name']);
									$s3_folder = $this->core->new_uuid();
									if($s3->putObjectFile($file_attributes['tmp_name'], $s3_bucket_public, $s3_folder . '/' . $s3_file_uri, S3::ACL_PUBLIC_READ, array(), $file_attributes['type'])) {
										// file upload to AWS S3 was successful
										$updated_row[$key . '_s3_bucket'] = $s3_bucket_public;
										$updated_row[$key . '_s3_uri'] = $s3_folder . '/' . $s3_file_uri;
										$updated_row[$key . '_path'] = 's3://' . $updated_row[$key . '_s3_bucket'] . '/' . $updated_row[$key . '_s3_uri'];
										$updated_row[$key . '_web_url'] = 'https://s3.amazonaws.com/' . $updated_row[$key . '_s3_bucket'] . '/' . $updated_row[$key . '_s3_uri'];
									} else {
										echo 'Error!  Unable to upload file to Public AWS S3 Bucket named: ' . htmlspecialchars($s3_bucket_public);
										exit;
									}
								}
								$updated_row[$key . '_path'] = $file_attributes['tmp_name'];
							} else {
								// a file share is not configured for the environment, keep the file where it is
								$this->core->load_system_config_var('private_upload_dir');
								$this->core->load_system_config_var('public_upload_dir');
								if(isset($file_attributes['private']) && $file_attributes['private'] && isset($this->core->config['private_upload_dir']) && trim($this->core->config['private_upload_dir'])!='') {
									if(!is_dir($this->core->config['private_upload_dir'])) {
										mkdir($this->core->config['private_upload_dir'], 0777, true);
									}
									$new_folder = $this->core->new_uuid();
									mkdir($this->core->config['private_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder);
									$updated_row[$key . '_path'] = $this->core->config['private_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder . DIRECTORY_SEPARATOR . $file_attributes['name'];
									move_uploaded_file($file_attributes['tmp_name'], $updated_row[$key . '_path']);
								} elseif(isset($this->core->config['public_upload_dir']) && trim($this->core->config['public_upload_dir'])!='') {
									if(!is_dir($this->core->config['public_upload_dir'])) {
										mkdir($this->core->config['public_upload_dir'], 0777, true);
									}
									$new_folder = $this->core->new_uuid();
									mkdir($this->core->config['public_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder);
									$updated_row[$key . '_path'] = $this->core->config['public_upload_dir'] . DIRECTORY_SEPARATOR . $new_folder . DIRECTORY_SEPARATOR . $file_attributes['name'];
									move_uploaded_file($file_attributes['tmp_name'], $updated_row[$key . '_path']);
									if(preg_match('@^' . preg_quote($_SERVER['DOCUMENT_ROOT'], '@') . '(.*)$@i', $this->core->config['public_upload_dir'], $matches)) {
										$matches[1] = str_replace(DIRECTORY_SEPARATOR, '/', $matches[1]);
										$updated_row[$key . '_web_url'] = ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS'])=='on') ? 'https' : 'http') . '://';
										$updated_row[$key . '_web_url'] .= $_SERVER['HTTP_HOST'];
										if(substr($matches[1], 0, 1)!='/') {
											$updated_row[$key . '_web_url'] .= '/';
										}
										$updated_row[$key . '_web_url'] .= $matches[1];
										if(substr($matches[1], -1, 1)!='/') {
											$updated_row[$key . '_web_url'] .= '/';
										}
										$updated_row[$key . '_web_url'] .= $new_folder . '/' . rawurlencode($file_attributes['name']);
									}
								} else {
									$updated_row[$key . '_path'] = $file_attributes['tmp_name'];
								}
							}
						}
					}
				}
			}
		}
		// process any conditional form actions
		@$this->form_info['conditional_actions'][$action] = array_merge(
			(array)$this->form_info['conditional_actions'][$action],
			(array)$this->form_info['conditional_actions']['done']
		);
		foreach($this->form_info['conditional_actions'][$action] as $conditional_action) {
			$remaining_condition = $conditional_action['condition'];
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
				if($matches[5]=='=' || strtolower($matches[5])=='is') {
					$matches[5] = '==';
				}
				$this->inject_form_variables($matches[4], $this->form_info['instance_uuid'], $updated_row, $existing_record);
				$this->inject_form_variables($matches[6], $this->form_info['instance_uuid'], $updated_row, $existing_record);
				$php_condition_string .= $matches[1]
					. $matches[2]
					. $matches[3]
					. var_export($matches[4], true)
					. $matches[5]
					. var_export($matches[6], true)
					. $matches[7];
				$remaining_condition = substr($remaining_condition, strlen($matches[0]));
			}
			if(@eval('if(' . $php_condition_string . ') return true; else return false;')) {
				switch($conditional_action['type']) {
					case 'set_to_string':
						$this->form_info['set_to_list_by_action'][$action][$conditional_action['variable']] = $conditional_action['value'];
						break;
					case 'create_sql_record':
						$this->form_info['create_sql_record_list_by_action'][$action][] = $conditional_action['record'];
						break;
					default:
				}
			}
		}
		// process any form action set commands
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$this->inject_form_variables($value, $this->form_info['instance_uuid'], $updated_row, $existing_record);
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
				if($bucket==$this->form_info['sql_table_name']) {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
					if($key=='id') {
						$key = 'uuid';
					}
					$updated_row[$key] = $value;
				}
			} else {
				$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
				if($key=='id') {
					$key = 'uuid';
				}
				$updated_row[$key] = $value;
			}
		}
		@$this->form_info['calculate_list_by_action'][$action] = array_merge(
			(array)$this->form_info['calculate_list_by_action'][$action],
			(array)$this->form_info['calculate_list_by_action']['done']
		);
		foreach($this->form_info['calculate_list_by_action'][$action] as $bucket_key=>$expression) {
			$context_stack = ease_interpreter::extract_context_stack($expression);
			$this->inject_form_variables($expression, $this->form_info['instance_uuid'], $updated_row, $existing_record);
			if(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $expression, $math_expression_matches)) {
				// math expression value, evaluate the expression to calculate value
				$eval_result = @eval("\$set_value = $expression;");
				if($eval_result===false) {
					// there was an error evaluating a math expression... set the value to the broken math expression
					$set_value = $expression;
				} else {
					ease_interpreter::apply_context_stack($set_value, $context_stack);
				}
			} else {
				// there were invalid characters in the math expression... set the value to the broken math expression
				$set_value = $expression;
			}
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = preg_replace('/[^a-z0-9]+/s', '_', strtolower(rtrim($bucket_key_parts[0])));
				if($bucket==$this->form_info['sql_table_name']) {
					$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(ltrim($bucket_key_parts[1])));
					if($key=='id') {
						$key = 'uuid';
					}
					$updated_row[$key] = $set_value;
				}
			} else {
				$key = preg_replace('/[^a-z0-9]+/s', '_', strtolower(trim($bucket_key)));
				if($key=='id') {
					$key = 'uuid';
				}
				$updated_row[$key] = $set_value;
			}
		}
		// if updated row data exists, update the instance in the SQL Table, otherwise do nothing
		if(isset($updated_row) && $this->core->db && !$this->core->db_disabled) {
			// make sure the SQL Table exists and has all the columns referenced in the new row
			$result = $this->core->db->query("DESCRIBE `$namespaced_sql_table_name`;");
			if($result) {
				// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
				$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
				foreach(array_keys($updated_row) as $column) {
					if(!in_array($column, $existing_columns)) {
						if(sizeof($updated_row[$column]) > 65535) {
							$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
						} else {
							$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text NOT NULL default '';");
						}
					}
				}
				if(!in_array('updated_on', $existing_columns)) {
					$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN updated_on TIMESTAMP;");
				}
			} else {
				// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
				foreach(array_keys($updated_row) as $column) {
					if(!in_array($column, $this->core->reserved_sql_columns)) {
						if(sizeof($updated_row[$column]) > 65535) {
							$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
						} else {
							$custom_columns_sql .= ", `$column` text NOT NULL default ''";
						}
					}
				}
				$this->core->db->exec("	CREATE TABLE `$namespaced_sql_table_name` (
											instance_id INT NOT NULL PRIMARY KEY auto_increment,
											created_on TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
											updated_on TIMESTAMP,
											uuid varchar(32) NOT NULL unique
											$custom_columns_sql
										);	");
			}
			// update the record in the database
			$update_columns_sql = '';
			$params = array();
			foreach($updated_row as $key=>$value) {
				$update_columns_sql .= ",`$key`=:$key";
				$params[":$key"] = (string)$value;
			}
			$params[":uuid"] = $this->form_info['instance_uuid'];
			$query = $this->core->db->prepare(" UPDATE `$namespaced_sql_table_name`
												SET updated_on=NOW()
													$update_columns_sql
												WHERE uuid=:uuid;	");
			$result = $query->execute($params);
			// TODO!! check for query errors
		}
		// done updating instance in SQL Table... process any Form Actions
		// execute any set cookie or session variable commands for the form action
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = rtrim($bucket_key_parts[0]);
				$key = ltrim($bucket_key_parts[1]);
				$this->inject_form_variables($value, $params[':uuid'], $updated_row, $existing_record);
				switch(strtolower($bucket)) {
					case 'session':
						$_SESSION[$key] = $value;
						break;
					case 'cookie':
						setcookie($key, $value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $value;
						break;
					default:
				}
			}
		}
		// execute any SEND EMAIL commands for the form action
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $params[':uuid'], $updated_row, $existing_record);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $params[':uuid'], $updated_row, $existing_record);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid'], $updated_row, $existing_record);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');						
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
				// TODO!!  check for query errors like updating a column that wasn't set to hold over 64k of data with more than 64k of data...
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $params[':uuid'], $updated_row, $existing_record);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid'], $updated_row, $existing_record);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');						
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $params[':uuid'], $updated_row, $existing_record);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete... skip it
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');						
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(isset($this->form_info['redirect_to_by_action'][$action])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $params[':uuid'], $updated_row, $existing_record);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(isset($this->form_info['redirect_to_by_action']['done'])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $params[':uuid'], $updated_row, $existing_record);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function delete_instance_from_sql_table($action) {
		// only process SQL deletes if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $this->form_info['sql_table_name']), '_');						
			// query for existing data for the record being deleted
			$query = $this->core->db->prepare("SELECT * FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
			$params = array(':uuid'=>(string)$this->form_info['instance_uuid']);
			if($query->execute($params)) {
				$existing_record = $query->fetch(PDO::FETCH_ASSOC);
			} else {
				// this is a delete form for a record that doesn't exist... error out?
				$existing_record = array();
			}
			// delete the requested instance
			$query = $this->core->db->prepare("DELETE FROM `$namespaced_sql_table_name` WHERE uuid=:uuid;");
			$params = array(':uuid'=>$this->form_info['instance_uuid']);
			$result = $query->execute($params);
		}
		// done deleting instance from SQL Table... process any Form Actions
		// execute any set cookie or session variable commands for the form action
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = rtrim($bucket_key_parts[0]);
				$key = ltrim($bucket_key_parts[1]);
				$this->inject_form_variables($value, $params[':uuid'], null, $existing_record);
				switch(strtolower($bucket)) {
					case 'session':
						$_SESSION[$key] = $value;
						break;
					case 'cookie':
						setcookie($key, $value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $value;
						break;
					default:
				}
			}
		}
		// execute any SEND EMAIL commands for the form action
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $params[':uuid']);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $params[':uuid'], null, $existing_record);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid'], null, $existing_record);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $params[':uuid']);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid']);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $params[':uuid']);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(isset($this->form_info['redirect_to_by_action'][$action])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $params[':uuid']);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(isset($this->form_info['redirect_to_by_action']['done'])) {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $params[':uuid']);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function add_row_to_googlespreadsheet($action) {
		// refresh the google access token if necessary
		$this->core->validate_google_access_token();
		// initialize a google Google Sheet API client
		require_once 'ease/lib/Spreadsheet/Autoloader.php';
		$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
		$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
		Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
		$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
		// determine if the Google Sheet was referenced by name or ID
		$spreadSheet = null;
		$new_spreadsheet_created = false;
		if($this->form_info['google_spreadsheet_id']) {
			$spreadSheet = $spreadsheetService->getSpreadsheetById($this->form_info['google_spreadsheet_id']);
			if($spreadSheet===null) {
				// there was an error loading the Google Sheet by ID...
				// flush the cached meta data for the Google Sheet ID which may no longer be valid
				$this->core->flush_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id']);
				$this->form_info['google_spreadsheet_id'] = '';
				// try adding the row again using the Google Sheet name (if set) which will automatically re-create the Google Sheet
				$this->add_row_to_googlespreadsheet();
				exit;
			}
			$google_spreadsheet_id = $this->form_info['google_spreadsheet_id'];
		} elseif($this->form_info['google_spreadsheet_name']) {
			$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
			$spreadSheet = $spreadsheetFeed->getByTitle($this->form_info['google_spreadsheet_name']);
			if($spreadSheet===null) {
				// the supplied Google Sheet name did not match an existing Google Sheet
				// create a new Google Sheet using the supplied name
				// initialize a Google Drive API client
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
				$file->setTitle($this->form_info['google_spreadsheet_name']);
				$file->setDescription('EASE ' . $this->core->globals['system.domain']);
				$file->setMimeType('text/csv');
				// build the header row CSV string of column names
				$alphas = range('A', 'Z');
				$header_row_csv = '';
				$prefix = '';
				foreach($this->form_info['inputs'] as $value) {
					$header_row_csv .= $prefix . '"' . str_replace('"', '""', $value['original_name']) . '"';
					$prefix = ', ';
				}
				// pad empty values up to column T
				$header_row_count = count($this->form_info['inputs']);
				while($header_row_count < 19) {
					$header_row_csv .= $prefix . '""';
					$header_row_count++;
				}
				// add column for unique row ID used by the EASE core to enable row update and delete
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
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					$try_count++;
					try {
						$new_spreadsheet = $service->files->insert($file, array('data'=>$header_row_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
					} catch(Google_Service_Exception $e) {
						continue;
					}
				}
				// get the new Google Sheet ID
				$google_spreadsheet_id = $new_spreadsheet['id'];
				// check if there was an error creating the Google Sheet
				if(!$google_spreadsheet_id) {
					echo 'Error!  Unable to create Google Sheet named: ' . htmlspecialchars($this->form_info['google_spreadsheet_name']);
					exit;
				}
				// cache the meta data for the new Google Sheet (id, name, column name to letter map, column letter to name map)
				$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($this->form_info['google_spreadsheet_name']);
				// load the newly created Google Sheet
				$spreadSheet = null;
				$try_count = 0;
				while($spreadSheet===null && $try_count<=5) {
					if($try_count > 0) {
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
					$try_count++;
				}
				$new_spreadsheet_created = true;
			}
		}
		// ensure a Google Sheet was laoded
		if($spreadSheet===null) {
			echo 'Error!  Unable to load Google Sheet.';
			exit;
		}
		// load the worksheets in the Google Sheet
		$worksheetFeed = $spreadSheet->getWorksheets();
		// use the requested worksheet, or default to the first worksheet
		if($this->form_info['save_to_sheet']) {
			$worksheet = $worksheetFeed->getByTitle($this->form_info['save_to_sheet']);
			if($worksheet===null) {
				// the supplied worksheet name did not match an existing worksheet in the Google Sheet
				// create a new worksheet using the supplied worksheet name
				$header_row = array();
				foreach($this->form_info['inputs'] as $value) {
					$header_row[] = $value['original_name'];
				}
				// pad empty values in the header row up to column T
				$header_row_count = count($this->form_info['inputs']);
				while($header_row_count < 19) {
					$header_row[] = '';
					$header_row_count++;
				}
				// add column for unique "EASE Row ID" used by the EASE Framework to enable row update and delete
				$header_row[] = 'EASE Row ID';
				$new_worksheet_rows = 100;
				if(count($header_row) < 20) {
					$new_worksheet_cols = 20;
				} else {
					$new_worksheet_cols = 10 + count($header_row);
				}
				$worksheet = $spreadSheet->addWorksheet($this->form_info['save_to_sheet'], $new_worksheet_rows, $new_worksheet_cols);
				$worksheet->createHeader($header_row);
				if($new_spreadsheet_created && $this->form_info['save_to_sheet']!='Sheet 1') {
					$worksheetFeed = $spreadSheet->getWorksheets();
					$old_worksheet = $worksheetFeed->getFirstSheet();
					$old_worksheet->delete();
				}
			}
		} else {
			$worksheet = $worksheetFeed->getFirstSheet();
		}
		// check for unloaded worksheet
		if($worksheet===null) {
			echo "Google Sheet Error!  Unable to load Worksheet.";
			exit;
		}
		// load the meta data for the sheet
		if($this->form_info['google_spreadsheet_id']) {
			// load the Google Sheet by the referenced ID
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id'], $this->form_info['save_to_sheet']);
		} elseif($this->form_info['google_spreadsheet_name']) {
			// load the Google Sheet by the referenced name
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($this->form_info['google_spreadsheet_name'], $this->form_info['save_to_sheet']);
		}
		// build the new row to insert into the worksheet
		if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
			$row = array();
			foreach($this->form_info['inputs'] as $key=>$value) {
				if(isset($_POST[$key])) {
					$new_value = (string)$_POST[$key];
				} else {
					$new_value = '';
				}
				if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
					$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
				} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
					$row[$value['header_reference']] = $new_value;
				} else {
					// the referenced column wasn't found in the cached Google Sheet meta data... dump the cache and reload it, then check again
					$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
					if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
						$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
					} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
						$row[$value['header_reference']] = $new_value;
					} else {
						// the referenced column still wasn't found... attempt to create it
						$alphas = range('A', 'Z');
						$cellFeed = $worksheet->getCellFeed();
						$cellEntries = $cellFeed->getEntries();
						$cellEntry = $cellEntries[0];
						// if the column reference is a single letter, treat it as a letter reference, otherwise treat it as the header name
						if(strlen($value['header_reference'])==1) {
							// single letter header reference, assume this is a column letter name
							$cellEntry->setContent('Column ' . strtoupper($value['header_reference']));
							$new_column_number = array_search(strtoupper($value['header_reference']), $alphas) + 1;
						} else {
							// column header referenced by name, add the header at the first available letter
							$currently_used_column_letters = array_keys($spreadsheet_meta_data['column_name_by_letter']);
							sort($currently_used_column_letters);
							$ease_row_id_column = array_search('T', $currently_used_column_letters);
							if($ease_row_id_column!==false) {
								$last_column_used_key = $ease_row_id_column - 1;
								$last_column_used_letter = $currently_used_column_letters[$last_column_used_key];
							} else {
								$last_column_used_letter = end($currently_used_column_letters);
							}
							$new_column_number = array_search($last_column_used_letter, $alphas) + 2;
							$cellEntry->setContent($value['original_name']);
						}
						$cellEntry->setCell(1, $new_column_number);
						$cellEntry->update();
						// dump the cached meta data for the Google Sheet and reload it, then check again
						$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
						if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
							$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
						} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
							$row[$value['header_reference']] = $new_value;
						}
					}
				}
			}
			// insert the new row
			$new_row_has_nonempty_value = false;
			foreach($row as $new_row_value) {
				if(trim($new_row_value)!='') {
					$new_row_has_nonempty_value = true;
					break;
				}
			}
			if($new_row_has_nonempty_value) {
				if(isset($this->form_info['new_row_uuid']) && trim($this->form_info['new_row_uuid'])!='') {
					// force the UUID to only contain a-z and 0-9 and be at most 32 characters long
					$row['easerowid'] = preg_replace('/[^a-z0-9]+/is', '', strtolower($this->form_info['new_row_uuid']));
					if(strlen($row['easerowid'])>32) {
						$row['easerowid'] = substr($row['easerowid'], 0, 32);
					}
				} else {
					$row['easerowid'] = $this->core->new_uuid();
				}
				$listFeed = $worksheet->getListFeed();
				$listFeed->insert($row);
			}
		}
		// done adding row to Google Sheet... process any Form Actions
		// duplicate the row keys so there are values for both column letter and column name;
		// - this is done to simplify local variable injection while processing Form Actions
		foreach($row as $key=>$value) {
			$row[$spreadsheet_meta_data['column_letter_by_name'][$key]] = $value;
		}
		// execute any set cookie or session variable commands for the form action
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = rtrim($bucket_key_parts[0]);
				$key = ltrim($bucket_key_parts[1]);
				$this->inject_form_variables($value, $row['easerowid'], $row);
				switch(strtolower($bucket)) {
					case 'session':
						$_SESSION[$key] = $value;
						break;
					case 'cookie':
						setcookie($key, $value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $value;
						break;
					default:
				}
			}
		}
		// execute any SEND EMAIL commands for the form action
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $row['easerowid'], $row);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $row['easerowid'], $row);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $row['easerowid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $row['easerowid'], $row);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $row['easerowid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $row['easerowid'], $row);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(isset($this->form_info['redirect_to_by_action'][$action]) && trim($this->form_info['redirect_to_by_action'][$action])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $row['easerowid'], $row);
			header('Location: ' . trim($this->form_info['redirect_to_by_action'][$action]));
		} elseif(isset($this->form_info['redirect_to_by_action']['done']) && trim($this->form_info['redirect_to_by_action']['done'])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $row['easerowid'], $row);
			header('Location: ' . trim($this->form_info['redirect_to_by_action']['done']));
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function update_row_in_googlespreadsheet($action) {
		// refresh the google access token if necessary
		$this->core->validate_google_access_token();
		// initialize a google Google Sheet API client
		require_once 'ease/lib/Spreadsheet/Autoloader.php';
		$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
		$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
		Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
		$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
		// determine if the Google Sheet was referenced by name or ID
		$new_spreadsheet_created = false;
		if($this->form_info['google_spreadsheet_id']) {
			$spreadSheet = $spreadsheetService->getSpreadsheetById($this->form_info['google_spreadsheet_id']);
			if($spreadSheet===null) {
				// there was an error loading the Google Sheet by ID...
				// flush the cached meta data for the Google Sheet ID which may no longer be valid
				$this->core->flush_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id']);
				$this->form_info['google_spreadsheet_id'] = '';
				// try adding the row again using the Google Sheet name which will automatically re-create the Google Sheet
				$this->update_row_in_googlespreadsheet();
				exit;
			}
			$google_spreadsheet_id = $this->form_info['google_spreadsheet_id'];
		} elseif($this->form_info['google_spreadsheet_name']) {
			$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
			$spreadSheet = $spreadsheetFeed->getByTitle($this->form_info['google_spreadsheet_name']);
			if($spreadSheet===null) {
				// the supplied Google Sheet name did not match an existing Google Sheet
				// create a new Google Sheet using the supplied name
				// initialize a Google Drive API client
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
				$file->setTitle($this->form_info['google_spreadsheet_name']);
				$file->setDescription('EASE ' . $this->core->globals['system.domain']);
				$file->setMimeType('text/csv');
				// build the CSV formated header row string for the column names
				$alphas = range('A', 'Z');
				$header_row_csv = '';
				$prefix = '';
				foreach($this->form_info['inputs'] as $value) {
					$header_row_csv .= $prefix . '"' . str_replace('"', '""', $value['original_name']) . '"';
					$prefix = ', ';
				}
				// pad empty column name values up to column T
				$header_row_count = count($this->form_info['inputs']);
				while($header_row_count < 19) {
					$header_row_csv .= $prefix . '""';
					$header_row_count++;
				}
				// add column for the unique row ID used by the EASE core to enable row update and delete
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
				// upload the CSV content and convert it to a Google Sheet
				$new_spreadsheet = null;
				$try_count = 0;
				while($new_spreadsheet===null && $try_count<=5) {
					if($try_count > 0) {
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					$try_count++;
					try {
						$new_spreadsheet = $service->files->insert($file, array('data'=>$header_row_csv, 'mimeType'=>'text/csv', 'convert'=>'true', 'uploadType'=>'multipart'));
					} catch(Google_Service_Exception $e) {
						continue;
					}
				}
				// get the newly created Google Sheet ID
				$google_spreadsheet_id = $new_spreadsheet['id'];
				// ensure a Google Sheet ID was received
				if(!$google_spreadsheet_id) {
					echo 'Error!  Unable to create Google Sheet named: ' . htmlspecialchars($this->form_info['google_spreadsheet_name']);
					exit;
				}
				// cache the meta data for the new Google Sheet
				// meta data include: id, name, worksheet name, column name to letter map, column letter to name map
				$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($this->form_info['google_spreadsheet_name']);
				// load the newly created Google Sheet
				$spreadSheet = null;
				$try_count = 0;
				while($spreadSheet===null && $try_count<=5) {
					if($try_count > 0) {
						sleep((2 ^ ($try_count - 1)) + (mt_rand(1, 1000) / 1000));
					}
					$spreadSheet = $spreadsheetService->getSpreadsheetById($google_spreadsheet_id);
					$try_count++;
				}
				$new_spreadsheet_created = true;
			}
		}
		// ensure a Google Sheet was laoded
		if($spreadSheet===null) {
			echo 'Error!  Unable to load Google Sheet';
			exit;
		}
		// load the worksheets in the Google Sheet
		$worksheetFeed = $spreadSheet->getWorksheets();
		if($this->form_info['save_to_sheet']) {
			$worksheet = $worksheetFeed->getByTitle($this->form_info['save_to_sheet']);
			if($worksheet===null) {
				// the supplied worksheet name did not match an existing worksheet of the Google Sheet;  create a new worksheet using the supplied name
				$header_row = array();
				foreach($this->form_info['inputs'] as $value) {
					$header_row[] = $value['original_name'];
				}
				// pad empty values up to column T
				$header_row_count = count($this->form_info['inputs']);
				while($header_row_count < 19) {
					$header_row[] = '';
					$header_row_count++;
				}
				// add column for unique row ID used by the EASE core to enable row update and delete
				$header_row[] = 'EASE Row ID';
				$new_worksheet_rows = 100;
				if(count($header_row) < 20) {
					$new_worksheet_cols = 20;
				} else {
					$new_worksheet_cols = 10 + count($header_row);
				}
				$worksheet = $spreadSheet->addWorksheet($this->form_info['save_to_sheet'], $new_worksheet_rows, $new_worksheet_cols);
				$worksheet->createHeader($header_row);
				if($new_spreadsheet_created && $this->form_info['save_to_sheet']!='Sheet 1') {
					$worksheetFeed = $spreadSheet->getWorksheets();
					$old_worksheet = $worksheetFeed->getFirstSheet();
					$old_worksheet->delete();
				}
			}
		} else {
			$worksheet = $worksheetFeed->getFirstSheet();
		}
		// ensure a worksheet has been loaded
		if($worksheet===null) {
			echo 'Google Sheet Error!  Unable to load Worksheet.';
			exit;
		}
		// load the meta data for the Google Sheet
		if($this->form_info['google_spreadsheet_id']) {
			// Google Sheet was referenced by ID
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id'], $this->form_info['save_to_sheet']);
		} elseif($this->form_info['google_spreadsheet_name']) {
			// Google Sheet was referenced by "Name"
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($this->form_info['google_spreadsheet_name'], $this->form_info['save_to_sheet']);
		}
		// build the updated row to replace into the sheet
		if(isset($this->form_info['inputs']) && is_array($this->form_info['inputs'])) {
			$row = array();
			foreach($this->form_info['inputs'] as $key=>$value) {
				if(isset($_POST[$key])) {
					$new_value = (string)$_POST[$key];
				} else {
					$new_value = '';
				}
				if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
					$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
				} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
					$row[$value['header_reference']] = $new_value;
				} else {
					// the referenced column wasn't found in the cached Google Sheet meta data... dump the cache and reload it, then check again
					$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
					$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
					if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
						$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
					} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
						$row[$value['header_reference']] = $new_value;
					} else {
						// the referenced column still wasn't found... attempt to create it
						$alphas = range('A', 'Z');
						$cellFeed = $worksheet->getCellFeed();
						$cellEntries = $cellFeed->getEntries();
						$cellEntry = $cellEntries[0];
						// if the column reference is a single letter, treat it as a letter reference, otherwise treat it as the header name
						if(strlen($value['header_reference'])==1) {
							// single letter header reference, assume this is a column letter name
							$cellEntry->setContent('Column ' . strtoupper($value['header_reference']));
							$new_column_number = array_search(strtoupper($value['header_reference']), $alphas) + 1;
						} else {
							// column header referenced by name, add the header at the first available letter
							$currently_used_column_letters = array_keys($spreadsheet_meta_data['column_name_by_letter']);
							sort($currently_used_column_letters);
							$ease_row_id_column = array_search('T', $currently_used_column_letters);
							if($ease_row_id_column!==false) {
								$last_column_used_key = $ease_row_id_column - 1;
								$last_column_used_letter = $currently_used_column_letters[$last_column_used_key];
							} else {
								$last_column_used_letter = end($currently_used_column_letters);
							}
							$new_column_number = array_search($last_column_used_letter, $alphas) + 2;
							$cellEntry->setContent($value['original_name']);
						}
						$cellEntry->setCell(1, $new_column_number);
						$cellEntry->update();
						// dump the cached meta data for the Google Sheet and reload it, then check again
						$this->core->flush_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
						$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($spreadsheet_meta_data['id'], $spreadsheet_meta_data['worksheet']);
						if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])])) {
							$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($value['header_reference'])]] = $new_value;
						} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$value['header_reference']])) {
							$row[$value['header_reference']] = $new_value;
						}
					}
				}
			}
			// new values found... update the Google Sheet Row
			foreach($row as $key=>$value) {
				$key = preg_replace('/(^[0-9]+|[^a-z0-9]+)/s', '', strtolower($key));
				if(isset($spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)])) {
					// the column was referenced by column letter... change it to reference the column header name
					unset($row[$key]);
					$row[$spreadsheet_meta_data['column_name_by_letter'][strtoupper($key)]] = $value;
				} elseif(isset($spreadsheet_meta_data['column_letter_by_name'][$key])) {
					// the column was referenced by an existing column header name
				} else {
					// the referenced column wasn't found in the cached Google Sheet meta data...
					//	dump the cache and reload it, then check again... if it still isn't found, create it
					// TODO!! consolidate all the code that does this into a core function
				}
			}
			// query for the row to update
			$listFeed = $worksheet->getListFeed('', '', "easerowid = \"{$this->form_info['row_uuid']}\"");
			$listEntries = $listFeed->getEntries();
			// update all rows that matched the requested EASE Row ID value
			// TODO!! if a matching record wasn't found, treat this as a create record command instead
			foreach($listEntries as $listEntry) {
				$current_row = $listEntry->getValues();
				foreach($current_row as $key=>$value) {
					if(!isset($row[$key])) {
						$row[$key] = $value;
					}
				}
				$listEntry->update($row);
			}
		}
		// done updating row in Google Sheet... process any Form Actions		
		// insert a key value in the new row for each column value by letter instead of header name
		foreach($row as $key=>$value) {
			$row[$spreadsheet_meta_data['column_letter_by_name'][$key]] = $value;
		}
		// execute any set cookie or session variable commands for the form action
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $set_to_command) {
			$value = $set_to_command['value'];
			$this->inject_form_variables($value, $this->form_info['row_uuid'], $row);
			switch(strtolower($set_to_command['bucket'])) {
				case 'session':
					$_SESSION[$set_to_command['key']] = $value;
					break;
				case 'cookie':
					setcookie($set_to_command['key'], $value, time() + 60 * 60 * 24 * 365, '/');
					$_COOKIE[$set_to_command['key']] = $value;
					break;
				default:
			}
		}
		// execute any SEND EMAIL when creating commands
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $this->form_info['row_uuid'], $row);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $this->form_info['row_uuid'], $row);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $this->form_info['row_uuid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $this->form_info['row_uuid'], $row);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $this->form_info['row_uuid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $this->form_info['row_uuid'], $row);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(trim($this->form_info['redirect_to_by_action'][$action])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $this->form_info['row_uuid'], $row);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(trim($this->form_info['redirect_to_by_action']['done'])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $this->form_info['row_uuid'], $row);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function delete_row_from_googlespreadsheet($action) {
		// refresh the google access token if necessary
		$this->core->validate_google_access_token();
		// initialize a google Google Sheet API client
		require_once 'ease/lib/Spreadsheet/Autoloader.php';
		$request = new Google\Spreadsheet\Request($this->core->config['gapp_access_token']);
		$serviceRequest = new Google\Spreadsheet\DefaultServiceRequest($request);
		Google\Spreadsheet\ServiceRequestFactory::setInstance($serviceRequest);
		$spreadsheetService = new Google\Spreadsheet\SpreadsheetService($request);
		// determine if the Google Sheet was referenced by name or ID
		if($this->form_info['google_spreadsheet_id']) {
			$spreadSheet = $spreadsheetService->getSpreadsheetById($this->form_info['google_spreadsheet_id']);
			if($spreadSheet===null) {
				// there was an error loading the Google Sheet by ID...
				// flush the cached meta data for the Google Sheet ID which may no longer be valid
				$this->core->flush_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id']);
				// try deleting the row again after blanking out the ID, so the Google Sheet "Name" will be used if set
				$this->form_info['google_spreadsheet_id'] = '';
				$this->delete_row_from_googlespreadsheet();
				exit;
			}
			$google_spreadsheet_id = $this->form_info['google_spreadsheet_id'];
		} elseif($this->form_info['google_spreadsheet_name']) {
			$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
			$spreadSheet = $spreadsheetFeed->getByTitle($this->form_info['google_spreadsheet_name']);
			if($spreadSheet===null) {
				echo 'Error!  Unable to load Google Sheet named: ' . htmlspecialchars($this->form_info['google_spreadsheet_name']);
				exit;
			}
		}
		// ensure a Google Sheet was laoded
		if($spreadSheet===null) {
			echo 'Error!  Unable to load Google Sheet';
			exit;
		}
		// load the worksheets in the Google Sheet
		$worksheetFeed = $spreadSheet->getWorksheets();
		if($this->form_info['save_to_sheet']) {
			$worksheet = $worksheetFeed->getByTitle($this->form_info['save_to_sheet']);
			if($worksheet===null) {
				echo 'Error!  Unable to load Google Drive Worksheet named: ' . htmlspecialchars($this->form_info['save_to_sheet']);
				exit;
			}
		} else {
			$worksheet = $worksheetFeed->getFirstSheet();
		}
		// check for unloaded worksheet
		if($worksheet===null) {
			echo 'Error!  Unable to load Google Drive Worksheet';
			exit;
		}
		// load the meta data for the sheet
		if($this->form_info['google_spreadsheet_id']) {
			// Google Sheet was referenced by ID
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_id($this->form_info['google_spreadsheet_id'], $this->form_info['save_to_sheet']);
		} elseif($this->form_info['google_spreadsheet_name']) {
			// Google Sheet was referenced by "Name"
			$spreadsheet_meta_data = $this->core->load_meta_data_for_google_spreadsheet_by_name($this->form_info['google_spreadsheet_name'], $this->form_info['save_to_sheet']);
		}
		// query for the row to delete
		$listFeed = $worksheet->getListFeed('', '', "easerowid = \"{$this->form_info['row_uuid']}\"");
		$listEntries = $listFeed->getEntries();
		// delete all rows that matched the requested EASE Row ID value
		foreach($listEntries as $listEntry) {
			$listEntry->delete();
		}
		// done deleting row from Google Sheet... process any Form Actions		
		// execute any set cookie or session variable commands for the form action
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $set_to_command) {
			$value = $set_to_command['value'];
			$this->inject_form_variables($value, $this->form_info['row_uuid'], $row);
			switch(strtolower($set_to_command['bucket'])) {
				case 'session':
					$_SESSION[$set_to_command['key']] = $value;
					break;
				case 'cookie':
					setcookie($set_to_command['key'], $value, time() + 60 * 60 * 24 * 365, '/');
					$_COOKIE[$set_to_command['key']] = $value;
					break;
				default:
			}
		}
		// execute any SEND EMAIL when creating commands
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $this->form_info['row_uuid'], $row);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $this->form_info['row_uuid'], $row);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $this->form_info['row_uuid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $this->form_info['row_uuid'], $row);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $this->form_info['row_uuid'], $row);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $this->form_info['row_uuid'], $row);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from form actions
		if(trim($this->form_info['redirect_to_by_action'][$action])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $this->form_info['row_uuid'], $row);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(trim($this->form_info['redirect_to_by_action']['done'])!='') {
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $this->form_info['row_uuid'], $row);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function process_checklist($action) {
		// only process SQL backed checklist forms if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			$namespaced_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $this->form_info['sql_table_name']), '_');
			// query for a list of checkable row IDs
			$checklist_rows = array();
			$query = "	SELECT `$namespaced_sql_table_name`.uuid
						FROM `$namespaced_sql_table_name`
							{$this->form_info['join_sql_string']}
						{$this->form_info['where_sql_string']}
						{$this->form_info['order_by_sql_string']};	";
			if($result = $this->core->db->query($query)) {
				$checklist_rows = $result->fetchAll(PDO::FETCH_ASSOC);
				// process the SET commands for the form action, and build column=>value update arrays based on checked status
				$update_column_when_checked = array();
				@$this->form_info['set_to_list_by_action'][$action . '-checked'] = array_merge(
					(array)$this->form_info['set_to_list_by_action'][$action . '-checked'],
					(array)$this->form_info['set_to_list_by_action'][$action],
					(array)$this->form_info['set_to_list_by_action']['done'],
					(array)$this->form_info['set_to_list_by_action']['checked']
				);
				foreach($this->form_info['set_to_list_by_action'][$action . '-checked'] as $bucket=>$value) {
					$column_reference = null;
					$bucket_key_parts = explode('.', $bucket, 2);
					if(count($bucket_key_parts)==2) {
						$table_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(rtrim($bucket_key_parts[0])));
						if($table_reference==$this->form_info['sql_table_name'] || $table_reference=='row') {
							$column_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(ltrim($bucket_key_parts[1])));
						}
					} else {
						$column_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(trim($bucket)));
					}
					if($column_reference) {
						$update_column_when_checked[$column_reference] = $value;
					}
				}
				$update_column_when_unchecked = array();
				@$this->form_info['set_to_list_by_action'][$action . '-unchecked'] = array_merge(
					(array)$this->form_info['set_to_list_by_action'][$action . '-unchecked'],
					(array)$this->form_info['set_to_list_by_action'][$action],
					(array)$this->form_info['set_to_list_by_action']['done'],
					(array)$this->form_info['set_to_list_by_action']['unchecked']
				);
				foreach($this->form_info['set_to_list_by_action'][$action . '-unchecked'] as $bucket=>$value) {
					$column_reference = null;
					$bucket_key_parts = explode('.', $bucket, 2);
					if(count($bucket_key_parts)==2) {
						$table_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(rtrim($bucket_key_parts[0])));
						if($table_reference==$this->form_info['sql_table_name'] || $table_reference=='row') {
							$column_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(ltrim($bucket_key_parts[1])));
						}
					} else {
						$column_reference = preg_replace('/[^a-z0-9]+/is', '_', strtolower(trim($bucket)));
					}
					if($column_reference) {
						$update_column_when_unchecked[$column_reference] = $value;
					}
				}
				// validate the referenced SQL table exists by querying for all column names
				$result = $this->core->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='$namespaced_sql_table_name' AND TABLE_SCHEMA=database();");
				if($existing_columns = $result->fetchAll(PDO::FETCH_COLUMN)) {
					$all_referenced_columns = array_merge(array_keys($update_column_when_checked), array_keys($update_column_when_unchecked));
					foreach($all_referenced_columns as $column) {
						if(!in_array($column, $existing_columns)) {
							$this->core->db->exec("ALTER TABLE `$namespaced_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
						}
					}
				}
				// process each checkable row, and apply any check action updates from SET commands
				foreach($checklist_rows as $row_key=>$row) {
					if(isset($_POST['ease_checklist_item_' . $row['uuid']]) && trim($_POST['ease_checklist_item_' . $row['uuid']])!='') {
						// the row was checked
						foreach($update_column_when_checked as $column=>$value) {
							$query = $this->core->db->prepare("UPDATE `$namespaced_sql_table_name` SET `$column`=:$column WHERE uuid=:uuid;");
							$params = array(":$column"=>$value, ':uuid'=>$row['uuid']);
							$result = $query->execute($params);
						}
					} else {
						// the row was NOT checked
						foreach($update_column_when_unchecked as $column=>$value) {
							$query = $this->core->db->prepare("UPDATE `$namespaced_sql_table_name` SET `$column`=:$column WHERE uuid=:uuid;");
							$params = array(":$column"=>$value, ':uuid'=>$row['uuid']);
							$result = $query->execute($params);
						}
					}
				}
			} else {
				// no results from the checklist query for checkable rows... check for a DB error, even though that should never happen
				$error = $this->core->db->errorInfo();
				if($error[0]!='00000') {
					$this->core->html_dump($error, 'Checklist Query Error');
					$this->core->html_dump($this->form_info, 'EASE Form info');
					$this->core->html_dump($_POST, 'POST data');
					exit;
				}
			}
		}
		// done updating instances in the SQL Table... process any Form Actions
		// execute any set cookie or session variable commands for the form action
		@$this->form_info['set_to_list_by_action'][$action] = array_merge(
			(array)$this->form_info['set_to_list_by_action'][$action],
			(array)$this->form_info['set_to_list_by_action']['done']
		);
		foreach($this->form_info['set_to_list_by_action'][$action] as $bucket_key=>$value) {
			$bucket_key_parts = explode('.', $bucket_key, 2);
			if(count($bucket_key_parts)==2) {
				$bucket = strtolower(rtrim($bucket_key_parts[0]));
				$key = ltrim($bucket_key_parts[1]);
				$this->inject_form_variables($value, $params[':uuid']);
				switch($bucket) {
					case 'session':
						$_SESSION[$key] = $value;
						break;
					case 'cookie':
						setcookie($key, $value, time() + 60 * 60 * 24 * 365, '/');
						$_COOKIE[$key] = $value;
						break;
					default:
				}
			}
		}
		// execute any SEND EMAIL commands for the checklist form action
		@$this->form_info['send_email_list_by_action'][$action] = array_merge(
			(array)$this->form_info['send_email_list_by_action'][$action],
			(array)$this->form_info['send_email_list_by_action']['done']
		);
		foreach($this->form_info['send_email_list_by_action'][$action] as $mail_options) {
			foreach(array_keys($mail_options) as $mail_option_key) {
				$this->inject_form_variables($mail_options[$mail_option_key], $params[':uuid']);
			}
			$result = $this->core->send_email($mail_options);
		}
		// only process SQL backed form actions if a database connection exists and hasn't been disabled
		if($this->core->db && !$this->core->db_disabled) {
			// execute any CREATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['create_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['create_sql_record_list_by_action'][$action],
				(array)$this->form_info['create_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['create_sql_record_list_by_action'][$action] as $create_sql_record) {
				$this->inject_form_variables($create_sql_record['for'], $params[':uuid']);
				$create_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($create_sql_record['for'])));
				$create_sql_record_new_row = array();
				if(isset($create_sql_record['set_to_commands']) && is_array($create_sql_record['set_to_commands'])) {
					foreach($create_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$create_sql_record['for'] || $set_to_command['bucket']==$create_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid']);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$create_sql_record_new_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($create_sql_record['round_to_commands']) && is_array($create_sql_record['round_to_commands'])) {
					foreach($create_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$create_sql_record['for'] || $round_to_command['bucket']==$create_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$create_sql_record_new_row[$round_to_command['key']] = round($create_sql_record_new_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_create_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $create_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_create_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_create_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				} else {
					// the SQL Table doesn't exist; create it with all of the columns referenced in the new row
					$custom_columns_sql = '';
					foreach(array_keys($create_sql_record_new_row) as $column) {
						if(!in_array($column, $this->core->reserved_sql_columns)) {
							if(sizeof($create_sql_record_new_row[$column]) > 65535) {
								$custom_columns_sql .= ", `$column` mediumtext NOT NULL default ''";
							} else {
								$custom_columns_sql .= ", `$column` text NOT NULL default ''";
							}
						}
					}
					$sql = "	CREATE TABLE `$namespaced_create_for_sql_table_name` (
									instance_id int NOT NULL PRIMARY KEY auto_increment,
									created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
									updated_on timestamp NOT NULL,
									uuid varchar(32) NOT NULL UNIQUE
									$custom_columns_sql
								);	";
					$this->core->db->exec($sql);
				}
				// insert the new row
				$create_sql_record_params = array();
				if(isset($create_sql_record_new_row['uuid'])) {
					$create_sql_record_params[':uuid'] = $create_sql_record_new_row['uuid'];
					unset($create_sql_record_new_row['uuid']);
				} else {
					$create_sql_record_params[':uuid'] = $this->core->new_uuid();
				}
				$insert_columns_sql = '';
				foreach($create_sql_record_new_row as $key=>$value) {
					$insert_columns_sql .= ",`$key`=:$key";
					$create_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	REPLACE INTO `$namespaced_create_for_sql_table_name`
													SET uuid=:uuid
														$insert_columns_sql;	");
				$query->execute($create_sql_record_params);
			}
			// execute any UPDATE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['update_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['update_sql_record_list_by_action'][$action],
				(array)$this->form_info['update_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['update_sql_record_list_by_action'][$action] as $update_sql_record) {
				$this->inject_form_variables($update_sql_record['for'], $params[':uuid']);
				$for_parts = explode('.', $update_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$update_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$update_sql_record_row = array('uuid'=>trim($for_parts[1]));
				} else {
					// update record with no referenced record to update.... hmm...
					continue;
				}
				if(isset($update_sql_record['set_to_commands']) && is_array($update_sql_record['set_to_commands'])) {
					foreach($update_sql_record['set_to_commands'] as $set_to_command) {
						if($set_to_command['bucket']=='' || $set_to_command['bucket']==$update_sql_record['for'] || $set_to_command['bucket']==$update_sql_record['as']) {
							$this->inject_form_variables($set_to_command['value'], $params[':uuid']);
							$context_stack = ease_interpreter::extract_context_stack($set_to_command['value']);
							if(preg_match('/^\s*"(.*)"\s*$/s', $set_to_command['value'], $inner_matches)) {
								// set to quoted string
								$set_value = $inner_matches[1];
							} elseif(preg_match('/^[() ^!%0-9\.+\*\/-]+$/s', $set_to_command['value'], $inner_matches)) {
								// math expression value, evaluate the expression to calculate value
								$eval_result = @eval("\$set_value = {$set_to_command['value']};");
								if($eval_result===false) {
									// there was an error evaluating the math expression... set the value to the broken math expression
									$set_value = $set_to_command['value'];
								}
							} else {
								$set_value = '';
							}
							ease_interpreter::apply_context_stack($set_value, $context_stack);
							$update_sql_record_row[$set_to_command['key']] = $set_value;
						}
					}
				}
				if(isset($update_sql_record['round_to_commands']) && is_array($update_sql_record['round_to_commands'])) {
					foreach($update_sql_record['round_to_commands'] as $round_to_command) {
						if($round_to_command['bucket']=='' || $round_to_command['bucket']==$update_sql_record['for'] || $round_to_command['bucket']==$update_sql_record['as']) {
							if(isset($round_to_command['decimals'])) {
								$update_sql_record_row[$round_to_command['key']] = round($update_sql_record_row[$round_to_command['key']], $round_to_command['decimals']);
							}
						}
					}
				}
				$namespaced_update_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $update_sql_record['for']), '_');
				// make sure the SQL Table exists and has all the columns referenced in the new row
				$result = $this->core->db->query("DESCRIBE `$namespaced_update_for_sql_table_name`;");
				if($result) {
					// the SQL Table exists; make sure all of the columns referenced in the new row exist in the table
					$existing_columns = $result->fetchAll(PDO::FETCH_COLUMN);
					foreach(array_keys($update_sql_record_row) as $column) {
						if(!in_array($column, $existing_columns)) {
							if(sizeof($update_sql_record_row[$column]) > 65535) {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` mediumtext NOT NULL default '';");
							} else {
								$this->core->db->exec("ALTER TABLE `$namespaced_update_for_sql_table_name` ADD COLUMN `$column` text NOT NULL DEFAULT '';");
							}
						}
					}
				}
				// build the query to update the row
				$update_sql_record_params = array(':uuid'=>$update_sql_record_row['uuid']);
				unset($update_sql_record_row['uuid']);
				$update_columns_sql = '';
				foreach($update_sql_record_row as $key=>$value) {
					$update_columns_sql .= ",`$key`=:$key";
					$update_sql_record_params[":$key"] = (string)$value;
				}
				$query = $this->core->db->prepare("	UPDATE `$namespaced_update_for_sql_table_name`
													SET updated_on=NOW()
														$update_columns_sql
													WHERE uuid=:uuid;	");
				$result = $query->execute($update_sql_record_params);
				// TODO!! check for query errors... the only error could be saving more than 65535 characters in a text type column: ALTER to mediumtext
			}
			// execute any DELETE RECORD - FOR CLOUD SQL TABLE commands for the form action
			@$this->form_info['delete_sql_record_list_by_action'][$action] = array_merge(
				(array)$this->form_info['delete_sql_record_list_by_action'][$action],
				(array)$this->form_info['delete_sql_record_list_by_action']['done']
			);
			foreach($this->form_info['delete_sql_record_list_by_action'][$action] as $delete_sql_record) {
				$this->inject_form_variables($delete_sql_record['for'], $params[':uuid']);
				$for_parts = explode('.', $delete_sql_record['for'], 2);
				if(count($for_parts)==2) {
					$delete_sql_record['for'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($for_parts[0])));
					$delete_sql_record_params = array(':uuid'=>trim($for_parts[1]));
				} else {
					// delete record with no referenced record to delete.... hmm...
					continue;
				}
				$namespaced_delete_for_sql_table_name = trim(preg_replace('/[^a-z0-9]+/is', '_', $this->core->namespace . '_' . $delete_sql_record['for']), '_');
				// build the query to delete the row
				$query = $this->core->db->prepare("DELETE FROM `$namespaced_delete_for_sql_table_name` WHERE uuid=:uuid;");
				$result = $query->execute($delete_sql_record_params);
			}
		}
		// execute any redirect commands from checklist form action
		if(isset($this->form_info['redirect_to_by_action'][$action])) {
			// redirect landing page was set for the processed form action
			$this->inject_form_variables($this->form_info['redirect_to_by_action'][$action], $params[':uuid']);
			header('Location: ' . $this->form_info['redirect_to_by_action'][$action]);
		} elseif(isset($this->form_info['redirect_to_by_action']['done'])) {
			// redirect landing page was set for the 'done' form action
			$this->inject_form_variables($this->form_info['redirect_to_by_action']['done'], $params[':uuid']);
			header('Location: ' . $this->form_info['redirect_to_by_action']['done']);
		} else {
			// a redirect landing page was not set... default to the homepage
			header('Location: /');
		}
	}

	function inject_form_variables(&$string, $id, $form=array(), $existing=array()) {
		// this function will return true if any injections were made, otherwise false
		$injected = false;
		$string = preg_replace_callback(
			'/' . preg_quote($this->core->ease_block_start, '/') . '\s*(.*?)\s*' . preg_quote($this->core->ease_block_end, '/') . '/is',
			function($matches) use (&$injected, $id, $form, $existing) {
				// process the variable name to determine the type and context
				$injected = true;
				$bucket_key_parts = explode('.', $matches[1], 2);
				if(count($bucket_key_parts)==2) {
					$bucket = preg_replace('/[^a-z0-9]+/', '_', strtolower(rtrim($bucket_key_parts[0])));
					$key = ltrim($bucket_key_parts[1]);
				} else {
					// this is an injection for an existing record value
					$bucket = '';
					$key = preg_replace('/[^a-z0-9]+/', '_', strtolower($matches[1]));
				}
				$context_stack = ease_interpreter::extract_context_stack($key);
				if($bucket=='form') {
					if(strtolower($key)=='id' || strtolower($key)=='uuid' || strtolower($key)=='easerowid') {
						$value = $id;
					} else {
						// Cloud SQL, Google Sheets, and memcached have different allowed character sets for bucket.key names...
						// allow any of the formats to determine the value to inject
						if(isset($form[$key])) {
							$value = $form[$key];
						} elseif(isset($form[strtoupper($key)])) {
							$value = $form[strtoupper($key)];
						} elseif(isset($form[strtolower($key)])) {
							$value = $form[strtolower($key)];
						} elseif(isset($form[preg_replace('/(^[0-9]+|[^a-z0-9]+)/is', '', strtolower($key))])) {
							$value = $form[preg_replace('/(^[0-9]+|[^a-z0-9]+)/is', '', strtolower($key))];
						} elseif(isset($form[preg_replace('/[^a-z0-9]+/is', '_', strtolower($key))])) {
							$value = $form[preg_replace('/[^a-z0-9]+/is', '_', strtolower($key))];
						} else {
							$value = '';
						}
					}
				} elseif($bucket=='' || $bucket==$this->form_info['sql_table_name'] || $bucket=='row') {
					// Cloud SQL, Google Sheets, and memcached have different allowed character sets for bucket.key names...
					// allow any of the formats to determine the value to inject
					if(isset($existing[$key])) {
						$value = $existing[$key];
					} elseif(isset($existing[strtoupper($key)])) {
						$value = $existing[strtoupper($key)];
					} elseif(isset($existing[strtolower($key)])) {
						$value = $existing[strtolower($key)];
					} elseif(isset($existing[preg_replace('/(^[0-9]+|[^a-z0-9]+)/is', '', strtolower($key))])) {
						$value = $existing[preg_replace('/(^[0-9]+|[^a-z0-9]+)/is', '', strtolower($key))];
					} elseif(isset($existing[preg_replace('/[^a-z0-9]+/is', '_', strtolower($key))])) {
						$value = $existing[preg_replace('/[^a-z0-9]+/is', '_', strtolower($key))];
					} else {
						$value = '';
					}
				}
				// process the context stack for items that use variable references such as salt variables for hashing
				if(is_array($context_stack) && count($context_stack) > 0) {
					foreach($context_stack as $key=>$item) {
						if(isset($item['context']) && $item['context']=='hash' && isset($item['salt_var']) && trim($item['salt_var'])!='') {
							$hash_salt_var = $this->core->ease_block_start . $item['salt_var'] . $this->core->ease_block_end;
							$this->inject_form_variables($hash_salt_var, $id, $form, $existing);							
							$context_stack[$key]['salt'] = $hash_salt_var;
						}
					}
				}
				ease_interpreter::apply_context_stack($value, $context_stack);
				return $value;
			},
			$string
		);
		return $injected;
	}

}
