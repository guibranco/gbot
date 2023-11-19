<?php

function getHeaders($header)
{
    $headers = array();
    foreach (explode("\r\n", $header) as $i => $line) {
        if ($i === 0) {
            $headers['http_code'] = $line;
        } else {
            $explode = explode(": ", $line);
            if (count($explode) == 2) {
                list($key, $value) = explode(': ', $line);
                $headers[$key] = $value;
            }
        }
    }

    return $headers;
}


function sendHealthCheck($type=null)
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        return;
    }
    global $healthChecksIoUuid;

    $curl = curl_init();
    curl_setopt_array(
        $curl,
        array(
            CURLOPT_URL => "https://hc-ping.com/" . $healthChecksIoUuid . ($type == null ? "" : $type),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false
        )
    );
    curl_exec($curl);
    curl_close($curl);
}

function toCamelCase($inputString) {
    return preg_replace_callback(
        '/(?:^|_| )(\w)/',
        function ($matches) {
            return strtoupper($matches[1]);
        },
        $inputString
    );
}
