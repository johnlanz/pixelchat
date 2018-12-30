<?php
require_once('./vendor/autoload.php');

$dotenv = new \Symfony\Component\Dotenv\Dotenv();
$dotenv->load(__DIR__.'/config/.env');

$ws = new \App\WebsocketServer();