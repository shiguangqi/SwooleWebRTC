<?php

use Swoole\Http\Request;
use Swoole\Http\Response;

const WEBROOT = __DIR__ . '/web';
$connnection_map = array();
error_reporting(E_ALL);
Co\run(function () {
    $server = new Swoole\Coroutine\Http\Server('0.0.0.0', 9509, true);
    $server->set([
        'ssl_key_file' => __DIR__ . '/ssl/ssl.key',
        'ssl_cert_file' => __DIR__ . '/ssl/ssl.crt',
    ]);
    $server->handle('/', function (Request $req, Response $resp) {
        //websocket
        if (isset($req->header['upgrade']) and $req->header['upgrade'] == 'websocket') {
            $resp->upgrade();
            $resp->subjects = array();
            while (true) {
                $frame = $resp->recv();
                if (empty($frame)) {
                    break;
                }
                $data = json_decode($frame->data, true);
                switch ($data['cmd']) {
                    case 'subscribe':
                        subscribe($data, $resp);
                        break;
                    case 'publish':
                        publish($data, $resp);
                        break;
                }
            }
            free_connection($resp);
            return;
        }
        //http
        $path = $req->server['request_uri'];
        if ($path == '/') {
            $resp->end(get_php_file(WEBROOT . '/index.html'));
        } else {
            $file = realpath(WEBROOT . $path);
            if (false === $file) {
                $resp->status(404);
                $resp->end('<h3>404 Not Found</h3>');
                return;
            }
            if (strpos($file, WEBROOT) !== 0) {
                $resp->status(400);
                return;
            }
            if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $resp->end(get_php_file($file));
                return;
            }
            if (isset($req->header['if-modified-since']) and !empty($if_modified_since = $req->header['if-modified-since'])) {
                $info = \stat($file);
                $modified_time = $info ? \date('D, d M Y H:i:s', $info['mtime']) . ' ' . \date_default_timezone_get() : '';
                if ($modified_time === $if_modified_since) {
                    $resp->status(304);
                    $resp->end();
                    return;
                }
            }
            $resp->sendfile($file);
        }
    });
    $server->start();
});


function subscribe($data, $connection)
{
    global $connnection_map;
    $subject = $data['subject'];
    $connection->subjects[$subject] = $subject;
    $connnection_map[$subject][$connection->fd] = $connection;
}

function unsubscribe($subject, $current_conn)
{
    global $connnection_map;
    unset($connnection_map[$subject][$current_conn->fd]);
}

function publish($data, $current_conn)
{
    global $connnection_map;
    $subject = $data['subject'];
    $event = $data['event'];
    $data = $data['data'];
    //当前主题不存在
    if (empty($connnection_map[$subject])) {
        return;
    }
    foreach ($connnection_map[$subject] as $connection) {
        //不给当前连接发送数据
        if ($current_conn == $connection) {
            continue;
        }
        $connection->push(
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

function free_connection($connection)
{
    foreach ($connection->subjects as $subject) {
        unsubscribe($subject, $connection);
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
