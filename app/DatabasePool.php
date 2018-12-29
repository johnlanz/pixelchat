<?php
namespace App;


use \Swoole\Http\Request;
use \Swoole\Http\Response;

/**
 * Class DatabasePool
 *
 * Deal with the fact that Swoole 2.1.3 has no build in database pooling
 */
class DatabasePool
{
    private $server = [
        'host' => 'localhost',
        'user' => 'admin',
        'password' => 'M123chael',
        'database' => 'twitch'
    ];
    private $pool;
    private $pool_count = 0;
    function __construct()
    {
        $this->pool = new \SplQueue;
    }
    function set_host_ip()
    {
        echo 'server host: '. $this->server['host'] . "\n";
        if (empty($this->server['host'])) {
            $tfb_database_ip = \Swoole\Coroutine::gethostbyname('twitch');
            $this->server['host'] = $tfb_database_ip;
        }
    }
    function put($db)
    {
        $this->pool->enqueue($db);
        $this->pool_count++;
    }
    function get(string $server_type)
    {
        if ($this->pool_count > 0) {
            $this->pool_count--;
            return $this->pool->dequeue();
        }
        
        // No idle connection, time to create a new connection
        if ($server_type === 'mysql') {
            $db = new \Swoole\Coroutine\Mysql;
        }
        if ($server_type === 'postgres') {
            $db = new \Swoole\Coroutine\PostgreSql;
        }
        $db->connect($this->server);
        if ($db == false) {
            return false;
        }
        return $db;
    }
}