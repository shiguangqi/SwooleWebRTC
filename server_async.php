<?php

class WebRTC
{
    const WEBROOT = __DIR__ . '/web';

    public static $server = null;
    public static $subject2fd = [];
    public static $fd2subject = [];

    function onClose(Swoole\Server $server, int $fd, int $reactorId)
    {
        echo "server: $fd closed\n";
        if (isset(self::$fd2subject[$fd])) {
            $subject = self::$fd2subject[$fd];
            unset(self::$subject2fd[$subject][$fd]);
        }
    }

    function onOpen(Swoole\WebSocket\Server $server, $request)
    {
        echo "server: handshake success with fd{$request->fd}\n";
    }

    function onMessage(Swoole\WebSocket\Server $server, $frame)
    {
        echo "receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);
        switch ($data['cmd']) {
            case 'subscribe':
                $subject = $data['subject'];
                self::$subject2fd[$subject][$fd] = $fd;//每个房间可能有多个连接
                self::$fd2subject[$fd] = $subject;//一个连接只能对应一个房间
                break;
            case 'publish':
                $this->publish($data, $fd);
                break;
        }
    }

    function publish($data, $current_fd)
    {
        $subject = $data['subject'];
        $event = $data['event'];
        $data = $data['data'];
        if (empty(self::$subject2fd[$subject])) {
            return;
        }
        foreach (self::$subject2fd[$subject] as $_fd) {
            if ($_fd == $current_fd) {
                continue;
            }
            self::$server->push($_fd,
                json_encode(
                    array(
                        'cmd' => 'publish',
                        'event' => $event,
                        'data' => $data
                    )
                )
            );
        }
    }

    function onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response)
    {
        $path = $request->server['request_uri'];
        if ($path == "/") {
            $response->sendfile(self::WEBROOT . '/index.html');
        } else {
            $file = realpath(self::WEBROOT . $path);
            if (false === $file) {
                $response->status(404);
                $response->end('<h3>404 Not Found</h3>');
                return;
            }
            if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $response->end($this->get_php_file($file));
                return;
            }
            if (isset($req->header['if-modified-since']) and !empty($if_modified_since = $req->header['if-modified-since'])) {
                $info = \stat($file);
                $modified_time = $info ? \date(
                        'D, d M Y H:i:s',
                        $info['mtime']
                    ) . ' ' . \date_default_timezone_get() : '';
                if ($modified_time === $if_modified_since) {
                    $response->status(304);
                    $response->end();
                    return;
                }
            }
            $response->sendfile($file);
        }
    }

    function get_php_file($file)
    {
        \ob_start();
        try {
            include $file;
        } catch (\Exception $e) {
            echo $e;
        }
        return \ob_get_clean();
    }

    function run()
    {
        $server = new Swoole\WebSocket\Server("0.0.0.0", 9509, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        $server->set([
            'ssl_key_file' => __DIR__ . '/ssl/ssl.key',
            'ssl_cert_file' => __DIR__ . '/ssl/ssl.crt',
        ]);
        $server->on('open', [$this, "onOpen"]);
        $server->on('close', [$this, "onClose"]);
        $server->on('message', [$this, "onMessage"]);
        $server->on('request', [$this, "onRequest"]);
        self::$server = $server;
        $server->start();
    }
}

$w = new WebRTC();
$w->run();
