<?php

namespace App\Jobs;

use App\Jobs\Job;
use App\Libraries\CURL;
use App\Login;
use App\Msglist;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

//消息处理
class JobMsgDeal extends Job implements SelfHandling, ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public $msgid, $wxuin;

    public function __construct($wxuin, $msgid)
    {
        $this->msgid = $msgid;
        $this->wxuin = $wxuin;
    }


    public function handle()
    {
        echo "msgid:{$this->msgid}  处理*****\r\n";
        $msg = Msglist::where('MsgId', $this->msgid)->first()->toArray();
        if (!count($msg)) {
            $this->death('消息已经失效');
        }


        if ($msg['MsgType'] == 3) {                                                 //消息为图片
            $info = $this->pub_img($msg);
            //反馈消息
            $this->webwxsendmsg($msg['FromUserName'],$info);

        } else if ($msg['ToUserName'] == 'filehelper') {                            //消息为发送给自己的文件助手
            //$info = $this->cmd($msg['Content']);
        } else if (strstr($msg['FromUserName'], "@@")) {                            //群消息
            if ($msg['MsgType'] == 1) {                                               //群普通消息
                //$info = $this->room($msg);
            }
        } else if ($msg['MsgType'] == 1) {                                                                //普通文本消息
            // $info = $this->tuling($msg['Content'], $msg['FromUserName']);
        } else if ($msg['MsgType'] == 10000 && $msg['Content'] == '收到红包，请在手机上查看') {
            //红包
        } else if ($msg['MsgType'] == 10002) {
            //撤回消息
        } else {
            //其他特殊情况
            $info = "";
        }




    }

    //下载图片
    public function pub_img($msg)
    {
        $user = Login::where('wxuin', $this->wxuin)->where('status', 1)->first();
        if (!$user) {
            $this->death();
        }
        $cookies = json_decode($user->cookies);

        $url = "https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/webwxgetmsgimg?MsgID=" . $msg['MsgId'] .
            "&skey=" . urlencode($user->skey) .
            "&__r=-" . t();

        $img = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; '))], [], ['ret' => 'body']);

        \Storage::put('img/' . $msg['MsgId'] . ".jpg", $img);

        echo "put_img: img/" . $msg['MsgId'] . ".jpg\r\n";

        return '已接收到图片';

    }


    //发送消息
    public function webwxsendmsg($ToUserName, $info)
    {

        $user = Login::where('wxuin', $this->wxuin)->where('status', 1)->first();
        if (!$user) {
            $this->death();
        }
        $cookies = json_decode($user->cookies);

        dd($user);

        $ClientMsgId = time();
        $LocalID = $ClientMsgId;

        $url = "https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/webwxsendmsg?sid=" . urlencode($cookies->wxsid) .
            "&skey=" . urlencode($cookies->skey) .
            "&r=" . t() .
            "&pass_ticket=" . urlencode($user->pass_ticket);


        $post['BaseRequest']['DeviceID'] = $user->deviceid;
        $post['BaseRequest']['Sid'] = $cookies->wxsid;
        $post['BaseRequest']['Skey'] = $user->skey;
        $post['BaseRequest']['Uin'] =  (int)$cookies->wxuin;


        $post['Msg']['FromUserName'] =$user->UserName;
        $post['Msg']['ToUserName'] =$ToUserName;
        $post['Msg']['Type'] =1;
        $post['Msg']['Content'] =$info;
        $post['Msg']['ClientMsgId'] =$ClientMsgId;
        $post['Msg']['LocalID'] =$LocalID;


        dd($post);


        $html = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; '))], [], ['ret' => 'body','post'=>json_encode($post)]);

        dd($html);

    }

    //死亡
    function death($msg = "")
    {
        if ($msg) \Log::info($msg);
        //Login::where('wxuin', $this->wxuin)->update(['status' => 0]);
        echo "wxuin:{$this->wxuin} is death";
        abort(500);
    }

}
