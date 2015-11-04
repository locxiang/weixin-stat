<?php

namespace App\Libraries;

//自定义curl类
class CURL
{
    static function send($url, $headers = [], $options = [], $set=['ret'=>'body','post'=>''])
    {

        $default_headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.152 Safari/537.36'
        ];

        $default_options = [
            'follow_redirects' => false,
            'timeout' => 30
        ];

        $headers = $headers + $default_headers;
        $options = $options + $default_options;


        //出错的话就访问10次
        for ($i = 1; $i < 10; $i++) {
            \Log::debug("第{$i}次访问" . $url);
            try {
                if(isset($set['post'])&&$set['post']!="")
                    $html = \Requests::post($url, $headers,$set['post'], $options);
                else
                    $html = \Requests::get($url, $headers, $options);
            } catch (\Requests_Exception $e) {
                continue;  //表示url访问出错了
            }

            if ($html->body!="") break;  //表示访问正确
        }

        if ($set['ret'] == 'body') {
            return $html->body;
        } else {
            return $html;
        }
    }


}