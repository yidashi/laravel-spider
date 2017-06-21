<?php
/**
 * Created by PhpStorm.
 * Author: yidashi
 * DateTime: 2017/5/18 10:47
 * Description:
 */

namespace core;


class Link
{
    public $url;

    public $referer;

    public $method = 'get';

    public $proxy = false;

    public $maxTry = 5;

    public $tryNum = 0;

    public $timeout;

    /**
     * Link constructor.
     * @param string $url
     * @param array $config
     */
    public function __construct($url, array $config = [])
    {
        $this->url = $url;
        if (!empty($config)) {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }
        }
    }

    /**
     * @param $url
     * @param array $config
     * @return static
     */
    public static function create($url, array $config = [])
    {
        return new static($url, $config);
    }
}