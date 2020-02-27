<?php

namespace Ejz;

class RedisClient
{
    /** @var array */
    private $config;

    /** @var resource */
    private $socket;

    /**
     * @param array $config (optional)
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + [
            'host' => 'localhost',
            'port' => 6379,
            'auth' => false,
            'persistent' => false,
            'select' => 0,
            'timeout' => 5,
            'chunk' => 1024,
        ];
    }

    /**
     * @param ...$args
     *
     * @return array
     */
    public function HGETALL(...$args): array
    {
        array_unshift($args, 'HGETALL');
        $response = $this->send($args);
        for ($i = 0, $collect = [], $c = count($response); $i < $c; $i += 2) {
            $collect[$response[$i]] = $response[$i + 1];
        }
        return $collect;
    }

    /**
     * @param ...$args
     *
     * @return mixed
     */
    public function EVAL(...$args)
    {
        if (isset($args[0])) {
            $script = $args[0];
            $args[0] = sha1($args[0]);
        }
        array_unshift($args, 'EVALSHA');
        try {
            return $this->send($args);
        } catch (RedisClientException $exception) {
            $message = $exception->getMessage();
            [$err] = explode(' ', $message);
            if ($err != 'NOSCRIPT') {
                throw $exception;
            }
            $this->SCRIPT('LOAD', $script);
            return $this->send($args);
        }
    }

    /**
     * @param string $method
     * @param array  $args
     *
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        array_unshift($args, $method);
        return $this->send($args);
    }

    /**
     * @param array $args
     *
     * @return mixed
     */
    private function send(array $args)
    {
        $cmd = ['*' . count($args) . "\r\n"];
        foreach ($args as $item) {
            $cmd[] = '$' . strlen($item) . "\r\n" . $item . "\r\n";
        }
        $cmd = implode($cmd);
        $len = strlen($cmd);
        $written = 0;
        $socket = $this->getSocket();
        $tries = 3;
        do {
            $tries--;
            $res = fwrite($socket, substr($cmd, $written));
            if ($res === false || ($res <= 0 && !$tries)) {
                throw new RedisClientException('fwrite() ERROR');
            }
            $tries = $res > 0 ? 3 : $tries;
            $written += $res;
        } while ($written !== $len && ($res > 0 || !usleep(300000)));
        return $this->getResponse();
    }

    /**
     * @return resource
     */
    private function getSocket()
    {
        if ($this->socket) {
            return $this->socket;
        }
        $flags = STREAM_CLIENT_CONNECT | ($this->config['persistent'] ? STREAM_CLIENT_PERSISTENT : 0);
        @ $socket = stream_socket_client(
            $this->config['host'] . ':' . $this->config['port'],
            $errno,
            $errstr,
            $this->config['timeout'],
            $flags
        );
        if (!$socket) {
            throw new RedisClientException($errstr);
        }
        $this->socket = $socket;
        if ($this->config['auth']) {
            $this->AUTH($this->config['auth']);
        }
        if ($this->config['select']) {
            $this->SELECT($this->config['select']);
        }
        return $this->socket;
    }

    /**
     */
    public function close()
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * @return mixed
     */
    private function getResponse()
    {
        $socket = $this->getSocket();
        $chunk = $this->config['chunk'];
        $line = fgets($socket);
        if ($line === false) {
        	throw new RedisClientException('fgets() returned FALSE');
        }
        [$type, $result] = [$line[0], substr($line, 1, strlen($line) - 3)];
        if ($type == '-') {
            throw new RedisClientException($result);
        } elseif ($type == ':') {
            $result = (int) $result;
        } elseif ($type == '$') {
            if ($result == -1) {
                $result = null;
            } else {
                $len = $result + 2;
                $read = 0;
                $lines = [];
                do {
                    $res = fread($socket, min($chunk, $len));
                    if ($res === false) {
                        throw new RedisClientException('fread() returned FALSE');
                    }
                    $len -= strlen($res);
                    $lines[] = $res;
                } while ($read !== $len);
                $result = implode($lines);
                $result = substr($result, 0, strlen($result) - 2);
            }
        } elseif ($type == '*') {
            $count = (int) $result;
            for ($i = 0, $result = []; $i < $count; $i++) {
                $result[] = $this->getResponse();
            }
        }
        return $result;
    }
}
