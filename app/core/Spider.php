<?php
/**
 * Created by PhpStorm.
 * User: yidashi
 * Date: 2016/12/12
 * Time: 上午10:05
 */

namespace core;

abstract class Spider
{
    public $enabled = true;

    public $name;

    public $description;

    public $proxy;

    public $maxTry;
    /**
     * 没startUrls必须定义startRequests方法
     * @var array
     */
    public $startUrls = [];



}