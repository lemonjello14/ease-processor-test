<?php
if(count(get_included_files()) ==1) exit("Direct access not permitted.");
if($_SERVER["SERVER_ADDR"] == "::1" || $_SERVER["SERVER_ADDR"] == "127.0.0.1" || !$_SERVER["SERVER_ADDR"]){
    $client_id = "70399099102-un9fuod0711t83f31arlh10m6ncg7fi1.apps.googleusercontent.com";
    $client_secret = "a8wwfXnxTy74ymCOkmD3tPAt";
}else{
    $client_id = "378156221000-hs7r97h0da09t072otc7mfjagk814rgc.apps.googleusercontent.com";
    $client_secret = "lnr4EUbmKa0nX6RkaKjMGkZ8";
}
?>