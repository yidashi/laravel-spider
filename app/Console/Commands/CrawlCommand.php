<?php

namespace App\Console\Commands;

use core\Core;
use Illuminate\Console\Command;

class CrawlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spider:run {spiderName : 蜘蛛名} {--taskNum=10 : 同时开启多少子进程用来抓取页面} {--debug=false : 是否开启调试}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '执行蜘蛛';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        ini_set('memory_limit', '1024M');
        $config = [
            'taskNum' => $this->option('taskNum'),
            'showLog' => $this->option('debug'),
        ];
        (new Core($this->argument('spiderName'), $config))->run();
    }
}
