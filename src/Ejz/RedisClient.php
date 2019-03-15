<?php

namespace Ejz;

class RedisClient
{
    /** @var array */
    private $config;

    /** @var resource */
    private $socket;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config + [
            'host' => 'localhost:6379',
            'auth' => '',
            'persistent' => false,
            'select' => 0,
            'timeout' => 5,
        ];
    }

    /**
     * @param ... $args
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
     * @param string $method
     * @param array  $args
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
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $item) {
            $cmd .= '$' . strlen($item) . "\r\n" . $item . "\r\n";
        }
        fwrite($this->getSocket(), $cmd);
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
            $this->config['host'],
            $errno,
            $errstr,
            $this->config['timeout'],
            $flags
        );
        if (!$socket) {
            throw new RedisClientException($errstr);
        }
        $this->socket = $socket;
        if ($this->config['auth'] !== '') {
            $this->AUTH($this->config['auth']);
        }
        return $this->socket;
    }

    /**
     * @return mixed
     */
    private function getResponse()
    {
        $socket = $this->getSocket();
        $line = fgets($socket);
        [$type, $result] = [$line[0], substr($line, 1, strlen($line) - 3)];
        if ($type == '-') {
            throw new RedisClientException($result);
        } elseif ($type == ':') {
            $result = (int) $result;
        } elseif ($type == '$') {
            if ($result == -1) {
                $result = null;
            } else {
                $line = fread($socket, $result + 2);
                $result = substr($line, 0, strlen($line) - 2);
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
