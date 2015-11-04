<?php

namespace App\Jobs;

use App\Http\Controllers\LoginController;
use App\Jobs\Job;
use App\Libraries\CURL;
use App\Login;
use App\Msglist;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

class JobCheck extends Job implements SelfHandling, ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public $wxuin;

    public function __construct($wxuin)
    {
        $this->wxuin = $wxuin;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {


        //读取好友和群消息
        $login = new LoginController();
        $login->getFriends($this->wxuin);

        do {

            //循环执行心跳
            $user = Login::where('wxuin', $this->wxuin)->where('status', 1)->first();
            if (!$user) {
                \Log::info("wxuin:{$this->wxuin} 冻结状态,结束此次循环");
                return;
            }
            $cookies = json_decode($user->cookies);


            $url = "https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/synccheck?r=" . t() .
                "&skey=" . urlencode($user->skey) .
                "&sid=" . urlencode($cookies->wxsid) .
                "&uin=" . urlencode($user->Uin) .
                "&deviceid=" . urlencode($user->deviceid) .
                "&synckey=" . to_url_synckey($user->SyncKey) .
                "&_=" . t();


            $ret = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; ')) ], ['follow_redirects' => false], ['ret' => 'all']);

            $html = $ret->body;


            $cookies2 = toCookies($ret->cookies);
            $cookies = (object)((array)$cookies2 + (array)$cookies);

            $tmp = [
                'url' => $url,
                'cookie' => urldecode(http_build_query($cookies, '', '; ')),
                'html' => $html,
                'ret_cookies' => (array)$cookies2
            ];

            \Log::info('心跳包:',$tmp);


            $data['retcode'] = str_tiqu($html, 'retcode:\"', '\"');
            $data['selector'] = str_tiqu($html, 'selector:\"', '\"');

            //判断消息
            $this->retcode($data);

            \DB::reconnect(); //确保获取了一个新的连接。
        } while (1);
    }


    //判断信息
    function retcode($data)
    {
        if (count($data['selector']) && count($data['retcode']) && $data['retcode'] == '0') {

        } else {
            $this->death();
        }

        //大于0表示有消息
        if ($data['selector'] > 0) {
            \Log::info("{$this->wxuin} 有消息来啦");
            $this->webwxsync();
        } else {
            \Log::info("{$this->wxuin} 暂无新消息");
        }
    }

    //读取消息
    function webwxsync()
    {

        $user = Login::where('wxuin', $this->wxuin)->where('status', 1)->first();
        if (!$user) {
            $this->death('读取消息失败,wxuin已被冻结');
        }
        $cookies = json_decode($user->cookies);

        $url = "https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsync?sid=".urlencode($cookies->wxsid) .
            "&skey=".urlencode($user->skey) .
            "&lang=zh_CN".
            "&pass_ticket=".urlencode($user->pass_ticket);
        $post = '{"BaseRequest":{"Uin":'.$user->Uin.',"Sid":"'.$cookies->wxsid.'","Skey":"'.$user->skey.'","DeviceID":"'.$user->deviceid.'"},"SyncKey":'.$user->SyncKey.',"rr":-'.rr().'}';
        $ret = CURL::send($url, ['Cookie' =>urldecode(http_build_query($cookies, '', '; ')) ], ['follow_redirects' => false], ['ret' => 'all','post'=>$post]);


        $html = $ret->body;

        $cookies2 = toCookies($ret->cookies);
        $cookies = (object)((array)$cookies2 + (array)$cookies);

        //更新Cookie
        Login::where('wxuin',$this->wxuin)->update(['cookies'=>json_encode($cookies)]);

        $data_arr = $this->post_check($html);       //判断数据包是否正常


        \Log::info('接收到消息:',$data_arr);

        //读取消息
        if($data_arr['AddMsgCount']>0){
            foreach ($data_arr['AddMsgList'] as $k => $v) {

                echo json_encode($v)."\r\n";

                if($v['MsgType']==51||$v['Content']==""){
                    //51好像没什么用,可能是正在输入的意思
                    continue;
                }

                $msg = Msglist::where('MsgId', $v['MsgId'])->first();
                if($msg){
                    continue;   //如果存在就抛弃
                }

                $data['MsgId'] =$v['MsgId'];
                $data['FromUserName'] =$v['FromUserName'];
                $data['ToUserName'] =$v['ToUserName'];
                $data['MsgType'] =$v['MsgType'];
                $data['Content'] =$v['Content'];
                $data['Status'] =$v['Status'];
                $data['ImgStatus'] =$v['ImgStatus'];
                $data['CreateTime'] =$v['CreateTime'];

                $data['time_y'] = date('Y',$v['CreateTime']);
                $data['time_m'] = date('m',$v['CreateTime']);
                $data['time_d'] = date('d',$v['CreateTime']);
                $data['time_h'] = date('H',$v['CreateTime']);


                $data['my_uin']  = $this->wxuin;


                Msglist::insert($data);

                //加入消息处理队列    在本框架内出错,无法调用
                $msg = new LoginController();
                $msg->MsgDeal($this->wxuin,$v['MsgId']);

            }
        }



        //处理SyncKey
        if($data_arr['SyncKey']['Count']>0){
            $l['SyncKey'] =json_encode($data_arr['SyncKey']);
            Login::where('wxuin',$this->wxuin)->update($l);

        }

        //处理SKey
        if($data_arr['SKey']!=""){
            Login::where('wxuin',$this->wxuin)->update('skey',$data_arr['SKey']);
        }

    }

    //判断数据包正常否
    function post_check($html)
    {
        //判断是否正常
        $data_arr = json_decode($html, true);
        if (count($data_arr)) {
            if (!isset($data_arr['BaseResponse']['Ret'])) {
                $this->death('BaseResponse.ret:不存在');
            } else if ($data_arr['BaseResponse']['Ret'] != 0) {
                $this->death('BaseResponse.ret:'.$data_arr['BaseResponse']['Ret']);
            }
        } else {
            $this->death();
        }
        return $data_arr;
    }

    //死亡
    function death($msg="")
    {
        echo "wxuin:{$this->wxuin} is death";
        if($msg) \Log::info($msg);
        Login::where('wxuin', $this->wxuin)->update(['status' => 0]);
        abort(500);
    }
}
