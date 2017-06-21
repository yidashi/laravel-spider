<?php

namespace App\Console\Commands;

use App\Helpers\File;
use Illuminate\Console\Command;

class SpiderCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spider:create {name : 蜘蛛名}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '添加蜘蛛';

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
        $description = $this->ask('输入蜘蛛的说明：');
        $name = $this->argument('name');
        $spiderDirs = explode('/', $name);

        if (count($spiderDirs) > 1) {
            $spiderClassShortName = ucfirst($spiderDirs[count($spiderDirs)-1]) . 'Spider';
            unset($spiderDirs[count($spiderDirs) - 1]);
            $spiderNamespace = $this->spiderNamespace . join('\\', $spiderDirs);
            $spiderFullFileName = $this->spiderDir . '/' . join('/', $spiderDirs) . '/' . $spiderClassShortName . '.php';
        } else {
            $spiderNamespace = $this->spiderNamespace;
            $spiderClassShortName = ucfirst($name) . 'Spider';
            $spiderFullFileName = $this->spiderDir . '/' . $spiderClassShortName . '.php';
        }
        $dir = pathinfo($spiderFullFileName, PATHINFO_DIRNAME);
        if (!is_dir($dir)) {
            File::createDirectory($dir);
        }
        file_put_contents($spiderFullFileName, <<<php
<?php
namespace {$spiderNamespace};

use core\Spider;
use core\Response;

class {$spiderClassShortName} extends Spider
{
    public \$name = '{$name}';
    
    public \$description = '{$description}';
    
    public \$startUrls = [];
    
    public function parse(Response \$response)
    {
    
    }
}
php
        );
        $this->info("创建成功\n");
    }
}
