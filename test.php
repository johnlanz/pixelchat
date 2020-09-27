<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$server = new Swoole\HTTP\Server("127.0.0.1", 9502);

$server->on("start", function (Server $server) {
    echo "Swoole http server is started at http://127.0.0.1:9502\n";
});

$server->on("request", function (Request $request, Response $response) {
    $response->header("Content-Type", "text/plain");
    $response->end("Hello World\n");
});

$server->start();