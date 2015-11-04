<?
//返回时间戳
if (!function_exists('t')) {

    function t($num = 13)
    {
        $t = time() . mt_rand(100000, 1000000);
        return substr($t, 0, $num - 1);
    }
}

//返回9位毫秒时间戳
if (!function_exists('rr')) {
    function rr()
    {
        // 14437 78005.1417       66858.5708
        $t = microtime(1);      //14437 78005.1417
        $t = substr($t, 5);      //     78005.1417
        $t = str_replace('.', '', $t); //780051417
        return $t;
    }
}
//提取字符串
if (!function_exists('str_tiqu')) {
    function str_tiqu($str, $a, $b)
    {
        if ($str == "") {
            return;
        }

        $preg = "/" . $a . "(.*?)" . $b . "/is";
        preg_match_all($preg, $str, $tmp, PREG_PATTERN_ORDER);

        if (!isset($tmp[1][0])) {
            return;
        }
        return $tmp[1][0];
    }
}


//把对象cookies转换成数组cookie
if (!function_exists('toCookies')) {
    function toCookies($cookies)
    {
        //把cookies转换为数组
        $cookies = (array)$cookies;
        $cookies = current($cookies);

        foreach ($cookies as $key => $val) {
            $cookies[$key] = $val->value;
        }

        return (object)$cookies;

    }
}


//synckey转换成url
if (!function_exists('to_url_synckey')) {
    function to_url_synckey($json)
    {
        $synckey = "";
        $tmp = json_decode($json);
        foreach ($tmp->List as $k => $v) {
            $tmp2[] = $v->Key . "_" . $v->Val;
        }
        $synckey = implode('|', $tmp2);
        return urlencode($synckey);
    }
}
