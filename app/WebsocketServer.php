<?php
namespace App;

use sethink\swooleOrm\Db;
use sethink\swooleOrm\MysqlPool;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

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

        $this->ws->on('handshake', function (\swoole_http_request $request, \swoole_http_response $response) {
            $secWebSocketKey = $request->header['sec-websocket-key'];
            $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
            
            if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
                $response->end();
                return false;
            }
            
            echo $request->header['sec-websocket-key'];
            
            $key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        
            $headers = [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
                'Sec-WebSocket-Version' => '13',
            ];
        
            // WebSocket connection to 'ws://127.0.0.1:9502/'
            // failed: Error during WebSocket handshake:
            // Response must not include 'Sec-WebSocket-Protocol' header if not present in request: websocket
            if (isset($request->header['sec-websocket-protocol'])) {
                $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
            }
        
            foreach ($headers as $key => $val) {
                $response->header($key, $val);
            }
        
            $response->status(101);
            $response->end();
            echo "connected!" . PHP_EOL;
            echo "ip: ". $request->server['remote_addr'] . PHP_EOL;
            
            return true;
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
            date_default_timezone_set('America/Chicago');
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
                    case "get_live_stream":
                        $this->getLiveStream($ws, $frame->fd, $message);
                        break;
                    default:
                }
            }
        } else {
            print_r($frame);
            echo("Error: Received data is incomplete");
        }
        //echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
    }

    protected function getLiveStream(\swoole_websocket_server $ws, $fd, $message = [])
    {
        print_r($message);
        $room = $message['room'];
        $status = $message['status'];
        $liveStreams = Db::init($this->MysqlPool)
        ->name('streams')
        ->field('id,name,audience,created,stream_title,user_id')
        ->where([
            'status' => ['IN', ['live', 'live_screenshot']]
        ])
        ->select();
        foreach ($liveStreams as $key => $stream) {
            $user = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username')
            ->where([
                'id' => $stream['user_id']
            ])
            ->find();
            if (!empty($user)) {
                $liveStreams[$key]['username'] = $user[0]['username'];
            }
        }
        $roomUsers = Db::init($this->MysqlPool)
        ->name('chatrooms')
        ->select();
        if (!empty($roomUsers)) {
            $message = [
                'live_stream' => true,
                'total_live' => count($liveStreams),
                'streams' => $liveStreams
            ];
            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
        }

        if ($room == "defaultRoom") return;

        //push to room user
        $roomUsers = Db::init($this->MysqlPool)
        ->name('chatrooms')
        ->where(['room'=> $room])
        ->select();
        if (!empty($roomUsers)) {
            echo "check roomUsers status: {$status}\n";
            $stream = [];
            if ($status == "online") {
                $stream = Db::init($this->MysqlPool)
                    ->name('streams')
                    ->field('id,name,folder,audience,created,stream_title,user_id,use_version,status,disable_low_latency,vhost')
                    ->where([
                        'status' => ['IN', ['live', 'live_screenshot']]
                    ])->find();
                if (empty($stream)) return;
                $stream = $stream[0];
                $user = Db::init($this->MysqlPool)
                    ->name('users')
                    ->field('id,username,token,screenshot,subscribers_count,bio')
                    ->where([
                        'id' => $stream['user_id']
                    ])->find();
                if (empty($user)) return;
                $user = $user[0];

                $webrtcApi = getenv('webrtc_api');
                if ($stream['vhost'] == "nj.goohshi.com") {
                    $webrtcApi = 'rtc-nj.goohshi.com';
                }

                $streamQuality = [];
                if (in_array($stream['status'], ['live', 'live_screenshot']) && $stream['disable_low_latency'] == 0) {
                    $streamQuality[] = [
                        'label' => 'Low Latency',
                        'size' => 'ftl',
                        'src' => 'webrtc://'. $webrtcApi .'/live/' . $stream['name'],
                        'type' => 'webrtc'
                    ];
                }

                $videoUrl = '/live/videos/';
                $streamURL = $videoUrl . $stream['folder'] . '/' . $user['token'] . '.m3u8';
                $streamType = 'hls';
                $streamQuality[] = [
                    'label' => 'HLS: Source',
                    'size' => 'Source',
                    'src' => $videoUrl . $stream['folder'] . '/' . $user['token'] . '.m3u8',
                    'type' => 'application/x-mpegURL'
                ];
                if ($stream['use_version']) {
                    $streamQuality[] = [
                        'label' => '720p@30fps',
                        'size' => '720p',
                        'src' => $videoUrl . $stream['folder'] . '_720p/' . $user['token'] . '.m3u8',
                        'type' => 'application/x-mpegURL'
                    ];
                    $streamQuality[] = [
                        'label' => '480p',
                        'size' => '480p',
                        'src' => $videoUrl . $stream['folder'] . '_480p/' . $user['token'] . '.m3u8',
                        'type' => 'application/x-mpegURL'
                    ];
                    $streamQuality[] = [
                        'label' => '360p',
                        'size' => '360p',
                        'src' => $videoUrl . $stream['folder'] . '_360p/' . $user['token'] . '.m3u8',
                        'type' => 'application/x-mpegURL'
                    ];
                }
                if (!empty($user['screenshot'])) {
                    $screenshot = '/live/screenshot/' . $user['screenshot'];
                } else {
                    $screenshot = '/vidoe/img/s4.png';
                }
                
                $stream = [
                    'username' => $user['username'],
                    'user_image' => '/users/image/' . $user['username'],
                    'title' => $stream['stream_title'],
                    'room' => $stream['name'],
                    'url' => '/streams/' . $user['username'],
                    'streamURL' => $streamURL,
                    'streamType' => $streamType,
                    'streamQuality' => $streamQuality,
                    'screenshot' => $screenshot,
                    'status' => $stream['status'],
                    'stream_id' => $stream['id'],
                    'userid' => $user['id'],
                    'subscribers_count' => $user['subscribers_count'],
                    'audience' => $stream['audience'],
                    'vhost' => $stream['vhost'],
                    'webrtc_api' => "https://" . $webrtcApi . "/rtc/v1/play/",
                    'webrtc_stream_url' => "webrtc://" . $webrtcApi . "/live/" . $stream['name']
                ];
                $message = [
                    'online_live_stream' => true,
                    'stream' => $stream,
                    'room' => $room
                ];
            } else {
                $message = [
                    'offline_live_stream' => true,
                    'stream' => $stream,
                    'room' => $room
                ];
            }

            foreach ($roomUsers as $roomUsers) {
                $ws->push($roomUsers['fd'], json_encode($message));
            }
        }
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

        //follow notication
        if (!empty($message['followNotification'])) {
            $message['message'] = '<strong>' . $message['username']. '</strong> now follows <strong>'. $message['streamer'] .'</strong>!';
        }

        if (!empty($message['guest'])) {
            $chatGuest = Db::init($this->MysqlPool)
                ->name('chat_user_guests')
                ->where([
                    'name' => ['LOWER', strtolower($message['username'])]
                ])
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
                'ban_username' => ['LOWER', strtolower($message['username'])],
                'room' => $message['room']
            ])
            ->find();
        if (!empty($banUser)) {
            $message['error_message'] = "Chat is not available for banned user";
            $ws->push($fd, json_encode($message));
            //not allowed to send message
            return;
        }

        //check if ban user from admin
        $siteWideBanned = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,banned,username')
            ->where([
                'username' => ['LOWER', strtolower($message['username'])],
                'banned' => 1
            ])
            ->find();
        if (!empty($siteWideBanned)) {
            //$message['error_message'] = "Chat is not available for banned user";
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
            //print_r($chat);
            if (!empty($chat)) {
                $message = array_merge($message, $chat[0]);
                //print_r($message);
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
                $tipMessage = '<strong>' . $user .'</strong> just ordered for <strong>'. $message['points'] . ' goo</strong>';
                $tipMessage .= '<br />"<span class="fa fa-star star-checked"></span> <strong><em>' . $message['gooOrderMessage'] . '</em></strong>"';
                $message['message_type'] = "notification_goo_order";
            } else {
                $tipMessage = '<strong>' . $user .'</strong> sent <strong>'. $message['points'] . ' goo!</strong>';
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
            $message = $this->getUserColor($message);
            
            foreach ($roomUsers as $roomUsers) {
                $ban = Db::init($this->MysqlPool)
                    ->name('chat_bans')
                    ->field('id,room,ban_username')
                    ->where([
                        'ban_username' => ['LOWER', strtolower($roomUsers['username'])],
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
        //print_r($message);
        
        //krsort($chats);
        //print_r($chats);

        foreach ($chats as $chat) {
            if (!empty($chat['created'])) {
                $chat['created'] = Carbon::createFromFormat('Y-m-d H:i:s', $chat['created'])->isoFormat('MMM D, h:mm:ss');
            }
            $chat['type'] = "get_history";
            $chat['message'] = $this->coloredUsername($chat['message']);
            $chat = $this->getUserColor($chat);
            $ban = Db::init($this->MysqlPool)
                ->name('chat_bans')
                ->field('id,ban_username,room')
                ->where([
                    'ban_username' => ['LOWER', strtolower($chat['username'])],
                    'room' => $message['room']
                ])
                ->find();
            if (!empty($ban)) {
                //print_r("wtf");
                //print_r($ban);
            }
            if (empty($ban)) {
                $ws->push($fd, json_encode($chat));
            }
        }
    }

    /* replace @username with text-info */
    protected function coloredUsername($message)
    {
        $message = preg_replace('/(\@([a-zA-Z\'-]+)\w+)/', '<span class="text-info">$1</span>', $message);
        return $message;
    }

    protected function getUserColor($message)
    {
        //print_r("get User color");
        $user = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,color')
            ->where([
                'username' => ['LOWER', strtolower($message['username'])]
            ])
            ->find();

        //print_r($user);
        if (!empty($user)) {
            if (!empty($user[0]['color'])) {
                $message['color'] = $user[0]['color'];
            }
        }
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
            ->field('id,username,token,coin,consumable_coin,streamer_goo_goals')
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
                ->field('id,username,token,coin,consumable_coin')
                ->where([
                    'username' => ['LOWER', strtolower($message['username'])]
                ])
                ->find();

            $senderTotalCoin = (int)$sender[0]['coin'] + (int)$sender[0]['consumable_coin'];
            $streamerTotalCoin = (int)$streamer[0]['coin'] + (int)$streamer[0]['consumable_coin'];
            //print_r("total sender coin: " . $senderTotalCoin);
            //print_r("total streamer coin: " . $streamerTotalCoin);


            if ($sender[0]['id'] == $streamer[0]['id']) {
                $message['updateCoin'] = $senderTotalCoin;
                $message['nocheer'] = true;
                return $message;
            }
            
            //print_r($points ." > " . $sender[0]['coin']);
            if ($points > $senderTotalCoin) {
                $message['nocheer'] = true;
                return $message;
            }

            //substract points to sender
            if ((int)$sender[0]['consumable_coin'] > 0) {
                $updateConsumableCoin = (int)$sender[0]['consumable_coin'] - $points;
                $updateCoin = (int)$sender[0]['coin'];
                if ($updateConsumableCoin < 0) {
                    $updateCoin = (int)$sender[0]['coin'] + $updateConsumableCoin;
                    $updateConsumableCoin = 0;
                }
                Db::init($this->MysqlPool)
                    ->name('users')->where(['id' => $sender[0]['id']])
                    ->update(['coin' => $updateCoin, 'consumable_coin' => $updateConsumableCoin]);
                $updateCoin = $updateCoin + $updateConsumableCoin;
            } else {
                $updateCoin = (int)$sender[0]['coin'] - $points;
                Db::init($this->MysqlPool)
                    ->name('users')->where(['id' => $sender[0]['id']])
                    ->update(['coin' => $updateCoin]);
            }

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
            
            //user Points receive
            $userPoints = [
                'user_id' => $sender[0]['id'],
                'send_to' => $streamer[0]['id'],
                'points' => $points,
                'type' => 'receive',
                'updated_to_new_history' => 1,
                'created' => date("Y-m-d H:i:s"),
                'modified' => date("Y-m-d H:i:s")
            ];
            Db::init($this->MysqlPool)
            ->name('user_points')
            ->insert($userPoints);
            //user Points sent
            $userPointsSent = [
                'user_id' => $streamer[0]['id'],
                'send_to' => $sender[0]['id'],
                'points' => -$points,
                'type' => 'sent',
                'updated_to_new_history' => 1,
                'created' => date("Y-m-d H:i:s"),
                'modified' => date("Y-m-d H:i:s")
            ];
            Db::init($this->MysqlPool)
            ->name('user_points')
            ->insert($userPointsSent);
            
            $message['updateCoin'] = $updateCoin;
            $message['sendCheer'] = true;

            $goals = (int)$streamer[0]['streamer_goo_goals'];
            if ($goals > 0) {
                $searchDate = CarbonImmutable::now();
                $startDate = $searchDate->startOfWeek(Carbon::SATURDAY)->format('Y-m-d H:i:s');
                $endDate = date("Y-m-d H:i:s");
                $getUserPoints = Db::init($this->MysqlPool)
                ->name('user_points')
                ->where([
                    'send_to' => $streamer[0]['id'],
                    'reset' => 0
                ])
                ->select();
                $raised = 0;
                foreach ($getUserPoints as $userPoint) {
                    $raised += (int)$userPoint['points'];
                }
                $raisedPercent = ($raised / $goals) * 100;
                if ($raisedPercent < 30) {
                    $raisedPercent = 30;
                } elseif ($raisedPercent > 100) {
                    $raisedPercent = 100;
                }
                $message['gooRaised'] = [
                    'raised' => $raised,
                    'goals' => $goals,
                    'percentage' => $raisedPercent
                ];
            }
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
        unset($message['streamer']);
        unset($message['followNotification']);
        unset($message['gooRaised']);
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
        } elseif (!empty($message['guest'])) {
            
        } else {
            $user = Db::init($this->MysqlPool)
            ->name('users')
            ->field('id,username,token,coin')
            ->where([
                'username' => ['LOWER', strtolower($message['username'])]
            ])
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
            ->where([
                'username' => ['LOWER', strtolower($message['username'])]
            ])
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

        if (empty($chatrooms)) return;
        
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
        //print_r($userInfo);
        
        krsort($chats);
        //print_r($chats);

        foreach ($chats as $chat) {
            if (!empty($chat['created'])) {
                $chat['created'] = Carbon::createFromFormat('Y-m-d H:i:s', $chat['created'])->isoFormat('MMM D, h:mm:ss');
            }
            $chat['message'] = $this->coloredUsername($chat['message']);
            $chat = $this->getUserColor($chat);
            $ban = Db::init($this->MysqlPool)
                ->name('chat_bans')
                ->field('id,ban_username,room')
                ->where([
                    'ban_username' => ['LOWER', strtolower($chat['username'])],
                    'room' => $userInfo['room']
                ])
                ->find();
            if (!empty($ban)) {
                //print_r("wtf");
                //print_r($ban);
            }
            if (empty($ban)) {
                $ws->push($userInfo['fd'], json_encode($chat));
            }
        }
    }
}