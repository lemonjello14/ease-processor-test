<?php

/**
 * Retrieves user information and sets info in the session variable
 * 
 * @author  Lucas Simmons
 * 
*/
session_start();
if($_POST['state_code'] != $_SESSION['state']){
    echo "Authentication error";
    exit;
}

require_once('ease/core.class.php');
$ease_core = new ease_core();

$auth_code = $_POST['authResult'];

include_once getcwd() . '/ease/lib/Google/Client.php';
include_once getcwd() . '/ease/lib/Google/Service/Plus.php';
require_once getcwd() . '/ease/lib/Google/Http/Request.php';

$try_count = 0;
$login = false;

while(!$login && $try_count <= 5){ 
 try{
    $client = new Google_Client();
    $client->setClientId($ease_core->load_system_config_var('gapp_client_id'));
    $client->setClientSecret($ease_core->load_system_config_var('gapp_client_secret'));
    $client->setRedirectUri('postmessage');
    
    $client->authenticate($auth_code);
    $token = json_decode($client->getAccessToken());
    
    $plusService = new Google_Service_Plus($client);
    $people = $plusService->people->get("me");
    $people_emails = $people->getEmails();
    $people_emails = $people_emails[0];
    $email_address = $people_emails->getValue();
    
    if(!$people_emails->getValue()){
        echo "Authentication error";
        exit;
    }
    
    $_SESSION['user_email'] = $email_address;
    $_SESSION['user_first_name'] = $people->getName()->givenName;
    $_SESSION['user_last_name'] = $people->getName()->familyName;
    $_SESSION['access_token'] = $token->access_token;
    
    
    if($people->getIsPlusUser()){
        $_SESSION['is_plus_user'] = true;
    }else{
        $_SESSION['is_plus_user'] = false;
    }
    
    $login = true;
 }catch (Google_ServiceException $e) {
    $login = false;
 } catch (Google_IOException $e) {
  $login = false;
 }catch (Google_Exception $e) {
  $login = false;
 }catch (Exception $e) {
  $login = false;
 }
 
 if($login){
  $login = true;
  $try_count = 10;
 }
}

if(!$login && $try_count > 5){
  echo "We could not log you in, we had issues connecting with Google.  Please reload and try again.";
  exit;
}

$_SESSION['ease_memberships.authenticated_users'] = 'unlocked';
$_SESSION['authenticated_user'] = $email_address;

if(strpos($email_address,"@cloudward.com")){
    $_SESSION['ease_memberships.admins'] = 'unlocked';
}

$ease_core->process_ease('<# start list for users;
		include when email_address is "<#[session.user_email]#>";
	#>
<# start row #>
    <# update record for "users.<# users.id #>";
                set first_name to "<#[session.user_first_name]#>";
                set last_name to "<#[session.user_last_name]#>";
                set session.user_id to "<# uuid #>";
    #>
<# end row #>

<# no results #>
    <# create new record for "users";
            set email_address to "<#[session.user_email]#>";
            set first_name to "<#[session.user_first_name]#>";
            set last_name to "<#[session.user_last_name]#>";
            set session.user_id to "<# uuid #>";
    #>
<# end no results #>
<# end list #>

<# start list for users;
		include when email_address is "<#[session.user_email]#>";
	#>
<# start row #>
    <# set session.user_id to "<# uuid #>"; #>
<# end row #>
<# no results #>
<# end no results #>
<# end list #>');

/**
 * Calculates how long to wait if there is a failure, based on the google api doc
 * 
 * @author  Lucas Simmons 
 *
 * @param integer    $try_count  Number of times this action has been tried
 * @return integer How long to wait
*/
function calc_wait($try_count){  
  if($try_count > 0) {
    return (2 ^ ($try_count)) + (mt_rand(1, 1000) / 1000);
  }else{
    return 1;
  }
}
?>


