<?php
/**
 * Created by PhpStorm.
 * Author: yidashi
 * DateTime: 2017/5/19 14:10
 * Description:
 */

namespace core;


use GuzzleHttp\Client;
use Log;
use DB;
use Event;

class Request
{
    const EVENT_INIT = 'init';
    const EVENT_BEFORE_RUN = 'beforeRun';
    const EVENT_AFTER_RUN = 'afterRun';

    public $useCache = false;
    /**
     * @var Link
     */
    public $link;

    public $callback;

    public $callbackParams = [];

    public $timeRun = 0;

    public function __construct($config = [])
    {
        if (!empty($config)) {
            foreach ($config as $name => $value) {
                $this->$name = $value;
            }
        }
        Event::fire('request.' . self::EVENT_INIT, [$this]);
    }

    public static function create($link, $callback, $callbackParams = [])
    {
        return new static(['link' => $link, 'callback' => $callback, 'callbackParams' => $callbackParams]);
    }

    public function beforeRun()
    {
        Event::fire('request.' . self::EVENT_BEFORE_RUN, [$this]);
    }

    public function afterRun()
    {
        Event::fire('request.' . self::EVENT_AFTER_RUN, [$this]);
    }
    /**
     * 执行请求
     * @return bool|mixed|\Psr\Http\Message\ResponseInterface|Response
     */
    public function run()
    {
        $this->beforeRun();
        $startTime = microtime(true);
        $link = $this->link;
        Log::debug('开始下载：' . $link->url);
        $clientConfig = [
            'cookies' => true,
            'headers' => [
                'User-Agent' => config('crawler.user_agent'),
                'Referer' => $link->referer
            ],
            'timeout' => config('crawler.timeout'),
        ];
        /*if ($link->proxy) {
            $proxy = Yii::$app->proxyPool->getOne();
            if ($proxy) {
                $clientConfig['proxy'] = [
                    'http' => $proxy
                ];
            }
        }*/
        $client = new Client($clientConfig);
        try {
            $response = $client->request($link->method, $link->url);
            $response = new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders());
        } catch(\Exception $e) {
            $link->tryNum ++;
            Log::error(printf('下载失败(%d)：%s，失败原因：%s', $link->tryNum, $link->url, $e->getMessage()));
            if ($link->tryNum < $link->maxTry) {
                $response = $this->run();
            } else {
                return false;
            }
        }
        $this->afterRun();
        return $response;
    }

}