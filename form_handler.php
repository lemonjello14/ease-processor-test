<?php
/*
* Copyright 2014 Cloudward, Inc.
*/

/**
 * this script handles EASE Form posts for the EASE Processor Service
 *
 * @author Mike <mike@cloudward.com>
 */

// load the EASE Framework
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
if((!isset($_REQUEST['cloudward_snippet_id'])) && isset($_SERVER['QUERY_STRING']) && trim($_SERVER['QUERY_STRING'])!='') {
	// a Snippet ID was not found as a GET or POST HTTP param... treat the entire URL query string as the Snippet ID
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
	// a Session ID value was found... apply it
	session_id($_REQUEST['cloudward_snippet_session_id']);
}

// initialize the session, loading current session data into the global $_SESSION variable
session_start();

// check for a database
if($ease_core->db) {
	// a database was found, check for a requested Snippet ID
	if(isset($_REQUEST['cloudward_snippet_id']) && trim($_REQUEST['cloudward_snippet_id'])!='') {
		// Snippet ID provided, query for Snippet info
		$query = $ease_core->db->prepare('SELECT * FROM snippets WHERE uuid=:uuid;');
		if($query->execute(array(':uuid'=>$_REQUEST['cloudward_snippet_id']))) {
			// query successful, fetch the Snippet info
			if($snippet = $query->fetch(PDO::FETCH_ASSOC)) {
				// Snippet info fetched... query for Snippet Account info
				$query = $ease_core->db->prepare('SELECT * FROM accounts WHERE uuid=:uuid;');
				if($query->execute(array(':uuid'=>$snippet['account_id']))) {
					// query successful, fetch the Snippet Account info
					if($account = $query->fetch(PDO::FETCH_ASSOC)) {
						// Snippet Account info fetched... check for a requested EASE Form ID
						if(isset($_REQUEST['ease_form_id']) && trim($_REQUEST['ease_form_id'])!='') {
							// EASE Form ID provided, query for EASE Form info
							$query = $ease_core->db->prepare('SELECT base64_serialized_form_info FROM ease_forms WHERE form_id=:form_id;');
							if($query->execute(array(':form_id'=>$_REQUEST['ease_form_id']))) {
								// query successful, fetch the EASE Form info
								if($ease_form = $query->fetch(PDO::FETCH_ASSOC)) {
									// EASE Form info fetched, store it in the current session where the EASE Framework Form Handler will look for it
									$_SESSION['ease_forms'][$_REQUEST['ease_form_id']] = unserialize(base64_decode($ease_form['base64_serialized_form_info']));
									// configure the EASE Framework to use the Google Drive Access Token associated with the Account
									$ease_core->set_global_system_vars();
									$ease_core->service_endpoints['form'] = $ease_core->globals['system.secure_host_url'] . '/form_handler?cloudward_snippet_id=' . urlencode($_REQUEST['cloudward_snippet_id']);
									$ease_core->service_endpoints['oauth2callback'] = '/account_init_oauth2callback';
									$ease_core->set_system_config_var('gapp_access_token_json', $account['gapp_access_token_json']);
									$ease_core->set_system_config_var('gapp_access_token', $account['gapp_access_token']);
									$ease_core->set_system_config_var('gapp_expire_time', $account['gapp_expire_time']);
									$ease_core->set_system_config_var('gapp_refresh_token', $account['gapp_refresh_token']);
									$ease_core->validate_google_access_token();
									if((trim($ease_core->config['gapp_access_token_json'])!='') && ($ease_core->config['gapp_access_token_json']!=$account['gapp_access_token_json'])) {
										// a new or refreshed Google Drive Access Token was received... store it for the Account
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
									// Google Drive Access Token for the Account was validated and configured, process the received form data
									$ease_core->namespace = $account['uuid'];
									$ease_core->php_disabled = true;
									if((!isset($account['enable_db'])) || !in_array(strtolower(trim($account['enable_db'])), array('true', 't', 'yes', 'y'))) {
										$ease_core->db_disabled = true;
									}
									$ease_core->include_disabled = true;
									$ease_core->inject_config_disabled = true;
									$ease_core->handle_form();
								} else {
									echo 'ERROR!  Invalid EASE Form ID';
								}
							} else {
								echo 'ERROR!  Invalid Database Query';
							}
						} else {
							echo 'ERROR!  An EASE Form ID was not provided';
						}
					} else {
						echo 'ERROR!  Invalid Snippet Account ID';
					}
				} else {
					echo 'ERROR!  Invalid Database Query';
				}
			} else {
				echo 'ERROR!  Invalid Snippet ID';
			}
		} else {
			echo 'ERROR!  Invalid Database Query';
		}
	} else {
		echo 'ERROR!  Invalid Snippet ID';
	}
} else {
	echo 'ERROR!  No Database';
}
