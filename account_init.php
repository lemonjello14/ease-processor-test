<?php
/*
* Copyright 2014 Cloudward, Inc.
*/

/**
 * this script initializes Google Drive Access Tokens for EASE Processor Accounts
 *
 * @author Mike <mike@cloudward.com>
 */

// initialize the EASE Framework
require_once('ease/core.class.php');
$ease_core = new ease_core();

$ease_header = file_get_contents('header.espx');
$ease_core->process_ease($ease_header);
echo "	<div class='container container_body'>
		<div class='panel panel-default col-md-6 col-md-offset-3'>
			<div class='panel-body text-center'>";
			
$drive_setup_text = "<h3 style='margin-bottom:15px;'>Google Drive Access</h3>
				You will need to grant permissions in order to use 
				Google Drive Services with Cloudward Snippets.<br />
				Click on the next button to redirect to Google to continue.<br /><br /> ";
$ease_core->set_system_config_var('google_token_message', $drive_setup_text);

$ease_core->service_endpoints['google_oauth2callback'] = '/account_init_oauth2callback';
if($ease_core->db) {
	$query = $ease_core->db->prepare('SELECT * FROM accounts WHERE uuid=:uuid;');
	if($query->execute(array(':uuid'=>$_REQUEST['id']))) {
		if($account = $query->fetch(PDO::FETCH_ASSOC)) {
			$ease_core->set_system_config_var('gapp_access_token_json', $account['gapp_access_token_json']);
			$ease_core->set_system_config_var('gapp_access_token', $account['gapp_access_token']);
			$ease_core->set_system_config_var('gapp_expire_time', $account['gapp_expire_time']);
			$ease_core->set_system_config_var('gapp_refresh_token', $account['gapp_refresh_token']);
			$ease_core->validate_google_access_token(true);
			
			
			if((trim($ease_core->config['gapp_access_token_json'])!='') && ($ease_core->config['gapp_access_token_json']!=$account['gapp_access_token_json'])) {
				// the validated access token is either new or has been recently refreshed... store the new token for the account
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
				echo '			<h3>Things are going great!</h3>
							A Google Apps Access Token has been initialized for the Account.<br /><br />

							<a href="/account/new_snippet" class="btn btn-primary">Next <i class="fa fa-angle-double-right"></i></a> ';
				
			} elseif(trim($ease_core->config['gapp_access_token_json'])=='') {
				echo 'ERROR!  A Google Apps Access Token was not received';
				echo '<br /><br /><a href="' . htmlspecialchars($_REQUEST['state']) . '">Back to ' . htmlspecialchars($_REQUEST['state']) . '</a>';
			} else {
				echo '		<h3>Things are going great!</h3>
							A Google Apps Access Token has been initialized for the Account.<br /><br />

							<a href="/account/new_snippet" class="btn btn-primary">Next <i class="fa fa-angle-double-right"></i></a> ';
			}
		} else {
			echo 'ERROR!  Invalid Account ID';
			echo '<br /><br /><a href="' . htmlspecialchars($_REQUEST['state']) . '">Back to ' . htmlspecialchars($_REQUEST['state']) . '</a>';
		}
	} else {
		echo 'ERROR!  Invalid Database Query';
		echo '<br /><br /><a href="' . htmlspecialchars($_REQUEST['state']) . '">Back to ' . htmlspecialchars($_REQUEST['state']) . '</a>';
	}
} else {
	echo 'ERROR!  No Database';
	echo '<br /><br /><a href="' . htmlspecialchars($_REQUEST['state']) . '">Back to ' . htmlspecialchars($_REQUEST['state']) . '</a>';
}

if(isset($_REQUEST['state']) && trim($_REQUEST['state'])!='') {
	
}

echo "			</div>
		</div>
	</div>";
?>