<?php
/*
* Copyright 2014 Cloudward, Inc.  All Rights Reserved.
*/

/**
 * this script handles EASE Snippet processing requests from HTML documents via JavaScript
 *
 * @author Mike <mike@cloudward.com>
 */

// initialize the EASE Framework
require_once('ease/core.class.php');
$ease_core = new ease_core();

// the Snippet ID can be provided in many ways:  (the first value found will be used... cloudward_snippet_id is preferred)
if((!isset($_REQUEST['cloudward_snippet_id'])) || (trim($_REQUEST['cloudward_snippet_id'])=='')) {
	if(isset($_REQUEST['ease_processor_snippet_id']) && (trim($_REQUEST['ease_processor_snippet_id'])!='')) {
		$_REQUEST['cloudward_snippet_id'] = $_REQUEST['ease_processor_snippet_id'];
	} elseif(isset($_REQUEST['ease_snippet_id']) && (trim($_REQUEST['ease_snippet_id'])!='')) {
		$_REQUEST['cloudward_snippet_id'] = $_REQUEST['ease_snippet_id'];
	} elseif(isset($_REQUEST['snippet_id']) && (trim($_REQUEST['snippet_id'])!='')) {
		$_REQUEST['cloudward_snippet_id'] = $_REQUEST['snippet_id'];
	} elseif(isset($_REQUEST['id']) && (trim($_REQUEST['id'])!='')) {
		$_REQUEST['cloudward_snippet_id'] = $_REQUEST['id'];
	}
}
if((!isset($_REQUEST['cloudward_snippet_id'])) && isset($_SERVER['QUERY_STRING']) && (trim($_SERVER['QUERY_STRING'])!='')) {
	// a Snippet ID was not provided as a GET or POST HTTP param... treat the entire URL query string as the Snippet ID
	$_REQUEST['cloudward_snippet_id'] = $_SERVER['QUERY_STRING'];
}

// the Session ID can be provided in many ways:  (the first value found will be used... cloudward_snippet_session_id is preferred)
if((!isset($_REQUEST['cloudward_snippet_session_id'])) || (trim($_REQUEST['cloudward_snippet_session_id'])=='')) {
	if(isset($_REQUEST['ease_processor_session_id']) && (trim($_REQUEST['ease_processor_session_id'])!='')) {
		$_REQUEST['cloudward_snippet_session_id'] = $_REQUEST['ease_processor_session_id'];
	} elseif(isset($_REQUEST['cloudward_session_id']) && (trim($_REQUEST['cloudward_session_id'])!='')) {
		$_REQUEST['cloudward_snippet_session_id'] = $_REQUEST['cloudward_session_id'];
	} elseif(isset($_REQUEST['ease_session_id']) && (trim($_REQUEST['ease_session_id'])!='')) {
		$_REQUEST['cloudward_snippet_session_id'] = $_REQUEST['ease_session_id'];
	} elseif(isset($_REQUEST['session_id']) && (trim($_REQUEST['session_id'])!='')) {
		$_REQUEST['cloudward_snippet_session_id'] = $_REQUEST['session_id'];		
	}
} 
if(isset($_REQUEST['cloudward_snippet_session_id']) && trim($_REQUEST['cloudward_snippet_session_id'])!='') {
	// a Session ID value was provided... use it
	session_id($_REQUEST['cloudward_snippet_session_id']);
}

// initialize the session, loading current session data into global variables
session_start();

// check for a database
if($ease_core->db) {
	// check for a requested Snippet ID
	if(isset($_REQUEST['cloudward_snippet_id']) && (trim($_REQUEST['cloudward_snippet_id'])!='')) {
		// Snippet ID provided... query for Snippet info
		$query = $ease_core->db->prepare("SELECT * FROM snippets WHERE uuid=:uuid;");
		if($query->execute(array(':uuid'=>$_REQUEST['cloudward_snippet_id']))) {
			// query successful... fetch Snippet info
			if($snippet = $query->fetch(PDO::FETCH_ASSOC)) {
				// Snippet info fetched successfully... query for Snippet Account info
				if((!isset($snippet['status'])) || $snippet['status']!='inactive') {
					// Snippet was not inactivated
					$query = $ease_core->db->prepare("SELECT * FROM accounts WHERE uuid=:uuid;");
					if($query->execute(array(':uuid'=>$snippet['account_id']))) {
						// query successful... fetch Snippet Account info
						if($account = $query->fetch(PDO::FETCH_ASSOC)) {
							// Snippet Account info fetched successfully
							if((!isset($account['status'])) || $account['status']!='inactive') {
								// Snippet Account was not inactivated... validate referring domain
								$referring_domain_validated = false;
								if(isset($_SERVER['HTTP_REFERER']) && trim($_SERVER['HTTP_REFERER'])!='') {
									$referring_host_parts = parse_url($_SERVER['HTTP_REFERER']);
									$referring_host = strtolower($referring_host_parts['host']);
									if(isset($referring_host_parts['port']) && trim($referring_host_parts['port'])!='') {
										$referring_host .= ':' . $referring_host_parts['port'];
									}
									if(strtolower($referring_host_parts['host'])=='localhost'
									  || $referring_host=='snippets.cloudward.com'
									  || $referring_host=='ease-processor.appspot.com') {
										$referring_domain_validated = true;
									} else {
										$valid_domains = explode(',', $account['domain']);
										foreach($valid_domains as $domain) {
											$domain = strtolower(trim($domain));
											if($referring_host==$domain || $domain=='*') {
												$referring_domain_validated = true;
												break;
											}
										}
									}
								} else {
									// referrer not provided... likely a direct request to the javascript for testing purposes... allow it
									$referring_domain_validated = true;
								}
								if($referring_domain_validated) {
									// referring domain is valid... check if there is a Snippet Hit Quota set for the account
									if((!isset($account['snippet_hits_remaining']))
									  || (trim($account['snippet_hits_remaining'])!='')
									  || ($account['snippet_hits_remaining'] > 0)) {
										// Snippet Hit Quota has not been reached
										if(isset($account['snippet_hits_remaining']) && trim($account['snippet_hits_remaining'])!='') {
											// a remaining number of Snippet Hits was set... decrement that number to account for this hit
											$query = $ease_core->db->prepare("UPDATE accounts SET snippet_hits_remaining=snippet_hits_remaining-1 WHERE uuid=:uuid;");
											$query->execute(array(':uuid'=>$snippet['account_id']));
										}							
										// configure the EASE Framework to use the Google Drive API Access Tokens associated with the Snippet Account
										$ease_core->set_global_system_vars();
										$ease_core->service_endpoints['form'] = $ease_core->globals['system.secure_host_url'] . '/form_handler?cloudward_snippet_id=' . urlencode($_REQUEST['cloudward_snippet_id']);
										$ease_core->service_endpoints['oauth2callback'] = '/account_init_oauth2callback';
										$ease_core->set_system_config_var('gapp_access_token_json', $account['gapp_access_token_json']);
										$ease_core->set_system_config_var('gapp_access_token', $account['gapp_access_token']);
										$ease_core->set_system_config_var('gapp_expire_time', $account['gapp_expire_time']);
										$ease_core->set_system_config_var('gapp_refresh_token', $account['gapp_refresh_token']);
										// validate the Google Drive API Access Token for the Snippet Account... refreshing if necessary
										$ease_core->validate_google_access_token();
										if(trim($ease_core->config['gapp_access_token_json'])!=''
										  && $ease_core->config['gapp_access_token_json']!=$account['gapp_access_token_json']
										  && trim($ease_core->config['gapp_refresh_token'])!='') {
											// a new or refreshed Google Drive API Access Token was received... store it for the Snippet Account
											$query = $ease_core->db->prepare("	UPDATE accounts
																				SET gapp_access_token_json=:gapp_access_token_json,
																					gapp_access_token=:gapp_access_token,
																					gapp_expire_time=:gapp_expire_time,
																					gapp_refresh_token=:gapp_refresh_token
																				WHERE uuid=:uuid;	");
											$params = array(
												':gapp_access_token_json'=>$ease_core->config['gapp_access_token_json'],
												':gapp_access_token'=>$ease_core->config['gapp_access_token'],
												':gapp_expire_time'=>$ease_core->config['gapp_expire_time'],
												':gapp_refresh_token'=>$ease_core->config['gapp_refresh_token'],
												':uuid'=>$account['uuid']
											);
											$query->execute($params);		
										}
										// configure the EASE Framework for Snippet processing
										// store Snippet Account info in a global bucket that can be referenced by the Snippet
										foreach($account as $key=>$value) {
											$ease_core->globals["cloudward_snippet_account.$key"] = $value;
											$ease_core->globals["ease_processor_account.$key"] = &$ease_core->globals["cloudward_snippet_account.$key"];
										}
										// set a EASE Framework Namespace using the Snippet Account ID
										// the Namespace is used in the EASE Framework to prefix all memcache keys and database tables while processing
										$ease_core->namespace = $account['uuid'];
										$ease_core->php_disabled = true;
										if((!isset($account['enable_db'])) || !in_array(strtolower(trim($account['enable_db'])), array('true', 't', 'yes', 'y'))) {
											$ease_core->db_disabled = true;
										}
										$ease_core->include_disabled = true;
										$ease_core->inject_config_disabled = true;
										$ease_core->catch_redirect = true;
										// process the EASE Snippet
										$response = $ease_core->process_ease($snippet['ease_snippet'], true);

										// log the Snippet Hit
										$query = $ease_core->db->prepare("	INSERT INTO snippet_hit_log
																				(uuid, snippet_id)
																			VALUES
																				(:uuid, :snippet_id);	");
										$params = array(':uuid'=>$ease_core->new_uuid(), ':snippet_id'=>$_REQUEST['cloudward_snippet_id']);
										if(!$query->execute($params)) {
											// there was an error logging the Snippet Hit... attempt to create the log table
											$sql = "CREATE TABLE snippet_hit_log (
														instance_id int NOT NULL PRIMARY KEY auto_increment,
														created_on timestamp NOT NULL default CURRENT_TIMESTAMP,
														updated_on timestamp NOT NULL,
														uuid varchar(32) NOT NULL UNIQUE,
														snippet_id varchar(32) NOT NULL
													);	";
											$ease_core->db->exec($sql);
											// attempt to log the Snippet Hit again using the original query
											if(!$query->execute($params)) {
												// error again
												// TODO! check for missing columns and automatically alter the log table to add them
											}
										}
						
										// update the Hit Count for the Snippet
										$query = $ease_core->db->prepare("UPDATE snippets SET hits=hits+1 WHERE uuid=:uuid;");
										$query->execute(array(':uuid'=>$_REQUEST['cloudward_snippet_id']));
						
										// if any EASE Forms were used, store the form info in the DB
										// this allows the EASE Form Handler to reference it if the session cookie was not relayed
										if(isset($_SESSION['ease_forms']) && count($_SESSION['ease_forms']) > 0) {
											foreach($_SESSION['ease_forms'] as $form_id=>$form_info) {
												$query = $ease_core->db->prepare("	REPLACE INTO ease_forms
																						(form_id, base64_serialized_form_info)
																					VALUES
																						(:form_id, :base64_serialized_form_info);	");
												$params = array(
													':form_id'=>$form_id,
													':base64_serialized_form_info'=>base64_encode(serialize($form_info))
												);
												$result = $query->execute($params);
												if(!$result) {
													// the query failed... attempt to create the ease_forms table, then try again
													$ease_core->db->exec("	CREATE TABLE ease_forms (
																				form_id VARCHAR(64) NOT NULL PRIMARY KEY,
																				base64_serialized_form_info TEXT NOT NULL DEFAULT ''
																			);	");
													$result = $query->execute($params);
													if(!$result) {
														// error... couldn't cache form info... unless session IDs are relayed by the snippets, forms will fail
													}
												}
											}
										}
										// done processing the Snippet... respond with javascript to update the response element with the generated output
										header('Content-type: application/javascript');
										if(isset($account['snippet_header']) && (trim($account['snippet_header'])!='')) {
											$response = $account['snippet_header'] . $response;
										}
										if(isset($account['snippet_footer']) && (trim($account['snippet_footer'])!='')) {
											$response .= $account['snippet_footer'];
										}
										echo 'document.getElementById(ease_processor_response_element_id).innerHTML = ' . json_encode($response) . ";\n";
										// explicitly evaluate any script tags in the response so they will run in the context of the original domain
										echo "var ease_processor_response_script_elements = document.getElementById(ease_processor_response_element_id).getElementsByTagName('script');\n";
										echo "for(var i=0; i<ease_processor_response_script_elements.length; i++) {\n";
										echo "	eval(ease_processor_response_script_elements[i].innerHTML);\n";
										echo "}\n";
										// check for any caught redirects
										if(isset($ease_core->redirect) && trim($ease_core->redirect)!='') {
											// a redirect URL was caught, inject javascript to execute the redirect by setting a new value for window.location
											echo 'window.location = ' . json_encode($ease_core->redirect) . ";\n";
										}
									} else {
										// Snippet Quota Exceeded
										echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Quota Reached';\n";
									}
								} else {
									// invalid referring domain
									echo 'document.getElementById(ease_processor_response_element_id).innerHTML = ';
									echo json_encode('Invalid Domain (' . $referring_host . ').   Snippets for this Account limited to ' . $account['domain']);
									echo ";\n";
								}
							} else {
								// Snippet Account Deactivated
								echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Account Deactivated';\n";
							}
						} else {
							// invalid Account ID
							echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Invalid Snippet Account ID';\n";
						}
					} else {
						// invalid DB query
						echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Processor Error!';\n";
					}
				} else {
					// Snippet Deactivated
					echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Deactivated';\n";
				}
			} else {
				// invalid Snippet ID
				echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Invalid Snippet ID';\n";
			}
		} else {
			// invalid DB query
			echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Processor Error!';\n";
		}
	} else {
		// No Snippet ID provided
		echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Invalid Snippet ID';\n";
	}
} else {
	// No Database
	echo "document.getElementById(ease_processor_response_element_id).innerHTML = 'Snippet Processor Error!';\n";
}
