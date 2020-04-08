<?php
namespace App;

use sethink\swooleOrm\Db;
use sethink\swooleOrm\MysqlPool;
use Carbon\Carbon;

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
        echo "host: " . getenv('WS_HOST') . "\n";
        echo "port: " . getenv('WS_PORT') . "\n";
        $this->ws = new \swoole_websocket_server(getenv('WS_HOST'), getenv('WS_PORT'));
        /*$this->ws->set([
            'heartbeat_idle_time' => 1200,
            'heartbeat_check_interval' => 120,
        ]); */
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
            'host'      => getenv('DB_HOST'),
            'port'      => getenv('DB_PORT'),
            'user'      => getenv('DB_USER'),
            'password'  => getenv('DB_PASS'),
            'charset'   => getenv('DB_CHARSET'),
            'database'  => getenv('DB_DATABASE'),
            'poolMin'   => getenv('DB_POOLMIN'),
            'clearTime' => getenv('DB_CLEARTIME'),
            'clearAll'  => getenv('DB_CLEARALL'),
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
                    case "login_video":
                        $this->loginVideo($ws, $frame->fd, $message);
                        break;
                    case "message":
                        $this->serverPush($ws, $frame->fd, $message);
                        break;
                    case "get_history":
                        $this->getHistoryMessages($ws, $frame->fd, $message);
                        break;
                    default:
                }
            }
        } else {
            throw new Exception("Received data is incomplete");
        }
        //echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
    }

    protected function serverPush(\swoole_websocket_server $ws, $fd, $message = [])
    {
        if (!empty($message['ping'])) {
            $ws->push($fd, json_encode($message));
            return;
        }
        if (empty($message['username'])) {
            $message['error_message'] = "Please login to send a message";
            $ws->push($fd, json_encode($message));
            //not allowed to send message
            return;
        }
        if (!empty($message['guest'])) {
            $chatGuest = Db::init($this->MysqlPool)
                ->name('chat_user_guests')
                ->where(['name'=> $message['username']])
                ->find();
            if (!empty($chatGuest)) {
                $chatUser = Db::init($this->MysqlPool)
                    ->name('chat_users')
                    ->where(['id'=> $chatGuest[0]['chat_user_id']])
                    ->find();
                $totalSentMessage = (int)$chatGuest[0]['sent_messages'] + 1;
                $numMessage = (int)$chatUser[0]['number_of_message'];
                if ($totalSentMessage > $numMessage) {
                    $message['error_message'] = "Message limit reached. Please login to send a message";
                    $ws->push($fd, json_encode($message));
                    return;
                }
                Db::init($this->MysqlPool)
                ->name('chat_user_guests')->where(['id' => $chatGuest[0]['id']])
                ->update(['sent_messages' => $totalSentMessage]);
            }
        }
        //check if ban user
        $banUser = Db::init($this->MysqlPool)
            ->name('chat_bans')
            ->field('id,room,ban_username')
            ->where([
                'ban_username' => $message['username'],
                'room' => $message['room']
            ])
            ->find();
        if (!empty($banUser)) {
            $message['error_message'] = "Chat is not available for banned user";
            $ws->push($fd, json_encode($message));
            //not allowed to send message
            return;
        }

        //delete message
        if (!empty($message['delete'])) {
            $roomUsers = $this->getAllUsersInRoom($message['room']);
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
            return;
        }

        $message = $this->checkCommandMessage($message);
        if (!empty($message['command'])) {
            $original = $message['original'];
            unset($message['original']);
            $roomUsers = $this->getAllUsersInRoom($message['room']);
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($original));
            }
            $original = $this->saveMessage($original);
            //return;
        }

        if (!empty($message['update_opinion']) && !empty($message['chat_id'])) {
            $chat = Db::init($this->MysqlPool)
                ->name('chats')
                ->where(['id'=> $message['chat_id']])
                ->find();
            print_r($chat);
            if (!empty($chat)) {
                $message = array_merge($message, $chat[0]);
                print_r($message);
                $roomUsers = $this->getAllUsersInRoom($message['room']);
                foreach ($roomUsers as $roomUsers) {
                    $ws->push($roomUsers['fd'], json_encode($message));
                }
            }
            return;
        }

        if (!empty($message['sendTip'])) {
            //send an info message about spent goo
            $message = $this->cheerMessage($message['points'], $message);
            //print_r("sendTip");
            //print_r($message);
            if (!empty($message['nocheer'])) {
                if (empty($message['error_message'])) {
                    $message['error_message'] = "Not enough goo";
                }
                $ws->push($fd, json_encode($message));
                //not allowed to send message
                return;
            }
            $user = ($message['anonymous'])? "anonymous" : $message['username'];
            if (!empty($message['gooOrderMessage'])) {
                $tipMessage = $user.' just ordered for <strong>'. $message['points'] . ' goo</strong>';
                $tipMessage .= '<br />"<span class="fa fa-star star-checked"></span> <strong><em>' . $message['gooOrderMessage'] . '</em></strong>"';
                $message['message_type'] = "notification_goo_order";
            } else {
                $tipMessage = $user.' sent <strong>'. $message['points'] . ' goo!</strong>';
                $message['message_type'] = "notification_goo_spent";
            }
            if (!empty($message['message'])) {
                $tipMessage .= '<br />"<em>' . $message['message'] . '</em>"';
            }
            $message['message'] = $tipMessage;
            $message = $this->saveMessage($message);
            $roomUsers = $this->getAllUsersInRoom($message['room']);
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
            return;
        }        

        $cheers = Db::init($this->MysqlPool)
            ->name('currency_emojis')
            ->field('id,code,points')
            ->select();
        $points = 0;
        foreach ($cheers as $cheer) {
            $counts = substr_count($message['message'], $cheer['code']);
            if ($counts > 0) {
                $points = $points + ((int)$cheer['points'] * $counts);
            }
        }
        $message = $this->cheerMessage($points, $message);
        //print_r("cheerMessage");
        //print_r($message);
        if (!empty($message['nocheer'])) {
            if (empty($message['error_message'])) {
                $message['error_message'] = "Not enough goo";
            }
            $ws->push($fd, json_encode($message));
            //not allowed to send message
            return;
        }
        
        $roomUsers = $this->getAllUsersInRoom($message['room']);
        $cheerMessage = $message;
        $message = $this->saveMessage($message);
        if (!empty($roomUsers)) {
            if (!empty($message['created'])) {
                $message['created'] = Carbon::createFromFormat('Y-m-d H:i:s', $message['created'])->isoFormat('MMM D, h:mm:ss');
            }

            $message['message'] = $this->coloredUsername($message['message']);
            
            foreach ($roomUsers as $roomUsers) {
                $ban = Db::init($this->MysqlPool)
                    ->name('chat_bans')
                    ->field('id,room,ban_username')
                    ->where([
                        'ban_username' => $roomUsers['username'],
                        'room' => $message['room']
                    ])
                    ->find();
                if (empty($ban)) {
                    $ws->push($roomUsers['fd'], json_encode($message));
                }
            }
        }

        //send cheer notification
        if (!empty($message['sendCheer'])) {
            $cheerMessage['message'] = $cheerMessage['username'].' sent <strong>'. $points . ' goo!</strong>';
            $cheerMessage['message_type'] = "notification_goo_spent";
            $cheerMessage = $this->saveMessage($cheerMessage);
            $roomUsers = $this->getAllUsersInRoom($cheerMessage['room']);
            unset($cheerMessage['updateCoin']);
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($cheerMessage));
            }
        }
    }

    protected function getHistoryMessages(\swoole_websocket_server $ws, $fd, $message = [])
    {
       $chats = Db::init($this->MysqlPool)
            ->name('chats')
            ->where([
                'room'=> $message['room'],
                'id' => ['<', $message['message_id']]
            ])
            ->order(['id'=>['id' => 'desc']])
            ->limit(30)
            ->select();
        print_r($message);
        
        //krsort($chats);
        //print_r($chats);

        foreach ($chats as $chat) {
            if (!empty($chat['created'])) {
                $chat['created'] = Carbon::createFromFormat('Y-m-d H:i:s', $chat['created'])->isoFormat('MMM D, h:mm:ss');
            }
            $chat['type'] = "get_history";
            $chat['message'] = $this->coloredUsername($chat['message']);
            $ban = Db::init($this->MysqlPool)
                ->name('chat_bans')
                ->field('id,ban_username,room')
                ->where([
                    'ban_username' => $chat['username'],
                    'room' => $message['room']
                ])
                ->find();
            if (!empty($ban)) {
                print_r("wtf");
                print_r($ban);
            }
            if (empty($ban)) {
                $ws->push($fd, json_encode($chat));
            }
        }
    }

    protected function coloredUsername($message)
    {
        $message = preg_replace('/(\@([a-zA-Z\'-]+)\w+)/', '<span class="text-info">$1</span>', $message);
        return $message;
    }

    protected function checkCommandMessage($message = [])
    {
        $streamer = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,token,coin')
            ->where(['token' => $message['room']])
            ->find();

        $command = Db::init($this->MysqlPool)
            ->name('chat_commands')
            ->field('id,command,output,user_id')
            ->where([
                'user_id' => $streamer[0]['id'],
                'command' => $message['message']
            ])
            ->find();
        if (!empty($command)) {
            $string = $command[0]['output'];
            $url = '@(http(s)?)?(://)?(([a-zA-Z])([-\w]+\.)+([^\s\.]+[^\s]*)+[^,.\s])@';
            $string = preg_replace($url, '<a href="http$2://$4" target="_blank" title="$0">$0</a>', $string);
            $message['original'] = $message;
            $message['message'] = $string;
            $message['command'] = true;
            $message['message_type'] = 'command';
            //print_r($message);
        }
        return $message;
    }

    protected function cheerMessage($points = 0, $message = [])
    {
        $message['nocheer'] = false;
        
        $streamer = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,token,coin')
            ->where(['token' => $message['room']])
            ->find();
        //print_r($user);
        if ($points > 0) {
            if ($message['guest']) {
                $message['nocheer'] = true;
                $message['error_message'] = "Please login to send goo";
                return $message;
            }
            $sender = Db::init($this->MysqlPool)
                ->name('users')
                ->field('id,username,token,coin')
                ->where(['username' => $message['username']])
                ->find();

            if ($sender[0]['id'] == $streamer[0]['id']) {
                $message['updateCoin'] = (int)$sender[0]['coin'];
                $message['nocheer'] = true;
                return $message;
            }
            //print_r($points ." > " . $sender[0]['coin']);
            if ($points > $sender[0]['coin']) {
                $message['nocheer'] = true;
                return $message;
            }

            $updateCoin = (int)$sender[0]['coin'] - $points;

            Db::init($this->MysqlPool)
                ->name('users')->where(['id' => $sender[0]['id']])
                ->update(['coin' => $updateCoin]);

            //add points to streamer
            $addCoin = (int)$streamer[0]['coin'] + $points;
            Db::init($this->MysqlPool)
                ->name('users')->where(['id' => $streamer[0]['id']])
                ->update(['coin' => $addCoin]);
            
            //user top points
            $userTopPoints = Db::init($this->MysqlPool)
            ->name('user_top_points')
            ->field('id,user_id,streamer_id,points')
            ->where([
                'user_id' => $sender[0]['id'],
                'streamer_id' => $streamer[0]['id']
            ])
            ->find();
            if (empty($userTopPoints[0])) {
                //insert top Points
                $topPoints = [
                    'user_id' => $sender[0]['id'],
                    'streamer_id' => $streamer[0]['id'],
                    'points' => $points,
                    'created' => date("Y-m-d H:i:s"),
                    'modified' => date("Y-m-d H:i:s")
                ];
                Db::init($this->MysqlPool)
                ->name('user_top_points')
                ->insert($topPoints);
            } else {
                $updatePoints = $userTopPoints[0]['points'] + $points;
                //update top points
                Db::init($this->MysqlPool)
                ->name('user_top_points')->where(['id' => $userTopPoints[0]['id']])
                ->update(['points' => $updatePoints]);
            }
            
            //user Points
            $userPoints = [
                'user_id' => $sender[0]['id'],
                'send_to' => $streamer[0]['id'],
                'points' => $points,
                'created' => date("Y-m-d H:i:s"),
                'modified' => date("Y-m-d H:i:s")
            ];
            Db::init($this->MysqlPool)
            ->name('user_points')
            ->insert($userPoints);
            $message['updateCoin'] = $updateCoin;
            $message['sendCheer'] = true;
        }
        return $message;
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
        $message['modified'] = $message['created'];
        $originalMessage = $message;
        unset($message['updateCoin']);
        unset($message['nocheer']);
        unset($message['type']);
        unset($message['points']);
        unset($message['sendTip']);
        unset($message['error_message']);
        unset($message['command']);
        unset($message['sendCheer']);
        unset($message['anonymous']);
        unset($message['gooOrderMessage']);
        unset($message['guest']);
        Db::init($this->MysqlPool)
            ->name('chats')
            ->insert($message);
        $originalMessage['id'] = $message['id'];
        return $originalMessage;
    }

    protected function login(\swoole_websocket_server $ws, $fd, $message = [])
    {
        if (empty($message['username'])) {
            $message['username'] = 'guest' . $fd;
        } else {
            $user = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,token,coin')
            ->where(['username' => $message['username']])
            ->find();
            if (!empty($user)) {
                $userInfo["user_id"] = $user[0]['id'];
            }
        }
        $userInfo["fd"] = $fd;
        $userInfo["room"] = $message['room'];
        $userInfo["username"] = $message['username'];
        $userInfo["video"] = 1;
        $userInfo["chat"] = 1;
        $userInfo["browser_id"] = $message['browser_id'];

        $this->createUpdateRoomUserList($userInfo);
        $this->pushAllMessagesToUser($ws, $userInfo);

        $this->numberOfUsers($ws, $fd, 'add', $userInfo);
    }

    protected function loginVideo(\swoole_websocket_server $ws, $fd, $message = [])
    {
        if (empty($message['username'])) {
            $message['username'] = 'guest' . $fd;
        } else {
            $user = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,token,coin')
            ->where(['username' => $message['username']])
            ->find();
            if (!empty($user)) {
                $userInfo["user_id"] = $user[0]['id'];
            }
        }
        $userInfo["fd"] = $fd;
        $userInfo["room"] = $message['room'];
        $userInfo["username"] = $message['username'];
        $userInfo["video"] = 1;
        $userInfo["chat"] = 0;
        $userInfo["browser_id"] = $message['browser_id'];

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

        $this->numberOfUsers($ws, $fd, 'add', $userInfo);
    }

    protected function logout(\swoole_websocket_server $ws, $fd)
    {
        $chatrooms = Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $fd])
            ->find();
        $userInfo = $chatrooms[0];
        
        echo "Logout/Delete FD: {$fd}\n";
        Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $fd])
            ->delete();

        $this->numberOfUsers($ws, $fd, 'subtract', $userInfo);
    }

    protected function removeUserFromChatRoom($fd)
    {
        $chatrooms = Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $fd])
            ->find();
        $userInfo = $chatrooms[0];
        
        echo "Logout/Delete FD: {$fd}\n";
        Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['fd'=> $fd])
            ->delete();
    }

    protected function numberOfUsers($ws, $fd, $method = 'add', $userInfo = [])
    {
        if (empty($userInfo)) {
            return;
        }

        $browserUsers = Db::init($this->MysqlPool)
        ->name('chatrooms')
        ->where(['room'=> $userInfo['room'], 'video' => 1])
        ->group('browser_id')
        ->select();
        $totalUsers = count($browserUsers);
        
        //print_r($userInfo);
        $roomUsers = Db::init($this->MysqlPool)
        ->name('chatrooms')
        ->where(['room'=> $userInfo['room'], 'video' => 1])
        ->select();
        if (!empty($roomUsers)) {
            $message = [
                'update_viewers' => true,
                'live_viewers' => $totalUsers
            ];
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
        }
        //print_r($userInfo);
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

    protected function getAllUsersInRoom($room)
    {
        return Db::init($this->MysqlPool)
            ->name('chatrooms')
            ->where(['room'=> $room])
            ->select();
    }

    protected function pushAllMessagesToUser(\swoole_websocket_server $ws, $userInfo = [])
    {
       $chats = Db::init($this->MysqlPool)
            ->name('chats')
            ->where(['room'=> $userInfo['room']])
            ->order(['id'=>['id' => 'desc']])
            ->limit(30)
            ->select();
        print_r($userInfo);
        
        krsort($chats);
        //print_r($chats);

        foreach ($chats as $chat) {
            if (!empty($chat['created'])) {
                $chat['created'] = Carbon::createFromFormat('Y-m-d H:i:s', $chat['created'])->isoFormat('MMM D, h:mm:ss');
            }
            $chat['message'] = $this->coloredUsername($chat['message']);
            $ban = Db::init($this->MysqlPool)
                ->name('chat_bans')
                ->field('id,ban_username,room')
                ->where([
                    'ban_username' => $chat['username'],
                    'room' => $userInfo['room']
                ])
                ->find();
            if (!empty($ban)) {
                print_r("wtf");
                print_r($ban);
            }
            if (empty($ban)) {
                $ws->push($userInfo['fd'], json_encode($chat));
            }
        }
    }
}