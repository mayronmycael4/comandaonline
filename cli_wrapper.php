<?php
error_reporting(0);
$query = $argv[1] ?? "";
parse_str($query, $_GET);
$_POST = $_GET;
$_SERVER["REQUEST_METHOD"] = ($argv[2] ?? "GET");
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_SERVER["HTTP_HOST"] = "localhost";
include $argv[3];
?>