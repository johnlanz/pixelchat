<?php
namespace App;

use sethink\swooleOrm\Db;
use sethink\swooleOrm\MysqlPool;

class WebsocketServer
{
    const PING_DELAY_MS = 25000;
	private $ws;
    private $pool;
    protected $roomName = "defaultRoom";
    protected $roomListName = "RoomList";
    private $workerFirst = true;

	public function __construct()
    {
        $this->ws = new \swoole_websocket_server("127.0.0.1", 9501);

        $this->ws->on('open', function (\swoole_websocket_server $ws, $request) {
            echo "server: handshake success with fd: {$request->fd}\n";
            //$this->onConnection($request);
        });
        $this->ws->on('message', function (\swoole_websocket_server $ws, $frame) {
            $this->onMessage($ws, $frame);
        });
        $this->ws->on('close', function (\swoole_websocket_server $ws, $fd) {
            $this->logout($ws, $fd);
            $ws->close($fd);
            echo "client {$fd} closed\n";
        });

        /**
         * On start of the PHP worker. One worker per server process is started.
         */
        $this->ws->on('workerStart', function () {
            $this->onWorkerStart($this->ws);
        });

        $this->ws->start();
    }

    private function onWorkerStart(\swoole_websocket_server $ws) {
        echo "start worker\n";
        /*$ws->tick(self::PING_DELAY_MS, function () use ($ws) {
            foreach ($ws->connections as $id) {
                $ws->push($id, 'ping', WEBSOCKET_OPCODE_PING);
            }
        }); */

        $config    = [
            'host'      => 'localhost',
            'port'      => 3306,
            'user'      => 'admin',
            'password'  => 'M123chael',
            'charset'   => 'utf8',
            'database'  => 'twitch',
            'poolMin'   => 5,
            'clearTime' => 60000,
            'clearAll'  => 300000,
        ];
        $this->MysqlPool = new MysqlPool($config);
        unset($config);
        $this->MysqlPool->clearTimer($ws);

        //delete all data from chatrooms
        $sql = 'TRUNCATE TABLE chatrooms;';
        Db::init($this->MysqlPool)->query($sql);
        $sql = 'ALTER TABLE chatrooms AUTO_INCREMENT = 1;';
        Db::init($this->MysqlPool)->query($sql);
    }

    protected function onMessage(\swoole_websocket_server $ws, $frame)
    {
        if (!empty($frame) && $frame->opcode == 1 && $frame->finish == 1) {
            $message = $this->checkMessage($frame->data);
            if (!$message) {
                //unallowed message
                //$this->serverPush($ws, $frame->fd, $frame->data);
            }
            if (isset($message["type"])) {
                switch ($message["type"]) {
                    case "login":
                        $this->login($ws, $frame->fd, $message);
                        break;
                    case "message":
                        $this->serverPush($ws, $frame->fd, $message);
                        break;
                    default:
                }
            }
        } else {
            throw new Exception("Received data is incomplete");
        }
        echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
    }

    protected function serverPush(\swoole_websocket_server $ws, $fd, $message = [])
    {
        if (empty($message['username'])) {
            //not allowed to send message
            return;
        }
        $roomUsers = $this->getAllUsersInRoom($message['room']);
        if (!empty($roomUsers)) {
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
            $saved = $this->saveMessage($message);
        }
    }

    protected function getAllUsersInRoom($room)
    {
        return Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['room'=> $room])
            ->select();
    }

    protected function checkMessage($message)
    {
        $message = json_decode($message);
        $return_message = [];
        if (!is_array($message) && !is_object($message)) {
            $this->error = "The received message data format is incorrect";
            return false;
        }
        if (is_object($message)) {
            foreach ($message as $item => $value) {
                $return_message[$item] = $value;
            }
        } else {
            $return_message = $message;
        }
        if (!isset($return_message["type"]) || !isset($return_message["message"])) {
            return false;
        } else {
            if (!isset($return_message["room"])) {
                $return_message["room"] = $this->roomName;
            }
            $return_message["created"] = date('Y-m-d H:i:s', time());
            $return_message["message"] = htmlspecialchars($return_message['message']);
            return $return_message;
        }
    }

    protected function saveMessage($message)
    {
        unset($message['type']);
        $message['modified'] = $message['created'];
        return Db::init($this->MysqlPool)
            ->name('chats')
            ->insert($message);
    }

    protected function login(\swoole_websocket_server $ws, $fd, $message = [])
    {
        if (empty($message['username'])) {
            $message['username'] = 'guest' . $fd;
        }
        $userInfo["fd"] = $fd;
        $userInfo["room"] = $message['room'];
        $userInfo["username"] = $message['username'];

        $this->createUpdateRoomUserList($userInfo);
        $this->pushAllMessagesToUser($ws, $userInfo);
    }

    protected function logout(\swoole_websocket_server $ws, $fd)
    {
        echo "Logout/Delete FD: {$fd}\n";
        Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $fd])
            ->delete();
    }

    protected function createUpdateRoomUserList($userInfo = [])
    {
        $chatRoom = Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $userInfo['fd']])
            ->find();
        if (empty($chatRoom)) {
            //create chatroom
            Db::init($this->MysqlPool)
                ->name('chatrooms')
                ->insert($userInfo);
        } else {
            //update chatroom
            Db::init($this->MysqlPool)
                ->name('chatrooms')
                ->where(['fd'=> $userInfo['fd']])
                ->update($userInfo);
        }
    }

    protected function pushAllMessagesToUser(\swoole_websocket_server $ws, $userInfo = [])
    {
        $chats = Db::init($this->MysqlPool)
            ->name('chats')
            ->where(['room'=> $userInfo['room']])
            ->limit(20)
            ->select();

        foreach ($chats as $chat) {
            $ws->push($userInfo['fd'], json_encode($chat));
        }
    }

    protected function allUserByRoom($room)
    {
        $user_list = $this->redis->hget("{$this->userList}_{$room}");
        $users = [];
        if (!empty($user_list)) {
            foreach ($user_list as $fd) {
                $user = $this->table->get($fd);
                if (!empty($user)) {
                    $users[] = $user;
                }
            }
        }
        return $users;
    }
}