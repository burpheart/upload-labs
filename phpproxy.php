<?php

# edit this

    # set this to your client machine or proxy,
    # comment it out if you don't want protection
    $allow_ip = '10.20.30.40';
    # define this if you want PHPProxy not to connect
    # directly, but use parent proxy instead
   # $proxy_host = 'proxy.ml.lv';
   # $proxy_port = 8080;
    $proxy_host = '';
    $proxy_port = 0;
    # write debug info into server error_log
   # $debug = 1;
    $debug = 0;

# end of config

    if (!empty($allow_ip) && $REMOTE_ADDR != $allow_ip)
        exit;

function debug ($msg) {
    global $debug;
    if ($debug) error_log('phpproxy: '. $msg, 0);
}

    unset($url);
    $strip_header = 0;
    $set_content_type = 0;
    if ($REQUEST_METHOD == 'GET') {
        debug("GET request");
        # just page with inputs
        if (!isset($_GET['url'])) {
            debug("show page");
?>
<html><body>
<form method="POST">
<input type="text" name="url" size="70">
<input type="submit" name="get" value="Download">
<input type="submit" name="clickme" value="Get a link">
</form>
</body></html>
<?php
            exit;
        
        # url is hex encoded real url
        } else {
            debug("download by encoded url");
            $url = pack("H*", $_GET['url']);
            $strip_header = 1;
            debug("decoded url: $url");
        }
    } else if ($REQUEST_METHOD == 'POST') {
        debug("POST request");
        
        # just proceed to download
        if (isset($_POST['get'])) {
            debug("download by POST'ed url");
            $url = $_POST['url'];
            $strip_header = 1;
            $set_content_type = 1;
        
        # create obfuscated link end exit
        } else if (isset($_POST['clickme'])) {
            debug("create hex encoded link");
            $url = $_POST['url'];
            list (, $hex) = unpack('H'. strlen($url)*2, $url);
            $link = $_SERVER['PHP_SELF'] .'?url='. $hex;
            #$name = substr(strrchr($url, "/"), 1);
?>
<html><body>
<a href=<?php echo $link; ?>>Right-click me</a> and Save (Link) Target As <b><?php #echo $name; ?></b>
</body></html>
<?php
            exit;
        
        # regular request from client proxy
        } else {
            debug("request from client program");
            $req = $_POST['req'];
        }
    } else {
        debug("unknown request");
        exit;
    }

    # create a request from url
    if (isset($url)) {
        debug("url is set");
        $url_c = parse_url($url);
        $req = "GET $url HTTP/1.1\r\n" .
               'Host: ' . $url_c['host'] . ($url_c['port'] ? ':'. $url_c['port'] : '') .
               "\r\nConnection: close\r\n\r\n";
        debug("req: $req");

    # kill keep-alives in client request
    } else {
        debug("req1: $req");
        debug('setting "connection: close" header');
        $nlnl = strpos($req, "\r\n\r\n");
        if (!$nlnl) $nlnl = strpos($req, "\n\n");
        if (!$nlnl) { debug("can't find end of headers in request"); exit; }
        $headers = substr($req, 0, $nlnl);
        $headers = preg_replace('/^Keep-Alive:.*?(\n|$)/ims', '', $headers, 1);
        $headers = preg_replace('/^(Proxy-)?Connection:.*?(\n|$)/ims', '', $headers, 1);
        $headers .= "\r\n". (!empty($proxy_host) ? 'Proxy-' : '') .'Connection: close';
        $req = $headers . substr($req, $nlnl);
        debug("req2: $req");
    }
   
    debug("choose method");
    if (empty($proxy_host)) {
        debug("direct");
        $nl = strpos($req, "\n");
        $headl = substr($req, 0, $nl);
        if(!preg_match('/(\w+)\s+(\S+)(.*)/', $headl, $matches)) {
            debug("parse req !preg_match()");
            exit;
        }
        $url = parse_url($matches[2]);
        $host = $url["host"];
        $port = $url["port"] ? $url["port"] : 80;
        $req = $matches[1] ." ".
               ($url["path"] ? $url["path"] : '/') .
               ($url["query"] ? "?". $url["query"] : '') .
               $matches[3] . substr($req, $nl);
    } else {
        debug("proxy");
        $host = $proxy_host;
        $port = $proxy_port;
    }
    debug("host: $host; port: $port");;

    $fp = fsockopen ($host, $port, $errno, $errstr, 30);
    if (!$fp) {
        debug("fsockopen failed: $errstr ($errno)");
        print "HTTP/1.0 500 $errstr ($errno)\r\n";
        print "Content-Type: text/html\r\n\r\n";
        print "<html><body><b>error</b></body></html>\n";
        exit;
    }
    
    #socket_set_blocking($fp, 0);
    #socket_set_timeout($fp, 5, 0);
    
    debug("sending req: $req");
    fwrite($fp, $req);
    debug("before loop");
    $headers_processed = 0;
    $reponse = '';
    while (!feof($fp)) {
        $r = fread($fp, 2048);
        #debug("in loop: $r");
        if ($strip_header && !$headers_processed) {
            #debug("in loop: $r");
            debug("trying to find end of headers");
            $response .= $r;
            $nlnl = strpos($response, "\r\n\r\n"); $add = 4;
            if (!$nlnl) { $nlnl = strpos($response, "\n\n"); $add = 2; }
            if (!$nlnl) continue;
            debug("end of headers found");
            if ($set_content_type) {
                debug("extracting content-type");
                $headers = substr($response, 0, $nlnl);
                debug("headers: $headers");
                if (preg_match_all('/^(Content-.*?)(\r?\n|$)/ims', $headers, $matches)) {
                    for ($i = 0; $i < count($matches[0]); ++$i) {
                        $ct = $matches[1][$i];
                        debug("content-*: $ct");
                        header($ct);
                    }
                }
            }
            print substr($response, $nlnl + $add);
            $headers_processed = 1;
        } else
            print $r;
    }
    fclose ($fp);
    debug("after loop");

?>
