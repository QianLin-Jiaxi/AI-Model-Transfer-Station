<?php
// 错误处理相关配置
define('OUTPUT_FULL_ERRORS', false);
define('LOG_ERRORS', false);
define('ERROR_LOG_FILE', __DIR__ . '/error.log');

// 中转脚本APIKey配置，数组中文本顶一可访问的id例如test1，*则为可访问所有模型
/*
    'sk-114514' => [*],
    'sk-1314' => ['test1'],
*/
$api_keys = [
    // 这里来配置中转站APIkey信息
];

// 模型提供商配置
// 支持两种写法，第一种正常单个请求，第二种为轮询，访问错误自动使用下一个重新请求
/*
    'test1' => [
        'url'    => 'https://.../v1/chat/completions',
        'apikey' => 'apikey',
    ],
    'test2' => [
        [
            'url'    => 'https://.../v1/chat/completions',
            'apikey' => 'apikey',
            'real_model' => 'test2',
        ],
        [
            'url'    => 'https://.../v1/chat/completions',
            'apikey' => 'apikey',
            'real_model' => 'gpt-4o-mini2',
        ],
    ]
*/
$model_providers = [
    // 这里来配置模型提供商信息
];

// 一些作用比较少的辅助函数
function log_error($message) {
    if (LOG_ERRORS) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents(ERROR_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}

function send_error($http_code, $type, $message, $details = '') {
    http_response_code($http_code);
    header('Content-Type: application/json');
    $error = [
        'error' => [
            'message' => $message,
            'type'    => $type,
            'code'    => $http_code,
        ]
    ];
    if (OUTPUT_FULL_ERRORS && !empty($details)) {
        $error['error']['details'] = $details;
    }
    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    if (LOG_ERRORS) {
        log_error("Error {$http_code}: {$message}" . ($details ? " | Details: {$details}" : ''));
    }
    exit;
}

// 确认是否为合法请求类别
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error(405, 'method_not_allowed', 'Only POST method is allowed');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

// 提取中转站APIKey
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
    send_error(401, 'invalid_api_key', 'Missing or malformed Authorization header');
}
$user_api_key = $matches[1];

if (!array_key_exists($user_api_key, $api_keys)) {
    send_error(401, 'invalid_api_key', 'Invalid API key');
}
$allowed_models = $api_keys[$user_api_key];

$input = file_get_contents('php://input');
if ($input === false || $input === '') {
    send_error(400, 'invalid_request', 'Empty request body');
}
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    send_error(400, 'invalid_request', 'Invalid JSON: ' . json_last_error_msg());
}
$model_alias = $data['model'] ?? null;
if (empty($model_alias)) {
    send_error(400, 'invalid_request', 'Field "model" is required');
}

// 检查该APIKey是否允许访问此模型
$is_allowed = ($allowed_models === ['*']) || in_array($model_alias, $allowed_models, true);
if (!$is_allowed) {
    send_error(403, 'model_not_allowed', "Model '{$model_alias}' is not allowed for this API key");
}

// 检查别名是否在提供商配置中
if (!isset($model_providers[$model_alias])) {
    send_error(400, 'model_not_supported', "Model '{$model_alias}' is not supported");
}

$provider_entry = $model_providers[$model_alias];
if (isset($provider_entry['url'])) {
    $candidates = [$provider_entry];
} else {
    $candidates = $provider_entry;
}
if (empty($candidates)) {
    send_error(500, 'config_error', 'No provider candidates configured for model');
}

$request_body = $input;  // 原始数据，先存一下，防止后面用的上
$is_stream = isset($data['stream']) && $data['stream'] === true;

$last_error_body = '';
$last_http_code = 502;

foreach ($candidates as $index => $candidate) {
    $current_body = $input;
    if (!empty($candidate['real_model'])) {
        $tmp_data = $data;
        $tmp_data['model'] = $candidate['real_model'];
        $current_body = json_encode($tmp_data, JSON_UNESCAPED_UNICODE);
    }

    $target_url = $candidate['url'];
    $target_apikey = $candidate['apikey'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $target_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $current_body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $target_apikey,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response_headers = [];
    $http_status_code = 200;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header_line) use (&$response_headers, &$http_status_code) {
        $len = strlen($header_line);
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $header_line, $m)) {
            $http_status_code = (int) $m[1];
        } else {
            $clean = trim($header_line);
            if ($clean !== '') {
                $colon = strpos($clean, ':');
                if ($colon !== false) {
                    $key = strtolower(substr($clean, 0, $colon));
                    $value = trim(substr($clean, $colon + 1));
                    $response_headers[$key] = $value;
                }
            }
        }
        return $len;
    });

    $first_chunk = true;
    $body_buffer = '';
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (
        &$first_chunk, &$body_buffer, &$http_status_code, &$response_headers, $is_stream
    ) {
        if ($is_stream && $http_status_code >= 200 && $http_status_code < 300) {
            if ($first_chunk) {
                http_response_code($http_status_code);
                $skip = ['transfer-encoding', 'connection', 'keep-alive', 'content-encoding'];
                foreach ($response_headers as $k => $v) {
                    if (!in_array($k, $skip)) {
                        header("{$k}: {$v}");
                    }
                }
                while (ob_get_level()) ob_end_clean();
                $first_chunk = false;
            }
            echo $data;
            flush();
            return strlen($data);
        }
        $body_buffer .= $data;
        return strlen($data);
    });

    curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // 这里判断httpcode不在固定范围内视为大模型提供商发生错误
    $success = empty($curl_error) && ($http_status_code >= 200 && $http_status_code < 300);

    if ($success) {
        if ($first_chunk) {
            http_response_code($http_status_code);
            $skip = ['transfer-encoding', 'connection', 'keep-alive', 'content-encoding'];
            foreach ($response_headers as $k => $v) {
                if (!in_array($k, $skip)) {
                    header("{$k}: {$v}");
                }
            }
            echo $body_buffer;
        }
        exit;  // 优雅结束~
    }

    // 失败了，准备日志
    $reason = $curl_error ? "cURL error: {$curl_error}" : "HTTP {$http_status_code}";
    $log_msg = "Model alias '{$model_alias}' candidate #{$index} failed: {$reason}";
    if (!$curl_error && $body_buffer) {
        $log_msg .= ', Response: ' . substr($body_buffer, 0, 500);
    }
    log_error($log_msg);

    $last_error_body = $body_buffer;
    $last_http_code = $curl_error ? 502 : $http_status_code;

    if (!$first_chunk) {
        log_error("Streaming started but failed for alias '{$model_alias}'. Cannot retry.");
        exit;
    }
}

if (OUTPUT_FULL_ERRORS && $last_error_body) {
    http_response_code($last_http_code);
    header('Content-Type: application/json');
    echo $last_error_body;
} else {
    send_error(502, 'all_providers_failed', 'All configured providers for this model failed');
}
// 优雅的闭合脚本
?>
