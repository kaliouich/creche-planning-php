<?php
$_SERVER['REQUEST_URI'] = '/index.php/auth/login';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:5173';
ob_start();
require 'index.php';
$output = ob_get_clean();
echo "STATUS CODE: " . http_response_code() . "\n";
echo "OUTPUT: " . $output . "\n";
