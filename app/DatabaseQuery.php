<?php

namespace App;

class DatabaseQuery
{
	private $result;

	public function getAll($table = null)
	{
		if (empty($table)) {
			return [];
		}
		$db = new \swoole_mysql;
        $server = array(
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'admin',
            'password' => 'M123chael',
            'database' => 'twitch',
            'charset' => 'utf8',
            'timeout' => 2,
        );

        $db->connect($server, function ($db, $r) use ($table) {
            if ($r === false) {
                var_dump($db->connect_errno, $db->connect_error);
                die;
            }
            $sql = "select * from {$table};";
            echo $sql. "\n";
            $db->query($sql, function(\swoole_mysql $db, $r) {
                if ($r === false)
                {
                    print_r($db->error, $db->errno);
                }
                elseif ($r === true )
                {
                    print_r($db->affected_rows, $db->insert_id);
                }
                $this->result = $r;
                $db->close();

                return $this->result;
            });
        });
        
	}
}