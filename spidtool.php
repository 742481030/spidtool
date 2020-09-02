<?php

/修改时间 20200707 格式化
class fetch
{
    public static $headers = 'User-Agent:Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36';
    public static $cookies;
    public static $curl_opt;
    public static $proxy;

    public static $max_connect = 20;

    public static function init($opt = array())
    {
        self::$curl_opt = array(
            CURLOPT_RETURNTRANSFER => 1, //true, $head 有请求的返回值
            CURLOPT_BINARYTRANSFER => true, //返回原生的Raw输出
            CURLOPT_HEADER => true, //启用时会将头文件的信息作为数据流输出。
            CURLOPT_FAILONERROR => true, //显示HTTP状态码，默认行为是忽略编号小于等于400的HTTP信息。
            CURLOPT_AUTOREFERER => true, //当根据Location:重定向时，自动设置header中的Referer:信息。
            CURLOPT_FOLLOWLOCATION => false, //跳转
            CURLOPT_CONNECTTIMEOUT => 3, //在发起连接前等待的时间，如果设置为0，则无限等待。
            CURLOPT_TIMEOUT => 25, //设置cURL允许执行的最长秒数。
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        );
        foreach ($opt as $k => $v) {
            self::$curl_opt[$k] = $v;
        }
    }

    /**
     * fetch::get('http://www.google.com/');
     * fetch::post('http://www.google.com/', array('name'=>'foo'));.
     */
    public static function __callStatic($method, $args)
    {
        if (is_null(self::$curl_opt)) {
            self::init();
        }
        @list($request, $post_data, $callback) = $args;
        if (is_callable($post_data)) {
            $callback = $post_data;
            $post_data = null;
        }

        //single_curl
        if (is_string($request) || !empty($request['url'])) {
            $request = self::bulid_request($request, $method, $post_data, $callback);

            return self::single_curl($request);
        } elseif (is_array($request)) {
            //rolling_curl
            foreach ($request as $k => $r) {
                $requests[$k] = self::bulid_request($r, $method, $post_data, $callback);
            }

            return self::rolling_curl($requests);
        }
    }

    private static function bulid_request($request, $method = 'GET', $post_data = null, $callback = null)
    {
        //url
        if (is_string($request)) {
            $request = array('url' => $request);
        }
        empty($request['method']) && $request['method'] = $method;
        empty($request['post_data']) && $request['post_data'] = $post_data;
        empty($request['callback']) && $request['callback'] = $callback;

        return $request;
    }

    private static function bulid_ch(&$request)
    {
        // url
        $ch = curl_init($request['url']);
        // curl_opt
        $curl_opt = empty($request['curl_opt']) ? array() : $request['curl_opt'];
        $curl_opt = $curl_opt + (array) self::$curl_opt;
        // method
        $curl_opt[CURLOPT_CUSTOMREQUEST] = strtoupper($request['method']);
        // post_data
        if (!empty($request['post_data'])) {
            $curl_opt[CURLOPT_POST] = true;
            $curl_opt[CURLOPT_POSTFIELDS] = $request['post_data'];
        }
        // header
        $headers = @self::bulid_request_header($request['headers'], $cookies);
        $curl_opt[CURLOPT_HTTPHEADER] = $headers;

        // cookies
        $request['cookies'] = empty($request['cookies']) ? fetch::$cookies : $request['cookies'];
        $cookies = empty($request['cookies']) ? $cookies : self::cookies_arr2str($request['cookies']);
        if (!empty($cookies)) {
            $curl_opt[CURLOPT_COOKIE] = $cookies;
        }

        //proxy
        $proxy = empty($request['proxy']) ? self::$proxy : $request['proxy'];
        if (!empty($proxy)) {
            $curl_opt[CURLOPT_PROXY] = $proxy;
        }

        //setopt
        curl_setopt_array($ch, $curl_opt);

        $request['curl_opt'] = $curl_opt;
        $request['ch'] = $ch;

        return $ch;
    }

    private static function response($raw, $ch)
    {
        $response = (object) curl_getinfo($ch);
        $response->raw = $raw;
        //$raw = fetch::iconv($raw, $response->content_type);
        $response->headers = substr($raw, 0, $response->header_size);
        $response->cookies = fetch::get_respone_cookies($response->headers);
        fetch::$cookies = array_merge((array) fetch::$cookies, $response->cookies);
        $response->content = substr($raw, $response->header_size);

        return $response;
    }

    private static function single_curl($request)
    {
        $ch = self::bulid_ch($request);
        $raw = curl_exec($ch);
        $response = self::response($raw, $ch);
        curl_close($ch);
        if (is_callable($request['callback'])) {
            call_user_func($request['callback'], $response, $request);
        }

        return $response;
    }

    private static function rolling_curl($requests)
    {
        $master = curl_multi_init();
        $map = array();
        // start the first batch of requests
        do {
            $k = key($requests);
            $request = current($requests);
            next($requests);
            $ch = self::bulid_ch($request);
            curl_multi_add_handle($master, $ch);
            $key = (string) $ch;
            $map[$key] = array($k, $request['callback']);
        } while (count($map) < self::$max_connect && count($map) < count($requests));

        do {
            while (($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
            if ($execrun != CURLM_OK) {
                break;
            }

            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {
                $key = (string) $done['handle'];

                list($k, $callback) = $map[$key];

                // get the info and content returned on the request
                $raw = curl_multi_getcontent($done['handle']);
                $response = self::response($raw, $done['handle']);
                $responses[$k] = $response;

                // send the return values to the callback function.
                if (is_callable($callback)) {
                    $key = (string) $done['handle'];
                    unset($map[$key]);
                    call_user_func($callback, $response, $requests[$k], $k);
                }

                // start a new request (it's important to do this before removing the old one)
                $k = key($requests);
                if (!empty($k)) {
                    $k = key($requests);
                    $request = current($requests);
                    next($requests);
                    $ch = self::bulid_ch($request);
                    curl_multi_add_handle($master, $ch);
                    $key = (string) $ch;
                    $map[$key] = array($k, $request['callback']);
                    curl_multi_exec($master, $running);
                }

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $done['handle']);
            }

            // Block for data in / output; error handling is done by curl_multi_exec
            if ($running) {
                curl_multi_select($master, 10);
            }
        } while ($running);

        return $responses;
    }

    private static function bulid_request_header($headers, &$cookies)
    {
        if (is_array($headers)) {
            $headers = join(PHP_EOL, $headers);
        }
        if (is_array(self::$headers)) {
            self::$headers = join(PHP_EOL, self::$headers);
        }
        $headers = self::$headers.PHP_EOL.$headers;

        foreach (explode(PHP_EOL, $headers) as $k => $v) {
            @list($k, $v) = explode(':', $v, 2);
            if (empty($k) || empty($v)) {
                continue;
            }
            $k = implode('-', array_map('ucfirst', explode('-', $k)));
            $tmp[$k] = $v;
        }

        foreach ((array) $tmp as $k => $v) {
            if ($k == 'Cookie') {
                $cookies = $v;
            } else {
                $return[] = $k.':'.$v;
            }
        }

        return (array) $return;
    }

    public static function iconv(&$raw, $content_type)
    {
        @list($tmp, $charset) = explode('CHARSET=', strtoupper($content_type));

        if (empty($charset) && stripos($content_type, 'html') > 0) {
            preg_match('@\<meta.+?charset=([\w]+)[\'|\"]@i', $raw, $matches);
            $charset = empty($matches[1]) ? null : $matches[1];
        }

        return empty($charset) ? $raw : iconv($charset, 'UTF-8//IGNORE', $raw);
    }

    public static function get_respone_cookies($raw)
    {
        $cookies = array();
        if (strpos($raw, PHP_EOL) != false) {
            $lines = explode(PHP_EOL, $raw);
        } elseif (strpos($raw, "\r\n") != false) {
            $lines = explode("\r\n", $raw);
        } elseif (strpos($raw, '\r\n') != false) {
            $lines = explode('\r\n', $raw);
        }

        foreach ((array) $lines as $line) {
            if (substr($line, 0, 11) == 'Set-Cookie:') {
                list($k, $v) = explode('=', substr($line, 11), 2);
                list($v, $tmp) = explode(';', $v);
                $cookies[trim($k)] = trim($v);
            }
        }

        return $cookies;
    }

    public static function cookies_arr2str($arr)
    {
        $str = '';
        foreach ((array) $arr as $k => $v) {
            $str .= $k.'='.$v.'; ';
        }

        return $str;
    }
}



//修改时间2020年7.7日5.14
class onedrive
{
    public static $client_id;
    public static $client_secret;
    public static $redirect_uri;
    public static $typeurl;
    public static $oauth_url;
    public static $drivestype;
    public static $api;
    public static $api_url;
    public static $access_token;

    //验证URL，浏览器访问、授权
    public static function authorize_url()
    {
        $client_id = self::$client_id;
        $scope = urlencode('offline_access files.readwrite.all');
        $redirect_uri = self::$redirect_uri;
        $url = self::$oauth_url."/authorize?client_id={$client_id}&scope={$scope}&response_type=code&redirect_uri={$redirect_uri}";

        if ($_SERVER['HTTP_HOST'] != 'localhost') {
            $url .= '&state='.urlencode('http://'.$_SERVER['HTTP_HOST'].get_absolute_path(dirname($_SERVER['PHP_SELF'])));
        }

        return $url;
    }

    //使用 $code, 获取 $refresh_token
    public static function authorize($code = '')
    {
        $client_id = self::$client_id;
        $client_secret = self::$client_secret;
        $redirect_uri = self::$redirect_uri;

        $url = self::$oauth_url.'/token';
        $post_data = "client_id={$client_id}&redirect_uri={$redirect_uri}&client_secret={$client_secret}&code={$code}&grant_type=authorization_code";
        fetch::$headers = 'Content-Type: application/x-www-form-urlencoded';
        $resp = fetch::post($url, $post_data);
        $data = json_decode($resp->content, true);

        return $data;
    }

    //使用 $refresh_token，获取 $access_token
    public static function get_token($refresh_token)
    {
        $client_id = self::$client_id;
        $client_secret = self::$client_secret;
        $redirect_uri = self::$redirect_uri;

        $request['url'] = self::$oauth_url.'/token';
        $request['post_data'] = "client_id={$client_id}&redirect_uri={$redirect_uri}&client_secret={$client_secret}&refresh_token={$refresh_token}&grant_type=refresh_token";
        $request['headers'] = 'Content-Type: application/x-www-form-urlencoded';
        $resp = fetch::post($request);
        $data = json_decode($resp->content, true);

        return $data;
    }

    public static function access_token()
    {
        $varrr = explode('/', $_SERVER['REQUEST_URI']);
        $驱动器 = $varrr['1'];
        $配置文件 = config('@'.$驱动器);
        if ($配置文件['expires_on'] > time() + 600) {
            return $token['access_token'];
        } else {
            $refresh_token = config('refresh_token@'.$驱动器);
            $token = self::get_token($refresh_token);
            if (!empty($token['refresh_token'])) {
                $配置文件['expires_on'] = time() + $token['expires_in'];
                $配置文 = $token;

                config('@'.$驱动器, $配置文件);

                return $token['access_token'];
            }
        }

        return '';
    }

    // 生成一个request，带token
    public static function request($path = '/', $query = '')
    {
        $path = self::urlencode($path);
        $path = empty($path) ? '/' : ":/{$path}:/";
        $token = self::$access_token;
        $request['headers'] = "Authorization: bearer {$token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $request['url'] = self::$typeurl.$path.$query;

        return $request;
    }

    //返回目录信息
    public static function dir($path = '/')
    {
        $request = self::request($path, 'children?select=name,size,folder,lastModifiedDateTime,id,@microsoft.graph.downloadUrl');

        $items = array();
        self::dir_next_page($request, $items);
        //不在列表显示的文件夹
        $hide_list = explode(PHP_EOL, config('onedrive_hide'));
        if (is_array($hide_list) && count($hide_list) > 0) {
            foreach ($hide_list as $hide_dir) {
                foreach ($items as $key => $_array) {
                    if (!empty(trim($hide_dir)) && stristr($key, trim($hide_dir))) {
                        unset($items[$key]);
                    }
                }
            }
        }
        

        return $items;
    }

    //通过分页获取页面所有item
    public static function dir_next_page($request, &$items, $retry = 0)
    {
        $resp = fetch::get($request);

        $data = json_decode($resp->content, true);
        if (empty($data) && $retry < 3) {
            ++$retry;

            return self::dir_next_page($request, $items, $retry);
        }

        foreach ((array) $data['value'] as $item) {
            //var_dump($item);
            $items[$item['name']] = array(
                'name' => $item['name'],
                'id' => $item['id'],
                'size' => $item['size'],
                'lastModifiedDateTime' => strtotime($item['lastModifiedDateTime']),
                'downloadUrl' => $item['@microsoft.graph.downloadUrl'],
                'folder' => empty($item['folder']) ? false : true,
            );
        }

        if (!empty($data['@odata.nextLink'])) {
            $request = self::request();
            $request['url'] = $data['@odata.nextLink'];

            return self::dir_next_page($request, $items);
        }
    }

    //文件重命名 by github
    public static function rename($itemid, $name)
    {
        $access_token = self::$access_token;
        $api = str_replace('root', 'items/'.$itemid, self::$typeurl);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $api,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => "{\n  \"name\": \"".$name."\"\n}",
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.$access_token,
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        var_dump($response);
    }

    //文件删除 by by bygithub.com/742481030/oneindex
    public static function delete($itemid = array())
    {
        $access_token = self::$access_token;
        $apie = str_replace('root', 'items/', self::$api);

        $apis = array();

        for ($i = 0; $i < count($itemid); ++$i) {
            $apis[$i] = $apie.$itemid[$i];
        }

        $result = $res = $ch = array();
        $nch = 0;
        $mh = curl_multi_init();
        foreach ($apis as $nk => $url) {
            $timeout = 20;
            $ch[$nch] = curl_init();
            curl_setopt_array($ch[$nch], array(
                CURLOPT_URL => $url,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer '.$access_token,
                    'Content-Type: application/json',
                ),
            ));

            curl_multi_add_handle($mh, $ch[$nch]);
            ++$nch;
        }

        /* wait for performing request */

        do {
            $mrc = curl_multi_exec($mh, $running);
        } while (CURLM_CALL_MULTI_PERFORM == $mrc);

        while ($running && $mrc == CURLM_OK) {
            // wait for network
            if (curl_multi_select($mh, 0.5) > -1) {
                // pull in new data;
                do {
                    $mrc = curl_multi_exec($mh, $running);
                } while (CURLM_CALL_MULTI_PERFORM == $mrc);
            }
        }

        if ($mrc != CURLM_OK) {
            error_log('CURL Data Error');
        }

        /* get data */

        $nch = 0;

        foreach ($apis as $moudle => $node) {
            if (($err = curl_error($ch[$nch])) == '') {
                $res[$nch] = curl_multi_getcontent($ch[$nch]);
                $result[$moudle] = $res[$nch];
            } else {
                error_log('curl error');
            }

            curl_multi_remove_handle($mh, $ch[$nch]);
            curl_close($ch[$nch]);
            ++$nch;
        }

        curl_multi_close($mh);
        echo '批量处理完成';
    }

    //文件路径转itemsid by bygithub.com/742481030/oneindex
    public static function pathtoid($access_token, $path)
    {
        $request = self::request(urldecode($path));
        $request['headers'] = "Authorization: bearer {$access_token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $resp = fetch::get($request);
        $data = json_decode($resp->content, true);

        return $data['id'];
    }

    //剪切文件 by  github.com/742481030/oneindex
    public static function movepast($itemid, $newitemid)
    {
        
        
        $api = str_replace('root', 'items/'.$itemid, self::$typeurl);
       
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => "{\n  \"parentReference\": {\n    \"id\": \"".$newitemid."\"\n  }\n  \n}",
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.onedrive::$access_token,
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
        echo $id.'完成';
    }

    //通过id下载文件 by github.com/742481030/oneindex/one index
    public static function downloadbyid($itemid)
    {
        
        $token = self::$access_token;
        $api = str_replace('root', 'items/', self::$api);

        $request['headers'] = "Authorization: bearer {$token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $request['url'] = $api.$itemid;
        $resp = fetch::get($request);

        $ss = json_decode($resp->content, true)['@microsoft.graph.downloadUrl'];

        header('Location:'.$ss);
    }

    //文件批量移动 by  github.com/742481030/oneindex/oneindex
    public static function 批量移动($itemid = array(), $newitemid)
    {
        
       
        var_dump($itemid);
        $apis = array();
        $api = str_replace('root', 'items/', self::$typeurl);
        for ($i = 0; $i < count($itemid); ++$i) {
            $apis[$i] = $api.$itemid[$i];
        }

       
        $result = $res = $ch = array();
        $nch = 0;
        $mh = curl_multi_init();
        foreach ($apis as $nk => $url) {
            $timeout = 20;
            $ch[$nch] = curl_init();
            curl_setopt_array($ch[$nch], array(
                CURLOPT_URL => $url,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => "{\n  \"parentReference\": {\n    \"id\": \"".$newitemid."\"\n  }\n  \n}",
                CURLOPT_HTTPHEADER => array(
                    'Authorization: Bearer '.self::$access_token,
                    'Content-Type: application/json',
                ),
            ));

            curl_multi_add_handle($mh, $ch[$nch]);
            ++$nch;
        }

        /* wait for performing request */

        do {
            $mrc = curl_multi_exec($mh, $running);
        } while (CURLM_CALL_MULTI_PERFORM == $mrc);

        while ($running && $mrc == CURLM_OK) {
            // wait for network
            if (curl_multi_select($mh, 0.5) > -1) {
                // pull in new data;
                do {
                    $mrc = curl_multi_exec($mh, $running);
                } while (CURLM_CALL_MULTI_PERFORM == $mrc);
            }
        }

        if ($mrc != CURLM_OK) {
            error_log('CURL Data Error');
        }

        /* get data */

        $nch = 0;

        foreach ($apis as $moudle => $node) {
            if (($err = curl_error($ch[$nch])) == '') {
                $res[$nch] = curl_multi_getcontent($ch[$nch]);
                $result[$moudle] = $res[$nch];
            } else {
                error_log('curl error');
            }

            curl_multi_remove_handle($mh, $ch[$nch]);
            curl_close($ch[$nch]);
            ++$nch;
        }

        curl_multi_close($mh);
        echo '批量处理完成';
        
    }

    //获取站点id  github.com/742481030/oneindex/oneindex
    public static function get_siteidbyname($sitename, $access_token, $api_url)
    {
        $request['headers'] = "Authorization: bearer {$access_token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $request['url'] = $api_url.'/sites/root';
        $resp = fetch::get($request);
        $data = json_decode($resp->content, true);
     $hostname = $data['siteCollection']['hostname'];
        $getsiteid = $api_url.'/sites/'.$hostname.':'.$_REQUEST['site'];
        $request['url'] = $getsiteid;
        $respp = fetch::get($request);
        $datass = json_decode($respp->content, true);

        return $siteidurl = $datass['id'];
    }
public  static function getuserinfo($token,$apiurl){
    
     $request['headers'] = "Authorization: bearer {$token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $request['url'] = $apiurl;
        $resp = fetch::get($request);
        return $data = json_decode($resp->content, true);
}
    //新建文件夹 by  github.com/742481030/oneindex/oneindex
    public static function create_folder($path = '/', $name = '新建文件夹')
    {
        $path = self::urlencode($path);
        $path = empty($path) ? '/' : ":/{$path}:/";
        $api = self::$typeurl.$path.'/children';
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $api,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => "{\n  \"name\": \"".$name."\",\n  \"folder\": { },\n  \"@microsoft.graph.conflictBehavior\": \"rename\"\n}",
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer '.self::$access_token.'',
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        echo $response;
    }

    //文件缩略图链接
    public static function thumbnail($path, $size = 'large')
    {
        $request = self::request($path, "thumbnails/0?select={$size}");
        $resp = fetch::get($request);
        $data = json_decode($resp->content, true);
        $request = self::request($path, "thumbnails/0?select={$size}");

        return @$data[$size]['url'];
    }

    //分享链接
    public static function share($path)
    {
        $request = self::request($path, 'createLink');
        $post_data['type'] = 'view';
        $post_data['scope'] = 'anonymous';
        $resp = fetch::post($request, json_encode($post_data));
        $data = json_decode($resp->content, true);

        return $data;
    }

    //简单文件上传函数
    public static function upload($path, $content)
    {
        $request = self::request($path, 'content');
        $request['post_data'] = $content;
        $resp = fetch::put($request);
        $data = @json_decode($resp->content, true);

        return $data;
    }

    public static function upload_url($path, $url)
    {
        $request = self::request(get_absolute_path(dirname($path)), 'children');
        $request['headers'] .= 'Prefer: respond-async'.PHP_EOL;
        $post_data['@microsoft.graph.sourceUrl'] = $url;
        $post_data['name'] = pathinfo($path, PATHINFO_BASENAME);
        $post_data['file'] = json_decode('{}');
        $request['post_data'] = json_encode($post_data);
        $resp = fetch::post($request);
        list($tmp, $location) = explode('ocation:', $resp->headers);
        list($location, $tmp) = explode(PHP_EOL, $location);

        return trim($location);
    }

    //上传会话
    public static function create_upload_session($path)
    {
        $request = self::request($path, 'createUploadSession');
        $request['post_data'] = '{"item": {"@microsoft.graph.conflictBehavior": "fail"}}';
        $token = self::access_token();
        $resp = fetch::post($request);
        $data = json_decode($resp->content, true);
        if ($resp->http_code == 409) {
            return false;
        }

        return $data;
    }

    //分块上传
    public static function upload_session($url, $file, $offset, $length = 10240)
    {
        $token = self::access_token();
        $file_size = self::_filesize($file);
        $content_length = (($offset + $length) > $file_size) ? ($file_size - $offset) : $length;
        $end = $offset + $content_length - 1;
        $post_data = self::file_content($file, $offset, $length);

        $request['url'] = $url;
        $request['curl_opt'] = [CURLOPT_TIMEOUT => 360];
        $request['headers'] = "Authorization: bearer {$token}".PHP_EOL;
        $request['headers'] .= "Content-Length: {$content_length}".PHP_EOL;
        $request['headers'] .= "Content-Range: bytes {$offset}-{$end}/{$file_size}";
        $request['post_data'] = $post_data;
        $resp = fetch::put($request);
        $data = json_decode($resp->content, true);

        return $data;
    }

    //文件上传进度
    public static function upload_session_status($url)
    {
        $token = self::access_token();
        fetch::$headers = "Authorization: bearer {$token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $resp = fetch::get($url);
        $data = json_decode($resp->content, true);

        return $data;
    }

    //删除上传会话
    public static function delete_upload_session($url)
    {
        $token = self::access_token();
        fetch::$headers = "Authorization: bearer {$token}".PHP_EOL.'Content-Type: application/json'.PHP_EOL;
        $resp = fetch::delete($url);
        $data = json_decode($resp->content, true);

        return $data;
    }

    //获取文件信息
    public static function file_content($file, $offset, $length)
    {
        $handler = fopen($file, 'rb') or die('获取文件内容失败');
        fseek($handler, $offset);

        return fread($handler, $length);
    }

    //文件大小格式化
    public static function human_filesize($size, $precision = 1)
    {
        for ($i = 0; ($size / 1024) > 1; $i++, $size /= 1024) {
        }

        return round($size, $precision).(['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'][$i]);
    }

    //路径转码
    public static function urlencode($path)
    {
        foreach (explode('/', $path) as $k => $v) {
            if (empty(!$v)) {
                $paths[] = rawurlencode($v);
            }
        }

        return @join('/', $paths);
    }

    //文件大小
    public static function _filesize($path)
    {
        if (!file_exists($path)) {
            return false;
        }
        $size = filesize($path);

        if (!($file = fopen($path, 'rb'))) {
            return false;
        }

        if ($size >= 0) { //Check if it really is a small file (< 2 GB)
            if (fseek($file, 0, SEEK_END) === 0) { //It really is a small file
                fclose($file);

                return $size;
            }
        }

        //Quickly jump the first 2 GB with fseek. After that fseek is not working on 32 bit php (it uses int internally)
        $size = PHP_INT_MAX - 1;
        if (fseek($file, PHP_INT_MAX - 1) !== 0) {
            fclose($file);

            return false;
        }

        $length = 1024 * 1024;
        while (!feof($file)) { //Read the file until end
            $read = fread($file, $length);
            $size = bcadd($size, $length);
        }
        $size = bcsub($size, $length);
        $size = bcadd($size, strlen($read));

        fclose($file);

        return $size;
    }
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

$type=$_REQUEST["type"]?? "cn";
if ($type=="cn") {
    
    $client_id= $_REQUEST["client_id"]??"3447f073-eef3-4c60-bb68-113a86f2c39a";
    $client_secret=$_REQUEST["client_secret"]??"~b6iJ4n~HM.73XbsH3tRWn-8Vqhl.245B_";
    $oauth_url="https://login.partner.microsoftonline.cn/common/oauth2/v2.0";
    $api_url="https://microsoftgraph.chinacloudapi.cn/v1.0";
    // code...
} else {
     
    $client_id=$client_id= $_REQUEST["client_id"]??"108d697a-13e1-46b8-9761-b3005a022d5d";
    $client_secret=$_REQUEST["client_secret"]??"Bul~_2_25Dgv5030w6KC0K~2q7Q_PR-J2A";
    $oauth_url="https://login.microsoftonline.com/common/oauth2/v2.0";
    $api_url="https://graph.microsoft.com/v1.0";
}
$redirect_uri=$_REQUEST["redirect_uri"]??"https://api.mxin.ltd/";



//填写reaccesstoken
$refresh_token=$_REQUEST["refresh_token"];
$sitename=$_REQUEST["sitename"];

if($refresh_token==""){
 echo "sharepoint获取工具,没有token请新窗口打开后填写token";
  echo'  <a href="https://login.partner.microsoftonline.cn/common/oauth2/v2.0/authorize?client_id=3447f073-eef3-4c60-bb68-113a86f2c39a&scope=offline_access+files.readwrite.all+Sites.ReadWrite.All&response_type=code&redirect_uri=https://coding.mxin.ltd&state=https://coding.mxin.ltd/authorize.php"  target="_blank">授权登陆世纪互联</a><br>
    
    
    <a href="https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=108d697a-13e1-46b8-9761-b3005a022d5d&scope==offline_access+files.readwrite.all+Sites.ReadWrite.All&response_type=code&redirect_uri=https://coding.mxin.ltd&state=https://coding.mxin.ltd/authorize-us.php"  target="_blank">授权登陆国际版</a>';
    echo'<form action="" method="get">
 <p>token 国际版留空 <input type="text" name="type"  value="cn"/></p>
  <p>refresh_token 看清楚了 <input type="text" name="refresh_token" /></p>
  <p>站点名称site后面的字母<input type="text" name="sitename" /></p>
  <input type="submit" value="Submit" />
</form>



';}




        $request['url'] = $oauth_url.'/token';
        $request['post_data'] = "client_id={$client_id}&redirect_uri={$redirect_uri}&client_secret={$client_secret}&refresh_token={$refresh_token}&grant_type=refresh_token";
        $request['headers'] = 'Content-Type: application/x-www-form-urlencoded';
        $resp = fetch::post($request);
       $data = json_decode($resp->content, true);




#输出站点id
echo "cloudreve存储高级配置里把/me/drive/ 里的么替换成下面的,注意只有本人修改版本的cloudreve支持挂载sharepoint";
echo onedrive::get_siteidbyname($sitename="jane", $data["access_token"], $api_url);



