<?php

use Ejz\RedisClient;
use PHPUnit\Framework\TestCase;

class RedisClientTest extends TestCase
{
    /** @var RedisClient */
    private $redis;

    /**
     *
     */
    public function setUp()
    {
        parent::setUp();
        $this->redis = new RedisClient();
    }

    /**
     * @test
     */
    public function test_redis_client_ping()
    {
        $this->assertTrue((string) $this->redis->ping() === 'PONG');
    }

    /**
     * @test
     */
    public function test_redis_client_hgetall()
    {
        $this->redis->HMSET('key', 'k1', 'v1', 'k2', 'v2');
        $this->assertTrue($this->redis->HGETALL('key') === ['k1' => 'v1', 'k2' => 'v2']);
    }

    /**
     * @test
     */
    public function test_redis_client_eval()
    {
        foreach (range(time(), time() + 100) as $i) {
            $ret = $this->redis->EVAL('return ' . $i, 0);
            $this->assertTrue($ret === $i);
        }
        $ret = $this->redis->EVAL('return {1,2,{3,{"4",5}}}', 0);
        $this->assertTrue($ret === [1, 2, [3, ['4', 5]]]);
    }

    /**
     * @test
     */
    public function test_redis_client_exists()
    {
        $ret = $this->redis->EXISTS(md5(time()));
        $this->assertTrue($ret === 0);
    }
}
