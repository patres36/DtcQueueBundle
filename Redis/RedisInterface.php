<?php

namespace Dtc\QueueBundle\Redis;

interface RedisInterface
{
    public function zRem($zkey, $value);

    public function zAdd($zkey, $score, $value);

    public function zPopByMaxScore($key, $max);

    public function zPop($key);

    public function zCount($key, $min, $max);

    public function zScan($key, &$cursor, $pattern = '', $count = 0);

    /**
     * @param $key
     * @param $cursor
     * @param string $pattern
     * @param int    $count
     *
     * @return array
     */
    public function hScan($key, &$cursor, $pattern = '', $count = 0);

    public function hIncrBy($key, $hashKey, $value);

    public function hGetAll($key);

    /**
     * @param $key
     * @param $value
     *
     * @return bool true on success, false on failure
     */
    public function set($key, $value);

    /**
     * @param $key
     *
     * @return false if not set, otherwise the value if set
     */
    public function get($key);

    public function mGet(array $keys);

    /**
     * @param array $keys A list of keys to delete
     *
     * @return int The number of keys deleted
     */
    public function del(array $keys);

    public function setEx($key, $ttl, $value);

    public function lRange($lKey, $start, $stop);

    public function lRem($lKey, $count, $value);

    public function lPush($lKey, array $values);
}
