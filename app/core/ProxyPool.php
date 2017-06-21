<?php
/**
 * Created by PhpStorm.
 * Author: yidashi
 * DateTime: 2017/5/17 18:56
 * Description:
 */

namespace core;


use yii\base\Object;
use yii\di\Instance;
use yii\redis\Connection;

class ProxyPool extends Object
{
    public $pool = [];

    /**
     * @var string|array|Connection
     */
    public $redis = 'redis';

    public $redisKey = 'proxy_pool';

    public function init()
    {
        parent::init();
        $this->redis = Instance::ensure($this->redis, Connection::className());
        $this->pool = [
            '119.57.105.233:8080',
        ];
        $this->start();
    }

    public function start()
    {
        // 扔到redis里
        foreach ($this->pool as $proxy) {
            $this->redis->sadd($this->redisKey, $proxy);
        }
    }

    public function getOne()
    {
        return $this->redis->srandmember($this->redisKey);
    }

    public function remove($proxy)
    {
        $this->redis->srem($this->redisKey, $proxy);
    }

    public function add($proxy)
    {
        $this->redis->sadd($this->redisKey, $proxy);
    }
}