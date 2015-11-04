<?php

namespace App\Http\Controllers;

use App\Chatroom;
use App\Friends;
use App\Jobs\JobChatroom;
use App\Jobs\JobCheck;
use App\Jobs\JobMsgDeal;
use App\Libraries\CURL;
use App\Login;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;

//登录模块
class LoginController extends Controller
{

    public function getIndex($uuid = '')
    {
        if (!$uuid) {
            $uuid = $this->uuid();
        }
        return view('login.index')->with('uuid', $uuid);

    }

    //获取uuid
    public function uuid()
    {
        $html = CURL::send('https://res.wx.qq.com/zh_CN/htmledition/v2/js/webwxApp28a2f7.js');
        preg_match("/jslogin\?appid=(.*?)&redirect_uri=/i", $html, $match);

        $appid = $match[1];
        $html = CURL::send("https://login.weixin.qq.com/jslogin?appid={$appid}&redirect_uri=https%3A%2F%2Fwx.qq.com%2Fcgi-bin%2Fmmwebwx-bin%2Fwebwxnewloginpage&fun=new&lang=zh_CN&_=" . t());

        //判断是否获取二维码正常
        preg_match("/QRLogin.code = (.*?);/i", $html, $match);
        $code = $match[1];
        if ($code != 200) {
            return $html;
        }
        //获取uuid
        preg_match("/window.QRLogin.uuid = \"(.*?)\"/i", $html, $match);
        $uuid = $match[1];
        return $uuid;
    }


    //获取并显示二维码
    public function getQrcode($uuid)
    {

        //获取二维码
        $img_url = "https://login.weixin.qq.com/qrcode/" . $uuid;
        $images = CURL::send($img_url);

        //显示图片
        return response()->make($images)->header('Content-Type', 'image/jpeg');
    }


    //等待扫描
    public function getMmwebwx($uuid)
    {
        $url = "https://login.weixin.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=true&uuid={$uuid}&tip=1&r=-566163617&_=" . t();

        $body = CURL::send($url);


        $code = str_tiqu($body, 'window.code=', ';');

        $userAvatar = str_tiqu($body, "window.userAvatar = '", "\';");


        if ($code == "") {
            $code = "-1";
        }

        if ($code == '201') {
            return response()->json([
                'code' => 201,
                'userAvatar' => $userAvatar
            ]);
        }


        if ($code != "200") {
            return response()->json([
                'code' => $code
            ]);
        }


        $redirect_uri = str_tiqu($body, 'window.redirect_uri=\"', '\"');

        if ($redirect_uri) {
            return response()->json([
                'code' => 0,
                'redirect_uri' => $redirect_uri
            ]);
        } else {
            return response()->json([
                'code' => '-100',
                'msg' => $body            //获取不到跳转地址
            ]);
        }


    }


    //获取登录地址
    public function postLogin(Request $request)
    {
        $data = $request->only('url', 'uuid');

        $ret = CURL::send($data['url'], [], ['follow_redirects' => false], ['ret' => 'all']);


        //获取正真的登陆地址 //有时候需要二次跳转
        if (strstr($ret->body, "window.location.href=")) {
            $href_url = str_tiqu($ret->body, 'window.location.href=\"', '\"');
            $ret = CURL::send($href_url, [], ['follow_redirects' => false], ['ret' => 'all']);
        }

        $html = $ret->body;
        $cookies = toCookies($ret->cookies);

        //cookies为空返回错误
        if (!count((array)$cookies)) {
            return response()->json([
                'code' => 200,
                'msg' => '账户可能被封，请尝试用wx.qq.com登陆'
            ]);
        }


        $file = "user/{$cookies->wxuin}.txt";
        if (\Storage::exists($file)) {
            $user = \Storage::get($file);
            $user = json_decode($user);
        } else {
            $user = (object)[];
            $user->deviceid = "e" . mt_rand(10000, 99999) . mt_rand(10000, 99999);  //创建设备id
        }


        $user->cookies = $cookies;              //设置cookies
        $user->skey = str_tiqu($html, '<skey>', '<\/skey>');
        $user->pass_ticket = str_tiqu($html, '<pass_ticket>', '<\/pass_ticket>');


        //存储
        \Storage::put($file, json_encode($user));

        return response()->json([
            'code' => 0,
            'url' => '/login/login-ok/' . $cookies->wxuin
        ]);

    }

    public function getLoginOk($wxuin)
    {


        $file = "user/{$wxuin}.txt";
        if (\Storage::exists($file)) {
            $user = \Storage::get($file);
            $user = json_decode($user);
        } else {
            return '不存在此wxuin';
        }


        //进行post登陆尝试
        $url = "https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxinit?lang=zh_CN&pass_ticket=" . urlencode($user->pass_ticket) . "&r=-" . rr();

        $post = '{"BaseRequest":{"Uin":"' . $user->cookies->wxuin .
            '","Sid":"' . $user->cookies->wxsid .
            '","Skey":"' . $user->skey .
            '","DeviceID":"' . $user->deviceid . '"}}';
        $ret = CURL::send($url, ['Cookie' => urldecode(http_build_query($user->cookies, '', '; '))], ['follow_redirects' => false], ['ret' => 'all', 'post' => $post]);

        $cookies = toCookies($ret->cookies);
        $cookies = (object)((array)$cookies + (array)$user->cookies);

        //判断是否正常
        $data_arr = json_decode($ret->body, true);
        if (count($data_arr)) {
            if (!isset($data_arr['BaseResponse']['Ret'])) {
                $data_arr = array();
            } else if ($data_arr['BaseResponse']['Ret'] != 0) {
                return $data_arr['BaseResponse']['Ret'] . $data_arr['BaseResponse']['ErrMsg'] . '，请从新<a href="/login/">扫描</a>';
            }
        } else {
            return '没有获取到内容，请从新<a href="/login/">扫描</a>';
        }

        //开始获取基本信息了

        $data['Uin'] = $data_arr['User']['Uin'];
        $data['UserName'] = $data_arr['User']['UserName'];
        $data['NickName'] = $data_arr['User']['NickName'];
        $data['SyncKey'] = json_encode($data_arr['SyncKey']);

        $data['wxuin'] = $cookies->wxuin;
        $data['skey'] = urldecode($user->skey);
        $data['pass_ticket'] = urldecode($user->pass_ticket);
        $data['deviceid'] = $user->deviceid;
        $data['cookies'] = json_encode($cookies);
        //设置状态为可用
        $data['status'] = 1;


        Login::inSave($data);

        //删除临时文件
        \Storage::delete($file);


        //写入好友信息
        $this->ContactList($data_arr);

        //加入群信息获取队列
        $job = (new JobChatroom($data['wxuin']))->onQueue('chatroom');
        $this->dispatch($job);

        //加入监控队列
        $job = (new JobCheck($data['wxuin']))->onQueue('check');
        $this->dispatch($job);




    }

    //获取最近回话信息和好友
    public function ContactList($data_arr)
    {

        //dd($data_arr);
        foreach ($data_arr['ContactList'] as $k => $v) {

            //获取好友信息
            $data = [
                'my_uin' => $data_arr['User']['Uin'],
                'Uin' => $v['Uin'],
                'Alias' => $v['Alias'],
                'UserName' => $v['UserName'],
                'NickName' => $v['NickName'],
                'RemarkName' => $v['RemarkName'],
                'HeadImgUrl' => $v['HeadImgUrl'],
                'Sex' => $v['Sex'],
                'Signature' => $v['Signature'],
                'Province' => $v['Province'],
                'City' => $v['City'],

                //是群信息
                //'MemberCount' => $v['MemberCount'],
                //'MemberList' => json_encode($v['MemberList'])
            ];

            $fa = Friends::where('UserName', $v['UserName'])->where('my_uin', $data_arr['User']['Uin'])->first();
            //如果存在了就更新
            if ($fa) {
                Friends::where('UserName', $v['UserName'])->where('my_uin', $data_arr['User']['Uin'])->update($data);
            } else {
                $data_batch[] = $data;
            }
        }
        if (isset($data_batch)) {
            Friends::insert($data_batch);
        }
    }

    public function MsgDeal($wxuin,$msgid)
    {
        //加入消息处理队列      //在任务框架内出错,无法调用
        $job = (new JobMsgDeal($wxuin,$msgid))->onQueue('msgdeal');
        $this->dispatch($job);
    }

    //更新群信息
    public function getJobChatroom($wxuin){
        //加入群信息获取队列
        $job = (new JobChatroom($wxuin))->onQueue('chatroom');
        $this->dispatch($job);
    }


    //获取好友信息
    public function getFriends($wxuin)
    {
        //循环执行心跳
        $user = Login::where('wxuin', $wxuin)->where('status', 1)->first();
        if (!$user) {
            \Log::info("wxuin:{$wxuin} 冻结状态,结束此次循环");
            return;
        }
        $cookies = json_decode($user->cookies);

        $url = "https://webpush.weixin.qq.com/cgi-bin/mmwebwx-bin/webwxgetcontact?r=" . t();

        $ret = CURL::send($url, ['Cookie' => urldecode(http_build_query($cookies, '', '; '))], ['follow_redirects' => false], ['ret' => 'all']);

        $html = $ret->body;

        $html = iconv('UTF-8','UTF-8//IGNORE',$html);


        $cookies2 = toCookies($ret->cookies);
        $cookies = (object)((array)$cookies2 + (array)$cookies);


        //更新Cookie
        Login::where('wxuin', $wxuin)->update(['cookies' => json_encode($cookies)]);


        $data_arr = json_decode($html, true);

        foreach ($data_arr['MemberList'] as $k => $v) {

            //获取好友信息
            $data = array(
                'my_uin' => $wxuin,
                'Uin' => $v['Uin'],
                'Alias' => $v['Alias'],
                'UserName' => $v['UserName'],
                'NickName' => $v['NickName'],
                'RemarkName' => $v['RemarkName'],
                'HeadImgUrl' => $v['HeadImgUrl'],
                'Sex' => $v['Sex'],
                'Signature' => $v['Signature'],
                'Province' => $v['Province'],
                'City' => $v['City']

            );

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

        return $data_arr;

    }




}
