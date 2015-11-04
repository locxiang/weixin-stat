<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>小微</title>


    {!! Html::style('//cdn.bootcss.com/bootstrap/3.3.5/css/bootstrap-theme.min.css') !!}
    {!! Html::style('//cdn.bootcss.com/bootstrap/3.3.5/css/bootstrap.min.css') !!}
</head>


<body>

<div style="height: 100px;"></div>

<div class="container">
    <div class="row clearfix">
        <div class="col-md-4 column">
            <dl>
                <dt>
                    有趣小微
                </dt>
                <dd>
                    这是一个可以自动记录您微信上聊天记录,好友信息的系统
                </dd>
                <dt>
                    功能
                </dt>
                <dd>
                    可以统计,记录,发送,自动回复
                </dd>
                <dt>
                    使用步骤
                </dt>
                <dd>
                    1.打开微信扫一扫
                </dd>
                <dd>
                    2.扫描二维码
                </dd>
                <dd>
                    3.点击确认按钮
                </dd>
                <dd>
                    4.进入控制台页面
                </dd>
            </dl>
        </div>
        <div class="col-md-4 column">
            <img id="qrcode" style="width: 360px;height: 360px;" src="/login/qrcode/{{$uuid}}" alt="微信二维码"
                 class="img-thumbnail">
        </div>
        <div class="col-md-4 column">
            <div class="alert alert-info" id="alert-info">

                <strong>提示！</strong>请按照左边的提示进行操作
            </div>
        </div>
    </div>
</div>

</body>

{!! Html::script('//apps.bdimg.com/libs/jquery/2.1.4/jquery.min.js') !!}
{!! Html::script('//cdn.bootcss.com/bootstrap/3.3.5/js/bootstrap.min.js') !!}
<script type="text/javascript">


    $(window).load(function () {
        mm();
    });

    function mm() {
        $.get('/login/mmwebwx/{{$uuid}}', function (json) {
            if (json.code === 0) {
                console.log('扫描成功:' + json);
                login(json);
            } else if (json.code == 201) {
                $('#alert-info').removeClass('alert-info');
                $('#alert-info').addClass('alert-success');
                $('#alert-info').html('<strong>提示！</strong>扫描成功,请点击确认');
                $('#qrcode').attr('src',json.userAvatar);
                mm();
            } else {
                console.log('执行mmwebwx:' + json);
                mm();
            }
        }, 'json');
    }

    //进行登陆
    function login(json) {
        $.post('/login/login', {
                    'url': json.redirect_uri,
                    'uuid': '{{$uuid}}',
                    '_token':'{{csrf_token()}}'
                },
                function (json) {
                    if(json.code===0){
                        window.location.href=json.url;
                    }else{
                        $('#alert-info').removeClass('alert-success');
                        $('#alert-info').addClass('alert-warning');
                        $('#alert-info').html('<strong>提示！</strong>'+json.msg);
                        alert(json.msg);
                    }
                },'json');

    }
</script>
</html>

