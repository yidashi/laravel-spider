<?php

/**
 * Created by PhpStorm.
 * Author: yidashi
 * DateTime: 2017/5/16 15:10
 * Description:
 */
namespace core;

use App\Helpers\Console;
use Log;
use Redis;
use DB;
use Event;

class Core
{
    public $spiderNamespace = 'app\spiders';

    private $spider;

    public $showLog = false;
    /**
     * 主任务进程
     */
    public $taskmaster = true;

    /**
     * 当前任务ID
     */
    public $taskId = 1;

    /**
     * 当前任务进程ID
     */
    public $taskPid = 1;

    /**
     * 并发任务数
     */
    public $taskNum = 10;

    /**
     * 生成
     */
    public $forkTaskComplete = false;

    /**
     * 爬虫开始时间
     */
    public $startTime = 0;

    /**
     * 当前进程采集成功数
     */
    public $collectSuccess = 0;

    /**
     * 当前进程采集失败数
     */
    public $collectFail = 0;

    /**
     * 当前进程是否终止
     */
    public $terminate = false;

    // 运行面板参数长度
    public static $server_length = 10;
    public static $tasknum_length = 8;
    public static $taskid_length = 8;
    public static $pid_length = 8;
    public static $mem_length = 8;
    public static $urls_length = 15;
    public static $speed_length = 6;

    public function __construct($spiderName, array $config = [])
    {
        declare(ticks = 1);
        $this->spider = $this->createSpider($spiderName);
        if (!function_exists('pcntl_fork')) {
            $this->taskNum = 1;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'sigHandler']);
        }
        if (isWin()) {
            $this->showLog = true;
        }
        if (!$this->showLog) {
//            unset(Yii::$app->log->targets['show']);
        }
    }

    public function checkCache()
    {
        if ($this->getQueueSize() > 0) {
            exit;
        }
    }
    /**
     * ctrl+c 停止信号处理
     * @param $signo
     */
    public function sigHandler($signo)
    {
        switch ($signo) {
            // 停止.
            case SIGINT:
                Log::info("任务停止ing...");
                $this->terminate = true;
                break;
        }

    }
    /**
     * @param $spiderName
     * @throws \Exception
     * @return \core\Spider|object
     */
    public function createSpider($spiderName)
    {
        $pos = strrpos($spiderName, '/');
        if ($pos !== false) {
            $spiderNamespace = $this->spiderNamespace . '\\' . str_replace('/', '\\', substr($spiderName, 0, $pos));
            $spiderName = substr($spiderName, $pos + 1);
        } else {
            $spiderNamespace = $this->spiderNamespace;
        }
        $spiderClassName = $spiderNamespace . '\\' . ucfirst($spiderName) . 'Spider';
        if (!class_exists($spiderClassName)) {
            throw new \Exception('蜘蛛不存在');
        }
        $spider = new $spiderClassName;
        if (!$spider->enabled) {
            throw new \Exception('蜘蛛不可用');
        }
        return $spider;
    }

    public function run()
    {
        Log::info('开始' . $this->spider->name);
        $this->startTime = microtime(true);
        $this->taskPid = function_exists('posix_getpid') ? posix_getpid() : 1;
        Event::listen('request.init', function ($request) {
            Redis::lpush('collect_queue', serialize($request));
            Redis::incr('collect_urls_num');
        });
        try {
            if (method_exists($this->spider, 'startRequests')) {
                $this->spider->startRequests();
            } else {
                foreach ($this->spider->startUrls as $startUrl) {
                    Request::create(Link::create($startUrl), [$this->spider, 'parse']);
                }
            }
            if (!$this->showLog) {
                // 显示面板
                Console::clearScreen();
                $this->display_ui();
            }

            $this->doCollectPage();
        } catch(\Exception $e) {
            throw $e;
        } finally {
            Log::info(sprintf('结束，共耗时:%f，共采集%d个页面', (microtime(true) - $this->startTime), $this->getCollectedUrsNum()));
        }
    }

    public function doCollectPage()
    {
        while($queueSize = $this->getQueueSize()) {
            // 如果是主任务
            if ($this->taskmaster) {
                // 多任务下主任务未准备就绪
                if ($this->taskNum > 1 && !$this->forkTaskComplete) {
                    // 主进程采集到两倍于任务数时, 生成子任务一起采集
                    if ($queueSize > $this->taskNum) {
                        $this->forkTaskComplete = true;

                        // task进程从2开始, 1被master进程所使用
                        for ($i = 2; $i <= $this->taskNum; $i++) {
                            $this->forkOneTask($i);
                        }
                    }
                }

                // 抓取页面
                $this->collectPage();
                // 保存任务状态
                $this->setTaskStatus();
                if (!$this->showLog) {
                    //子任务也刷新的话速度会太快
                    $this->display_ui();
                }
            } else {// 如果是子任务
                // 如果队列中的网页比任务数2倍多, 子任务可以采集, 否则等待...
                if ($queueSize > $this->taskNum) {
                    // 抓取页面
                    $this->collectPage();
                    // 保存任务状态
                    $this->setTaskStatus();
                } else {
                    Log::info("子进程(" . $this->taskId . ") 待机中...");
                    sleep(1);
                }
            }
            // 检查进程是否收到关闭信号
            $this->checkTerminate();
            usleep(config('crawler.interval') * 1000);
        }
    }

    public function collectPage()
    {
        $request = unserialize(Redis::rpop('collect_queue'));
        $response = $request->run();
        Redis::incr('collected_urls_num');
        if ($request->link && $response) {
            $this->collectSuccess ++;
            call_user_func($request->callback, $response, ...$request->callbackParams);
        } else {
            $this->collectFail ++;
        }
    }

    /**
     * 创建一个子进程
     * @param int $taskId
     * @throws \Exception
     */
    public function forkOneTask($taskId)
    {
        DB::disconnect();
        Redis::close();
        $pid = pcntl_fork();

        // 主进程记录子进程pid
        if($pid > 0) {
            // 暂时没用
            //$this->taskPids[$taskId] = $pid;
        }
        // 子进程运行
        elseif(0 === $pid) {
            Log::info("开启子进程({$taskId})成功...");

            // 初始化子进程参数
            $this->startTime = microtime(true);
            $this->taskId     = $taskId;
            $this->taskmaster = false;
            $this->taskPid    = posix_getpid();
            $this->collectSuccess = 0;
            $this->collectFail = 0;
            $this->doCollectPage();

            // 这里用0表示正常退出
            exit(0);
        } else {
            Log::error("开启子进程({$taskId})失败...");
            exit;
        }
    }

    /**
     * 设置任务状态, 主进程和子进程每成功采集一个页面后调用
     */
    public function setTaskStatus()
    {
        // 每采集成功一个页面, 生成当前进程状态到文件, 供主进程使用
        $mem = round(memory_get_usage(true)/(1024*1024),2);
        $use_time = microtime(true) - $this->startTime;
        $speed = round(($this->collectSuccess + $this->collectFail) / $use_time, 2);
        $status = array(
            'id' => $this->taskId,
            'pid' => $this->taskPid,
            'mem' => $mem,
            'collect_succ' => $this->collectSuccess,
            'collect_fail' => $this->collectFail,
            'speed' => $speed,
        );
        $task_status = json_encode($status);

        $key = "task_status-" . $this->taskId;
        Redis::set($key, $task_status);
    }

    public function getTaskStatus($taskId)
    {
        $key = "task_status-{$taskId}";
        $task_status = Redis::get($key);
        return $task_status;
    }

    public function delTaskStatus($taskId)
    {
        $key = "task_status-{$taskId}";
        Redis::del($key);
    }

    public function getTaskStatusList($taskNum)
    {
        $taskStatus = [];
        for ($i = 1; $i <= $taskNum; $i++) {
            $key = "task_status-".$i;
            $taskStatus[] = Redis::get($key);
        }
        return $taskStatus;
    }
    /**
     * 检查是否终止当前进程
     *
     * @return mixed
     */
    public function checkTerminate()
    {
        if (!$this->terminate) {
            return;
        }

        // 删除当前任务状态
        $this->delTaskStatus($this->taskId);

        if ($this->taskmaster) {
            // 检查子进程是否都退出
            while (true) {
                $allStop = true;
                for ($i = 2; $i <= $this->taskNum; $i++) {
                    // 只要一个还活着就说明没有完全退出
                    $task_status = $this->getTaskStatus($i);
                    if ($task_status) {
                        $allStop = false;
                    }
                }
                if ($allStop) {
                    break;
                } else {
                    Log::info("子进程待结束...");
                }
                sleep(1);
            }
        }
        exit();
    }

    public function getCollectedUrsNum()
    {
        return Redis::get('collected_urls_num');
    }

    public function getCollectUrsNum()
    {
        return Redis::get('collect_urls_num');
    }

    public function getQueueSize()
    {
        return Redis::llen('collect_queue');
    }


    /**
     * 替换shell输出内容
     *
     * @param mixed $message
     * @param mixed $force_clear_lines
     * @return void
     */
    public function replace_echo($message, $force_clear_lines = NULL)
    {
        static $last_lines = 0;

        if(!is_null($force_clear_lines)) {
            $last_lines = $force_clear_lines;
        }

        // 获取终端宽度
        $toss = $status = null;
        $term_width = exec('tput cols', $toss, $status);
        if($status || empty($term_width)) {
            $term_width = 64; // Arbitrary fall-back term width.
        }

        $line_count = 0;
        foreach(explode("\n", $message) as $line) {
            $line_count += count(str_split($line, $term_width));
        }

        // Erasure MAGIC: Clear as many lines as the last output had.
        for($i = 0; $i < $last_lines; $i++) {
            // Return to the beginning of the line
            echo "\r";
            // Erase to the end of the line
            echo "\033[K";
            // Move cursor Up a line
            echo "\033[1A";
            // Return to the beginning of the line
            echo "\r";
            // Erase to the end of the line
            echo "\033[K";
            // Return to the beginning of the line
            echo "\r";
            // Can be consolodated into
            // echo "\r\033[K\033[1A\r\033[K\r";
        }

        $last_lines = $line_count;

        echo $message."\n";
    }

    /**
     * 展示启动界面, Windows 不会到这里来
     * @return void
     */
    public function display_ui()
    {
        $loadavg = sys_getloadavg();
        foreach ($loadavg as $k=>$v) {
            $loadavg[$k] = round($v, 2);
        }
        $display_str = "\n-----------------------------" . Console::ansiFormat(config('app.name'), [Console::BG_GREY, Console::FG_BLACK]) . "-----------------------------\n\n";
        $run_time_str = microtime(true) - $this->startTime;
        $display_str .= Console::ansiFormat('开始时间: ', [Console::FG_YELLOW]) . date('Y-m-d H:i:s', $this->startTime) . Console::ansiFormat('   执行时间: ', [Console::FG_YELLOW]) . $run_time_str . " \n";

        $display_str .= Console::ansiFormat('蜘蛛名: ', [Console::FG_YELLOW]) . $this->spider->name . "\n";
        $display_str .= Console::ansiFormat('任务数: ', [Console::FG_YELLOW]) . $this->taskNum . "\n";
        $display_str .= Console::ansiFormat('负载: ', [Console::FG_YELLOW]) . implode(", ", $loadavg) . "\n";

        $display_str .= $this->display_task_ui();

        $display_str .= $this->display_collect_ui();

        $display_str .= "---------------------------------------------------------------------\n";
        $display_str .= "Press Ctrl-C to quit. Start success.";
        if ($this->terminate) {
            $display_str .= Console::ansiFormat("\nWait for the process exits...", [Console::FG_YELLOW]);
        }
        $this->replace_echo($display_str);
    }

    public function display_task_ui()
    {
        $display_str = '-------------------------------' . Console::ansiFormat(' TASKS ', [Console::BG_GREY, Console::FG_BLACK]) . "-------------------------------\n\n";

        $display_str .= Console::ansiFormat('taskid', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$taskid_length + 2 - strlen('taskid'));
        $display_str .= Console::ansiFormat('taskpid', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$pid_length+2-strlen('taskpid'));
        $display_str .= Console::ansiFormat('mem', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$mem_length + 2 - strlen('mem'));
        $display_str .= Console::ansiFormat('collect succ', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$urls_length-strlen('collect succ'));
        $display_str .= Console::ansiFormat('collect fail', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$urls_length-strlen('collect fail'));
        $display_str .= Console::ansiFormat('speed', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', self::$speed_length+2-strlen('speed'));
        $display_str .= "\n\n";

        $task_status = $this->getTaskStatusList($this->taskNum);

        foreach ($task_status as $json) {
            $task = json_decode($json, true);
            if (empty($task)) {
                continue;
            }
            $display_str .= str_pad($task['id'], self::$taskid_length+2);
            $display_str .= str_pad($task['pid'], self::$pid_length+2);
            $display_str .= str_pad($task['mem']."MB", self::$mem_length+2);
            $display_str .= str_pad($task['collect_succ'], self::$urls_length);
            $display_str .= str_pad($task['collect_fail'], self::$urls_length);
            $display_str .= str_pad($task['speed']."/s", self::$speed_length+2);
            $display_str .= "\n";
        }
        return $display_str;
    }

    public function display_collect_ui()
    {
        $display_str = "\n---------------------------" . Console::ansiFormat(' COLLECT STATUS ', [Console::BG_GREY, Console::FG_BLACK]) . "--------------------------\n\n";

        $display_str .= Console::ansiFormat('find pages', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', 16 - strlen('find pages'));
        $display_str .= Console::ansiFormat('queue', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', 14-strlen('queue'));
        $display_str .= Console::ansiFormat('collected', [Console::BG_GREY, Console::FG_BLACK]) . str_pad('', 15-strlen('collected'));
        $display_str .= "\n";

        $collect   = $this->getCollectUrsNum();
        $collected = $this->getCollectedUrsNum();
        $queue     = $this->getQueueSize();
        $display_str .= str_pad($collect, 16);
        $display_str .= str_pad($queue, 14);
        $display_str .= str_pad($collected, 15);
        $display_str .= "\n";
        return $display_str;
    }
}