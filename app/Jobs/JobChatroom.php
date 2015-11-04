<?php

namespace App\Jobs;

use App\Chatroom;
use App\Friends;
use App\Jobs\Job;
use App\Libraries\CURL;
use App\Login;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

class JobChatroom extends Job implements SelfHandling, ShouldQueue
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
        ini_set('memory_limit','-1');

        echo "$this->wxuin 的群信息更新";


        $this->getChatroom($this->wxuin);  //获取群消息

        $this->getChatroomFriends($this->wxuin); //获取群友信息


    }


    //获取群数据
    public function getChatroom($wxuin)
    {

        //循环执行心跳
        $user = Login::where('wxuin', $wxuin)->where('status', 1)->first();
        if (!$user) {
            $this->death();

        }
        $cookies = json_decode($user->cookies);


        $url = 'https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/webwxbatchgetcontact?type=ex&r=' . t();


        $post['BaseRequest']['DeviceID'] = $user->deviceid;
        $post['BaseRequest']['Sid'] = $cookies->wxsid;
        $post['BaseRequest']['Skey'] = $user->skey;
        $post['BaseRequest']['Uin'] =  (int)$cookies->wxuin;

        //获取群列表
        $Chatroom = Friends::where('UserName','like','@@%')->where('my_uin',$wxuin)->get();
        if(!count($Chatroom)){
            $this->death('获取不到群信息');
        }

        $post['Count'] = count($Chatroom);
        foreach (json_decode($Chatroom) as $k => $v) {
            $post['List'][$k]['UserName'] = $v->UserName;
            $post['List'][$k]['EncryChatRoomId'] = '';
        }


        $ret = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; '))], [], ['ret' => 'all','post'=>json_encode($post)]);
        $html = $ret->body;
        $cookies2 = toCookies($ret->cookies);
        $cookies = (object)((array)$cookies2 + (array)$cookies);

        $html = iconv('UTF-8','UTF-8//IGNORE',$html);

        $data_arr = json_decode($html,true);



        foreach ($data_arr['ContactList'] as $k => $v) {

            //获取好友信息
            $data = [
                'my_uin' => $wxuin,
                'UserName' => $v['UserName'],
                'NickName' => $v['NickName'],
                'HeadImgUrl' => $v['HeadImgUrl'],
                'OwnerUin' => $v['OwnerUin'],
                'EncryChatRoomId' => $v['EncryChatRoomId'],
                'MemberCount' => $v['MemberCount'],
                // 'MemberList' => json_encode($v['MemberList'])
            ];

            //处理群好友信息
            foreach($v['MemberList'] as $_k => $_v){
                $data_room = [
                    'EncryChatRoomId' => $v['EncryChatRoomId'],
                    'UserName' => $_v['UserName'],
                    'AttrStatus' => $_v['AttrStatus'],
                    'NickName' => $_v['NickName'],
                    'Uin' => $_v['Uin'],
                ];
                $rm = Chatroom::where('EncryChatRoomId', $v['EncryChatRoomId'])->where('UserName',$_v['UserName'])->first();
                //如果存在了就更新
                if ($rm) {
                    Chatroom::where('EncryChatRoomId', $v['EncryChatRoomId'])->where('UserName',$_v['UserName'])->update($data_room);
                } else {
                    $data_room_batch[] = $data_room;
                }
            }
            if (isset($data_room_batch)) {
                Chatroom::insert($data_room_batch);
                unset($data_room_batch);
            }


            $ff = Friends::where('UserName', $v['UserName'])->first();
            //如果存在了就更新
            if ($ff) {
                Friends::where('UserName', $v['UserName'])->update($data);
            } else {
                $data_batch[] = $data;
            }
        }
        if (isset($data_batch)) {
            Friends::insert($data_batch);
        }
    }

    //获取群友数据
    public function getChatroomFriends($wxuin){

        set_time_limit(0);

        //循环执行心跳
        $user = Login::where('wxuin', $wxuin)->where('status', 1)->first();
        if (!$user) {
            $this->death();
        }
        $cookies = json_decode($user->cookies);





        $post['BaseRequest']['DeviceID'] = $user->deviceid;
        $post['BaseRequest']['Sid'] = $cookies->wxsid;
        $post['BaseRequest']['Skey'] = $user->skey;
        $post['BaseRequest']['Uin'] =  (int)$cookies->wxuin;

        //获取群列表
        $Chatroom = Friends::where('UserName','like','@@%')->where('my_uin',$wxuin)->get();
        if(!count($Chatroom)){
            $this->death("获取不到群信息");
        }

        //循环获取所有群友信息
        foreach (json_decode($Chatroom) as $k => $v) {
            $Chatroom_friends = Chatroom::where('EncryChatRoomId',$v->EncryChatRoomId)->get();
            $i = 0;
            //分批次获取群友信息
            foreach($Chatroom_friends as $_k => $_v){
                $post['List'][$i]['EncryChatRoomId'] = $v->UserName;        //群id
                $post['List'][$i]['UserName'] =$_v->UserName;
                if(++$i==50){
                    $post['Count'] = $i;
                    $i=0;
                    $this->putChatroomFriends($wxuin,$post);
                    unset($post['List']);
                }
            }

            if($i!=0){
                $post['Count'] = $i;
                $this->putChatroomFriends($wxuin,$post);
                unset($post['List']);
            }
        }
    }



    //发送50个群友消息请求
    public function putChatroomFriends($wxuin,$post){
        \DB::reconnect(); //确保获取了一个新的连接。


        //循环执行心跳
        $user = Login::where('wxuin', $wxuin)->where('status', 1)->first();
        if (!$user) {
           $this->death();
        }
        $cookies = json_decode($user->cookies);


        $url = 'https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/webwxbatchgetcontact?type=ex&r='.t();


        $ret = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; '))], [], ['ret' => 'all','post'=>json_encode($post)]);
        $html = $ret->body;
        $cookies2 = toCookies($ret->cookies);
        $cookies = (object)((array)$cookies2 + (array)$cookies);

        $html = iconv('UTF-8','UTF-8//IGNORE',$html);

        $data_arr = json_decode($html,true);



        foreach ($data_arr['ContactList'] as $k => $v) {
            //获取好友信息
            $data_room = [
                'EncryChatRoomId' => $v['EncryChatRoomId'],
                'Uin' => $v['Uin'],
                'UserName' => $v['UserName'],
                'NickName' => $v['NickName'],
                'HeadImgUrl' => $v['HeadImgUrl'],
                'RemarkName' => $v['RemarkName'],
                'Sex' => $v['Sex'],
                'Signature' => $v['Signature'],
                'AttrStatus' => $v['AttrStatus'],
                'Province' => $v['Province'],
                'City' => $v['City']
            ];

            $rm = Chatroom::where('EncryChatRoomId', $v['EncryChatRoomId'])->where('UserName',$v['UserName'])->first();
            //如果存在了就更新
            if ($rm) {
                Chatroom::where('EncryChatRoomId', $v['EncryChatRoomId'])->where('UserName',$v['UserName'])->update($data_room);
            } else {
                $data_room_batch[] = $data_room;
            }
        }
        if (isset($data_room_batch)) {
            Chatroom::insert($data_room_batch);
        }
    }


    //死亡
    function death($msg="")
    {
        if($msg) \Log::info($msg);
        Login::where('wxuin', $this->wxuin)->update(['status' => 0]);
        echo "wxuin:{$this->wxuin} is death";
        abort(500);
    }
}
