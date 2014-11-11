<?php

// this script handles requests for EASE Web Apps

// load the EASE Framework
require_once('ease/core.class.php');
$ease_core = new ease_core();

// handle the EASE Web App request
$ease_core->handle_request();
