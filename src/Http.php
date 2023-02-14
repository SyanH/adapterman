<?php

namespace Adapterman;

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

/**
 * http protocol
 */
class Http
{
    /**
     * Http status.
     * @var string
     */
    public static string $status = '';

    /**
     * Headers.
     * @var array
     */
    public static array $headers = [];

    /**
     * Cache.
     * @var array
     */
    protected static array $cache = [];
    /**
     * Phrases.
     *
     * @var array<int,string>
     * 
     * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
     */
    const CODES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // WebDAV; RFC 2518
        103 => 'Early Hints', // RFC 8297

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information', // since HTTP/1.1
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content', // RFC 7233
        207 => 'Multi-Status', // WebDAV; RFC 4918
        208 => 'Already Reported', // WebDAV; RFC 5842
        226 => 'IM Used', // RFC 3229

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // Previously "Moved temporarily"
        303 => 'See Other', // since HTTP/1.1
        304 => 'Not Modified', // RFC 7232
        305 => 'Use Proxy', // since HTTP/1.1
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect', // since HTTP/1.1
        308 => 'Permanent Redirect', // RFC 7538

        400 => 'Bad Request',
        401 => 'Unauthorized', // RFC 7235
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', // RFC 7235
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed', // RFC 7232
        413 => 'Payload Too Large', // RFC 7231
        414 => 'URI Too Long', // RFC 7231
        415 => 'Unsupported Media Type', // RFC 7231
        416 => 'Range Not Satisfiable', // RFC 7233
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot', // RFC 2324, RFC 7168
        421 => 'Misdirected Request', // RFC 7540
        422 => 'Unprocessable Entity', // WebDAV; RFC 4918
        423 => 'Locked', // WebDAV; RFC 4918
        424 => 'Failed Dependency', // WebDAV; RFC 4918
        425 => 'Too Early', // RFC 8470
        426 => 'Upgrade Required',
        428 => 'Precondition Required', // RFC 6585
        429 => 'Too Many Requests', // RFC 6585
        431 => 'Request Header Fields Too Large', // RFC 6585
        451 => 'Unavailable For Legal Reasons', // RFC 7725
        
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', // RFC 2295
        507 => 'Insufficient Storage', // WebDAV; RFC 4918
        508 => 'Loop Detected', // WebDAV; RFC 5842
        510 => 'Not Extended', // RFC 2774
        511 => 'Network Authentication Required', // RFC 6585
    ];

    /**
     * Init.
     * @return void
     */
    public static function init()
    {
    }

    /**
     * Reset.
     * @return void
     */
    public static function reset()
    {
        static::$status = 'HTTP/1.1 200 OK';
        static::$headers = [
            'Content-Type' => 'Content-Type: text/html;charset=utf-8',
            'Server' => 'Server: workerman'
        ];
    }

    /**
     * The supported HTTP methods
     * @var array<int,string>
     */
    const AVAILABLE_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Send a raw HTTP header.
     * @param string $content
     * @param bool $replace
     * @param int|null $http_response_code
     * @return bool
     */
    public static function header(string $content, bool $replace = true, int $http_response_code = null): bool
    {
        if (str_starts_with($content, 'HTTP')) {
            static::$status = $content;
            return true;
        }

        $key = \strstr($content, ':', true);
        if (empty($key)) {
            return false;
        }

        if ('location' === \strtolower($key)) {
            if (!$http_response_code) {
                $http_response_code = 302;
            }
            static::responseCode($http_response_code);
        }

        static::$headers[$key] = $content;

        return true;
    }

    /**
     * Remove previously set headers.
     * @param string $name
     * @return void
     */
    public static function headerRemove(string $name)
    {
        unset(static::$headers[$name]);
    }

    /**
     * Sets the HTTP response status code.
     * @param int $code The response code
     * @return boolean|int The valid status code or FALSE if code is not provided and it is not invoked in a web server environment
     */
    public static function responseCode(int $code): bool|int
    {
        if (isset(static::CODES[$code])) {
            static::$status = "HTTP/1.1 $code " . static::CODES[$code];
            return $code;
        }
        return false;
    }

    /**
     * Parse $_FILES.
     * @param string $http_body
     * @param string $http_post_boundary
     * @return void
     */
    protected static function parseUploadFiles(string $http_body, string $http_post_boundary)
    {
        $http_body = \substr($http_body, 0, \strlen($http_body) - (\strlen($http_post_boundary) + 4));
        $boundary_data_array = \explode($http_post_boundary . "\r\n", $http_body);
        if ($boundary_data_array[0] === '') {
            unset($boundary_data_array[0]);
        }
        $key = -1;
        foreach ($boundary_data_array as $boundary_data_buffer) {
            list($boundary_header_buffer, $boundary_value) = \explode("\r\n\r\n", $boundary_data_buffer, 2);
            // Remove \r\n from the end of buffer.
            $boundary_value = \substr($boundary_value, 0, -2);
            $key++;
            foreach (\explode("\r\n", $boundary_header_buffer) as $item) {
                list($header_key, $header_value) = \explode(": ", $item);
                $header_key = \strtolower($header_key);
                switch ($header_key) {
                    case "content-disposition":
                        // Is file data.
                        if (\preg_match('/name="(.*?)"; filename="(.*?)"/', $header_value, $match)) {
                            // Parse $_FILES.
                            $_FILES[$key] = [
                                'name' => $match[1],
                                'file_name' => $match[2],
                                'file_data' => $boundary_value,
                                'file_size' => \strlen($boundary_value),
                            ];
                            break;
                        } // Is post field.
                        else {
                            // Parse $_POST.
                            if (\preg_match('/name="(.*?)"$/', $header_value, $match)) {
                                $_POST[$match[1]] = $boundary_value;
                            }
                        }
                        break;
                    case "content-type":
                        // add file_type
                        $_FILES[$key]['file_type'] = \trim($header_value);
                        break;
                }
            }
        }
    }

    /**
     * Check the integrity of the package.
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input(string $recv_buffer, TcpConnection $connection): int
    {
        if (isset(static::$cache[$recv_buffer]['input'])) {
            return static::$cache[$recv_buffer]['input'];
        }
        $recv_len = \strlen($recv_buffer);
        $crlf_post = \strpos($recv_buffer, "\r\n\r\n");
        if (!$crlf_post) {
            // Judge whether the package length exceeds the limit.
            if ($recv_len >= $connection->maxPackageSize) {
                $connection->close();
            }
            return 0;
        }
        $head_len = $crlf_post + 4;

        $method = \substr($recv_buffer, 0, \strpos($recv_buffer, ' '));
        if (!\in_array($method, static::AVAILABLE_METHODS)) {
            $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            $connection->consumeRecvBuffer($recv_len);
            return 0;
        }

        if ($method === 'GET' || $method === 'OPTIONS' || $method === 'HEAD') {
            static::$cache[$recv_buffer]['input'] = $head_len;
            return $head_len;
        }

        $match = [];
        if (\preg_match("/\r\nContent-Length: ?(\d+)/i", $recv_buffer, $match)) {
            $content_length = isset($match[1]) ? $match[1] : 0;
            $total_length = $content_length + $head_len;
            if (!isset($recv_buffer[1024])) {
                static::$cache[$recv_buffer]['input'] = $total_length;
            }
            return $total_length;
        }

        return $method === 'DELETE' ? $head_len : 0;
    }

    /**
     * Parse $_POST、$_GET、$_COOKIE.
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return array
     */
    public static function decode(string $recv_buffer, TcpConnection $connection): array
    {
        static::reset();
        $microtime = \microtime(true);
        if (isset(static::$cache[$recv_buffer]['decode'])) {
            $cache = static::$cache[$recv_buffer]['decode'];
            $cache['server']['REQUEST_TIME_FLOAT'] =  $microtime;
            $cache['server']['REQUEST_TIME'] =  (int)$microtime;
            $_SERVER = $cache['server'];
            $_POST = $cache['post'];
            $_GET = $cache['get'];
            $_REQUEST = $cache['request'];
            $GLOBALS['HTTP_RAW_POST_DATA'] = $GLOBALS['HTTP_RAW_REQUEST_DATA'] = '';
            return static::$cache[$recv_buffer]['decode'];
        }
        // Init.
        $_POST = $_GET = $_REQUEST = $_FILES = [];
        // $_SERVER
        $_SERVER = [
            'REQUEST_METHOD' => '',
            'REQUEST_URI' => '',
            'SERVER_PROTOCOL' => '',
            'SERVER_SOFTWARE' => 'workerman/' . Worker::VERSION,
            'SERVER_NAME' => '',
            'HTTP_HOST' => '',
            'HTTP_USER_AGENT' => '',
            'HTTP_ACCEPT' => '',
            'HTTP_ACCEPT_LANGUAGE' => '',
            'HTTP_ACCEPT_ENCODING' => '',
            'HTTP_COOKIE' => '',
            'HTTP_CONNECTION' => '',
            'CONTENT_TYPE' => '',
            'REQUEST_TIME' => (int)$microtime,
            'REQUEST_TIME_FLOAT' => $microtime
        ];

        // Parse headers.
        list($http_header, $http_body) = \explode("\r\n\r\n", $recv_buffer, 2);
        $header_data = \explode("\r\n", $http_header);

        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = \explode(' ',
            $header_data[0]);

        $http_post_boundary = '';
        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value) = \explode(':', $content, 2);
            $key = \str_replace('-', '_', strtoupper($key));
            $value = \trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            switch ($key) {
                // HTTP_HOST
                case 'HOST':
                    $tmp = \explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // content-type
                case 'CONTENT_TYPE':
                    if (!\preg_match('/boundary="?(\S+)"?/', $value, $match)) {
                        if ($pos = \strpos($value, ';')) {
                            $_SERVER['CONTENT_TYPE'] = \substr($value, 0, $pos);
                        } else {
                            $_SERVER['CONTENT_TYPE'] = $value;
                        }
                    } else {
                        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
                        $http_post_boundary = '--' . $match[1];
                    }
                    break;
                case 'CONTENT_LENGTH':
                    $_SERVER['CONTENT_LENGTH'] = $value;
                    break;
            }
        }

        // Parse $_POST.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE']) {
            switch ($_SERVER['CONTENT_TYPE']) {
                case 'multipart/form-data':
                    static::parseUploadFiles($http_body, $http_post_boundary);
                    break;
                case 'application/json':
                    $_POST = \json_decode($http_body, true);
                    break;
                case 'application/x-www-form-urlencoded':
                    \parse_str($http_body, $_POST);
                    break;
            }
        }

        // Parse other HTTP action parameters
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== "POST") {
            $data = [];
            if ($_SERVER['CONTENT_TYPE'] === "application/x-www-form-urlencoded") {
                \parse_str($http_body, $data);
            } elseif ($_SERVER['CONTENT_TYPE'] === "application/json") {
                $data = \json_decode($http_body, true);
            }
            $_REQUEST = \array_merge($_REQUEST, $data);
        }

        // HTTP_RAW_REQUEST_DATA HTTP_RAW_POST_DATA
        $GLOBALS['HTTP_RAW_REQUEST_DATA'] = $GLOBALS['HTTP_RAW_POST_DATA'] = $http_body;

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            \parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }

        if (\is_array($_POST)) {
            // REQUEST
            $_REQUEST = \array_merge($_GET, $_POST, $_REQUEST);
        } else {
            // REQUEST
            $_REQUEST = \array_merge($_GET, $_REQUEST);
        }

        // REMOTE_ADDR REMOTE_PORT
        $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
        $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
        $ret = [
            'get' => $_GET,
            'post' => $_POST,
            'server' => $_SERVER,
            'files' => $_FILES,
            'request' => $_REQUEST
        ];
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            static::$cache[$recv_buffer]['decode'] = $ret;
            if (\count(static::$cache) > 256) {
                unset(static::$cache[key(static::$cache)]);
            }
        }

        return $ret;
    }

    /**
     * Http encode.
     * @param string $content
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode(?string $content, TcpConnection $connection): string
    {
        $content = (string)$content;

        // http-code status line.
        $header = static::$status . "\r\n";

        // other headers
        if (static::$headers) {
            $header .= \implode("\r\n", static::$headers) . "\r\n";
        }

        if (!empty($connection->gzip)) {
            $header .= "Content-Encoding: gzip\r\n";
            $content = \gzencode($content, $connection->gzip);
        }
        // header
        $header .= 'Content-Length: ' . \strlen($content) . "\r\n\r\n";

        // the whole http package
        return $header . $content;
    }
}

