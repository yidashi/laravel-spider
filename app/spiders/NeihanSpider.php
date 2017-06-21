<?php
namespace app\spiders;

use core\Link;
use core\Request;
use core\Spider;
use core\Response;
use DB;
use Symfony\Component\DomCrawler\Crawler;

class NeihanSpider extends Spider
{
    public $name = 'neihan';
    
    public $description = '内涵段子';
    
    public $startUrls = ['http://neihanshequ.com/'];
    
    public function parse(Response $response)
    {
        $crawler = $response->getCrawler();
        $html = $response->getContent();
        preg_match("/max_time: '(\d{10}(\.\d+)?)',/", $html, $matches);
        $maxTime = $matches[1];
        // 加载更多 http://neihanshequ.com/joke/?is_json=1&app_name=neihanshequ_web&max_time=1498032103.8100002
        Request::create(Link::create('http://neihanshequ.com/joke/?is_json=1&app_name=neihanshequ_web&max_time=' . $maxTime), [$this, 'parseMore']);
        //分析当前页
        $crawler->filter('#detail-list>li')->each(function (Crawler $liCrawler) {
            $author = $liCrawler->filter('.header .name')->text();
            $body = $liCrawler->filter('.content-wrapper .upload-txt p')->text();
            $this->export($author, $body);
        });




    }

    public function parseMore(Response $response)
    {
        $json = json_decode($response->getContent(), true);
        $hasMore = $json['data']['has_more'];
        $data = $json['data']['data'];
        $maxTime = $json['data']['max_time'];
        if ($hasMore) {
            Request::create(Link::create('http://neihanshequ.com/joke/?is_json=1&app_name=neihanshequ_web&max_time=' . $maxTime), [$this, 'parseMore']);
        }
        foreach ($data as $item) {
            $author = $item['group']['user']['name'];
            $body = $item['group']['text'];
            $this->export($author, $body);
        }
    }

    private function export($author, $body)
    {
        DB::table('neihan')->insert([
            'author' => $author,
            'body' => $body
        ]);
    }
}