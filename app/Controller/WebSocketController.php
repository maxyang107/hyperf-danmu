<?php


namespace App\Controller;


use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Utils\ApplicationContext;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\Websocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * hyperf弹幕服务器
 * Class WebSocketController
 * @package App\Controller
 */
class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    public function onMessage(WebSocketServer $server, Frame $frame): void
    {
        $data = json_decode($frame->data, true);
        switch ($data['action']) {
            case "login":
                self::bindUser($server, $data, $frame->fd);
                break;
            case "sendBarrage":
                self::sendMessageToGroup($data['room_id'], $data['content'], $frame->fd, $server);
                break;
            default:
                $server->push($frame->fd, "弹幕服务器连接成功，等待操作……");
                break;
        }
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        //当用户断开连接后释放用户房间绑定
        $container = ApplicationContext::getContainer();
        $redis     = $container->get(\Redis::class);
        $room_id = $redis->get('room_id_' . $fd);
        $redis->del('room_id_' . $fd);
        self::removeGroupMember($room_id, $fd);
    }

    public function onOpen(WebSocketServer $server, Request $request): void
    {
        $server->push($request->fd, '弹幕服务器连接成功……');
    }

    /**
     * 用户登陆弹幕服务器
     * @param  WebSocketServer  $server
     * @param  array            $data
     * @param  int              $client_id
     */
    public function bindUser(WebSocketServer $server, array $data, int $client_id): void
    {

        $container = ApplicationContext::getContainer();
        $redis     = $container->get(\Redis::class);
        if (!isset($data['room_id']) || empty($data['room_id'])) $server->push($client_id, '房间号不存在');
        /**
         * 如果用户已经绑定了房间，需要释放房间再绑定
         */

        $userInRoom = $redis->get('room_id_' . $client_id);
        if (!empty($userInRoom)) {
            self::removeGroupMember($userInRoom, $client_id);
            $redis->del('room_id_' . $client_id);
        }

        $redis->set('room_id_' . $client_id, $data['room_id']);
        self::addGroup($data['room_id'], $client_id);
        $server->push($client_id, '房间绑定成功，开始发送弹幕吧');
    }


    /**
     * 将客户端加入组
     * @param  string  $room_id
     * @param  int     $client
     */
    public function addGroup(string $room_id, int $client_id): bool
    {
        $container = ApplicationContext::getContainer();
        $redis     = $container->get(\Redis::class);

        $tmp  = json_decode($redis->get($room_id), true);
        $room = empty($tmp) ? [] : $tmp;
        if (!in_array($client_id, $room)) {
            array_push($room, $client_id);
        }
        $redis->set($room_id, json_encode($room));
        return true;
    }


    /**
     * 将某个用户移出组
     * @param  string  $room_id
     * @param  int     $client_id
     * @return bool
     */
    public function removeGroupMember(string $room_id, int $client_id): bool
    {
        $container = ApplicationContext::getContainer();
        $redis     = $container->get(\Redis::class);
        $tmp       = json_decode($redis->get($room_id), true);
        $room      = empty($tmp) ? [] : $tmp;
        if (empty($room)) return true;
        foreach ($room as $k => $v) {
            if ($client_id == $v) unset($room[$k]);
        }
        $redis->set($room_id, json_encode($room));
        return true;
    }


    /**
     * 向组发送消息
     * @param  WebSocketServer  $server
     * @param  string           $room_id
     * @param  array            $data
     * @param                   $client_id
     */
    public function sendMessageToGroup(string $room_id, string $data, int $client_id, WebSocketServer $server): void
    {
        $container = ApplicationContext::getContainer();
        $redis     = $container->get(\Redis::class);
        $room_ids  = json_decode($redis->get($room_id), true);
        if (empty($room_ids)) $server->push($client_id, '获取房间失败');
        foreach ($room_ids as $fd) {
            $server->push($fd, $data);
        }
    }

}