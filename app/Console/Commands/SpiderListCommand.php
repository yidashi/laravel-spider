<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SpiderListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spider:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '蜘蛛列表';

    public $spiderDir = 'spiders';

    public $spiderNamespace = 'app\spiders\\';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->spiderDir = app_path('spiders');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $directory = new \RecursiveDirectoryIterator($this->spiderDir);
        $iterator = new \RecursiveIteratorIterator($directory);
        $spiders = [];
        foreach ($iterator as $name => $item) {
            if ($item->isFile()) {
                $spiderFullFileName = $item->getPathname();
                $spiderFileName = substr($spiderFullFileName, strpos($spiderFullFileName, $this->spiderDir)+strlen($this->spiderDir));
                if (preg_match('/\/(\w+\/)?(\w+)Spider\.php/', $spiderFileName, $matches) > 0) {
                    $spiderName = $matches[1] . strtolower($matches[2]);
                    $spiderClassName = $this->spiderNamespace . str_replace('/', '\\', $spiderName) . 'Spider';
                    $spiderClass = new $spiderClassName;
                    if ($spiderClass->enabled) {
                        $spiders[] = [
                            'name' => $spiderClass->name,
                            'description' => $spiderClass->description,
                        ];
                    }
                }

            }
        }
        $len = 0;
        foreach ($spiders as $spider) {
            if (strlen($spider['name']) > $len) {
                $len = strlen($spider['name']);
            }
        }
        $this->info("\n下面的蜘蛛可用:\n\n");
        foreach ($spiders as $spider) {
            $this->warn('- ' . $spider['name'] . str_repeat(' ', $len + 4 - strlen($spider['name'])) . $spider['description']);
        }
        $this->info("\n");
    }
}
