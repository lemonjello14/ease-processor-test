<?php
session_start();
$user_id = $_SESSION["user_id"];
$_SESSION = array();
session_destroy();
?><!doctype html>

<html itemscope itemtype="http://schema.org/Article">
<head>
	<meta name="google-site-verification" content="ZrVPowzuAEBsRQvLX9MsTRkP6fIeVYB7TJMLjMdl5As" />
	<title>EASE Processor</title>
	<!-- BEGIN Pre-requisites -->
	<script src="//ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js">
	</script>
	<script src="https://apis.google.com/js/client:platform.js?onload=start" async defer>
	</script>
	<!-- END Pre-requisites -->
	<script src="javascripts/bootstrap.js"></script>
       
        <link href="stylesheets/bootstrap.css" rel="stylesheet">
        <link href="stylesheets/font-awesome.css" rel="stylesheet">
        <link href="stylesheets/style.css" rel="stylesheet">

</head>
<body>

<!--<a href='/' style='text-decoration:none; font-size:15pt; font-family:Lato, Helvetica, Arial, sans-serif;'>EASE Processor</a>-->
<div class="" id="top_container">
    
        <div id="header_container" style="background-color:white;width:100%">
            <header class="header wrapper-global clearfix" style="background-color:white;">
		<div class="container">
			<!-- logo -->
			
			<a class="logo" href="http://www.cloudward.com/"><img style="padding-top:12px" src="images/cloudward_logo.png" alt="Cloudward"></a>
			<!-- navUser -->
		</div>


	<div class="navUser clearfix">
		<span class="pull-right">
		    <nav>
			    <ul>
				    <li class="navUser-register"><a href="http://www.cloudward.com">Cloudward</a></li>
				    <li class="navUser-register" ><a href="http://www.cloudward.com/support">Support</a></li>
			    </ul>
		    </nav>
		    
		</span>
            
		<!-- navMain -->
		<div class="navMain clearfix">
			<nav>
				<ul>
					<li><a href='/'>Home</a></li>
					<li><a href='/accounts'>Accounts</a></li>
					<li><a href='/snippets'>Snippets</a></li>
					<?php if($_SESSION['ease_memberships.admins'] == 'unlocked'){ 
						echo "<li><a href='/admin'>Admin Console</a></li>";
					 } ?>
					<?php if($_SESSION['authenticated_user']){ 
						echo "<li><a href='/logout.php'>Logout</a></li>";
					} ?>
				</ul>
			</nav>
		</div>
	</div>
</div>
<div id="main_container" class="container" style="min-height: 600px;height: auto !important;">
<?php
//?referral=<?php echo $referral['referral'];&token=<?php echo $referral['referral_uuid'];

?>
<script type="text/javascript">
    function signinCallbackLogout(authResult) {
        gapi.auth.signOut();
        window.location.href = "/";
    }

</script>
    
                Logging you out...
                </div>

<div style="width:100%;overflow: hidden;padding:30px;font-family: Lato, Helvetica, Arial, sans-serif;font-size:15px;">
	<footer class="footer center" id="footer"  style="display:block">
		<div class="navFooter wrapper-global" style="">
				<nav>
					<ul  style="padding-left:0px">
						<li><a href="http://www.cloudward.com/support">Support</a></li>
						<li><a href="http://www.cloudward.com/?page=webarticle&aid=031d30538bf1bd317b7e3bda0c590391">Privacy Policy</a></li>
						<li><a href="http://www.cloudward.com/?page=webarticle&aid=05d8320470f98b9c751adeaa7e3e2aa9">Terms of Service</a></li>
						<!--li><a href="#">Investors</a></li-->					
					</ul>
				</nav>
	
			<p class="copyrights">Copyright &copy;<?php echo date("Y"); ?> Cloudward, Inc. All Rights Reserved</p>
	
		</div>
	</footer>
</div>
<?php
require_once('ease/core.class.php');
$ease_core = new ease_core();
 ?>
<div style="display:none">
    <span id="signinButton" style="display:none">
        <span
          class="g-signin"
          data-callback="signinCallbackLogout"
          data-clientid="<?php echo $ease_core->load_system_config_var('gapp_client_id'); ?>"
          data-cookiepolicy="single_host_origin"
          data-requestvisibleactions=""
          data-scope="https://www.googleapis.com/auth/plus.login https://www.googleapis.com/auth/userinfo.email">
        </span>
      </span>
    
     <!-- Place this asynchronous JavaScript just before your </body> tag -->
    <script type="text/javascript">
      (function() {
       var po = document.createElement('script'); po.type = 'text/javascript'; po.async = true;
       po.src = 'https://apis.google.com/js/client:plusone.js';
       var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(po, s);
     })();
    </script>
    
        