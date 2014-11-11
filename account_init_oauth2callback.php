<?php
/*
* Copyright 2014 Cloudward, Inc.
*/

/**
 * this script handles Google Drive API oAuth 2.0 callbacks during setup of Access Tokens for EASE Processor Accounts
 *
 * @author Mike <mike@cloudward.com>
 */

// initialize the EASE Framework
require_once('ease/core.class.php');
$ease_core = new ease_core();

// configure the EASE Framework to use the Google Drive API Tokens associated with the Snippet Account
$ease_core->service_endpoints['form'] = $ease_core->globals['system.host_url'] . '/form_handler';
$ease_core->service_endpoints['google_oauth2callback'] = '/account_init_oauth2callback';
$ease_core->set_global_system_vars();
$ease_core->load_system_config_var('gapp_client_id');
$ease_core->load_system_config_var('gapp_client_secret');
require_once 'ease/lib/Google/Client.php';
$client = new Google_Client();
$client->setClientId($ease_core->config['gapp_client_id']);
$client->setClientSecret($ease_core->config['gapp_client_secret']);
$client->setRedirectUri((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $ease_core->service_endpoints['google_oauth2callback']);
$client->setScopes('https://spreadsheets.google.com/feeds https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.readonly');
$client->setAccessType('offline');
$client->setApprovalPrompt('force');
require_once 'ease/lib/Google/Cache/Null.php';
$cache = new Google_Cache_Null($client);
$client->setCache($cache);
try {
	$client->authenticate($_GET['code']);
} catch(Google_Auth_Exception $e) {
	if(preg_match('/^(.*?), message: \'(.*)\'$/is', $e->getMessage(), $matches)) {
		echo "<h1>$matches[1]</h1><br /><br />\n";
		$message_key_value_pairs = json_decode($matches[2]);
		if(is_array($message_key_value_pairs) && count($message_key_value_pairs) > 0) {
			foreach($message_key_value_pairs as $key=>$value) {
				echo "<b>$key</b>: $value<br />\n";
				if($key=='error') {
					if($value=='invalid_client') {
						// the client is invalid, so wipe the settings in the memcache and DB... revert to settings in easeConfig.json
						if($ease_core->memcache) {
							$ease_core->memcache->delete('system.gapp_client_id');
							$ease_core->memcache->delete('system.gapp_client_secret');
						}
						if($ease_core->db) {
							$query = $ease_core->db->prepare("DELETE FROM ease_config WHERE name=:name;");
							$query->execute(array(':name'=>'gapp_client_id'));
							$query->execute(array(':name'=>'gapp_client_secret'));
						}
					} elseif($value=='unauthorized_client' || $value=='invalid_grant') {
						// the stored access tokens were not created for this client, so wipe them as a new client has been configured
						// the user will be prompted to grant permissions again to link this app to a google drive account
						$ease_core->flush_google_access_tokens();
					}
				}
			}
		}
	} else {
		echo $e->getMessage();
	}
	exit;
}
$gapp_access_token_json = $client->getAccessToken();
$access_token = json_decode($gapp_access_token_json);
if($access_token==null) {
	echo 'Invalid Access Token';
	exit;
}
$gapp_access_token = $access_token->access_token;
$gapp_expire_time = $_SERVER['REQUEST_TIME'] + $access_token->expires_in;
$gapp_refresh_token = $access_token->refresh_token;
$state = urldecode($_REQUEST['state']);
$state_parts = parse_url($state);
parse_str($state_parts['query'], $query_parts);
if($ease_core->db) {
	$query = $ease_core->db->prepare("	UPDATE accounts
										SET gapp_access_token_json=:gapp_access_token_json,
											gapp_access_token=:gapp_access_token,
											gapp_expire_time=:gapp_expire_time,
											gapp_refresh_token=:gapp_refresh_token
										WHERE uuid=:uuid;	");
	$params = array(
		':gapp_access_token_json'=>$gapp_access_token_json,
		':gapp_access_token'=>$gapp_access_token,
		':gapp_expire_time'=>$gapp_expire_time,
		':gapp_refresh_token'=>$gapp_refresh_token,
		':uuid'=>$query_parts['id']
	);
	$query->execute($params);					
} else {
	echo 'ERROR!  No Database';
	exit;
}

// redirect back to the page that initiated the oAuth 2.0 process
if(isset($state) && trim($state)!='') {
	header("Location: $state");
} else {
	// a landing page wasn't set... default to the homepage
	header('Location: /');
}
