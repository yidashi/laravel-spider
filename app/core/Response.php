<?php
/**
 * Created by PhpStorm.
 * Author: yidashi
 * DateTime: 2017/5/23 11:37
 * Description:
 */

namespace core;


use Symfony\Component\DomCrawler\Crawler;
use yii\db\Query;

class Response extends \Symfony\Component\BrowserKit\Response
{
    private $crawler;

    private $source;

    public function __construct($content = '', $status = 200, array $headers = array(), $source = 0)
    {
        parent::__construct($content, $status, $headers);
        $this->source = $source;
        $this->crawler = new Crawler();
        $this->crawler->addContent($content, $this->getHeader('Content-Type'));
    }

    public function getCrawler()
    {
        return $this->crawler;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function setSource($source)
    {
        $this->source = $source;
    }

    public function filter($selector)
    {
        return $this->crawler->filter($selector);
    }

    public function filterXPath($xpath)
    {
        return $this->crawler->filterXPath($xpath);
    }

    /**
     * 获得完整的连接地址
     *
     * @param mixed $url            要检查的URL
     * @return mixed
     */
    public function fillUrl($url)
    {
        $url = trim($url);
        // 从那个URL页面得到上面的URL

        $collectUrl = (new Query())->from('c_source')->where(['id' => $this->source])->select('url')->scalar();

        // 排除JavaScript的连接
        //if (strpos($url, "javascript:") !== false)
        if( preg_match("@^(javascript:|#|'|\")@i", $url) || $url == '') {
            return false;
        }
        // 排除没有被解析成功的语言标签
        if(substr($url, 0, 3) == '<%=') {
            return false;
        }

        $parse_url = @parse_url($collectUrl);
        if (empty($parse_url['scheme']) || empty($parse_url['host'])) {
            return false;
        }
        // 过滤mailto、tel、sms、wechat、sinaweibo、weixin等协议
        if (!in_array($parse_url['scheme'], ['http', 'https'])) {
            return false;
        }
        $scheme = $parse_url['scheme'];
        $domain = $parse_url['host'];
        $path = empty($parse_url['path']) ? '' : $parse_url['path'];
        $base_url_path = $domain.$path;
        $base_url_path = preg_replace("/\/([^\/]*)\.(.*)$/","/",$base_url_path);
        $base_url_path = preg_replace("/\/$/",'',$base_url_path);

        $i = $path_step = 0;
        $dstr = $pstr = '';
        /*$pos = strpos($url,'#');
        if($pos > 0) {
            // 去掉#和后面的字符串
            $url = substr($url, 0, $pos);
        }*/

        // 京东变态的都是 //www.jd.com/111.html
        if(substr($url, 0, 2) == '//') {
            $url = str_replace('//', '', $url);
        }
        // /1234.html
        elseif($url[0] == '/') {
            $url = $domain.$url;
        }
        // ./1234.html、../1234.html 这种类型的
        elseif($url[0] == '.') {
            if(!isset($url[2])) {
                return false;
            } else {
                $urls = explode('/',$url);
                foreach($urls as $u) {
                    if( $u == '..' ) {
                        $path_step++;
                    }
                    // 遇到 ., 不知道为什么不直接写$u == '.', 貌似一样的
                    else if( $i < count($urls)-1 ) {
                        $dstr .= $urls[$i].'/';
                    } else {
                        $dstr .= $urls[$i];
                    }
                    $i++;
                }
                $urls = explode('/',$base_url_path);
                if(count($urls) <= $path_step) {
                    return false;
                } else {
                    $pstr = '';
                    for($i=0;$i<count($urls)-$path_step;$i++){ $pstr .= $urls[$i].'/'; }
                    $url = $pstr.$dstr;
                }
            }
        } else {
            if( strtolower(substr($url, 0, 7))=='http://' ) {
                $url = preg_replace('#^http://#i','',$url);
                $scheme = 'http';
            } else if( strtolower(substr($url, 0, 8))=='https://' ) {
                $url = preg_replace('#^https://#i','',$url);
                $scheme = 'https';
            } else {
                $url = $base_url_path.'/'.$url;
            }
        }
        // 两个 / 或以上的替换成一个 /
        $url = preg_replace('@/{1,}@i', '/', $url);
        $url = $scheme.'://'.$url;
        //echo $url;exit("\n");

        return $url;
    }

    /**
     *
     * @param $url
     * @return Link
     */
    public function createLink($url)
    {
        $url = $this->fillUrl($url);
        $sourceUrl = (new Query())->from('c_source')->where(['id' => $this->source])->select('url')->scalar();
        return Link::create($url, ['referer' => $sourceUrl]);
    }
}