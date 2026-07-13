<?php
/*
lib_id: djebel-core-lib-http
lib_name: Djebel Core HTTP
version: 1.0.0
description: Generic outbound HTTP client (Dj_App_Core_Lib_Http) — lazily loaded, shared by any plugin that needs to call an external API.
min_php_ver: 7.4
*/

// Not a plugin — it registers no hooks. It DOES apply request-lifecycle filters (pre-request short-circuit, request-args
// rewrite, response post-process) so consumers can hook the request without this lib coupling
// to them. Instance-based (grab the singleton, then call instance methods) so it's mockable.
// A pre-defined / custom implementation wins: if the class already exists, don't redeclare.
if (class_exists('Dj_App_Core_Lib_Http')) {
    return;
}

class Dj_App_Core_Lib_Http
{
    public static function getInstance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }

    /**
     * Make an outbound HTTP request. Single, generic entry point — callers pass
     * the full URL, the request data, and any extra options (method, headers,
     * timeouts, json, verify_ssl). Knows nothing app-specific.
     *
     * The request lifecycle is filterable (WP-style): a listener can short-circuit
     * the whole call (cache hit, banned URL), rewrite the request args before it
     * goes out, or post-process the response Result.
     *
     * @param string $url
     * @param array  $args        Request data: JSON/form body for POST/PUT, query for GET.
     * @param array  $extra_opts  method, headers (array), timeout, connect_timeout, json (bool), verify_ssl (bool).
     * @return Dj_App_Result  status (bool), code (string), msg (string), data (decoded body), http_status (int).
     */
    public function request($url, $args = [], $extra_opts = [])
    {
        $res_obj = new Dj_App_Result();

        $ctx = [
            'url' => $url,
            'args' => $args,
            'extra_opts' => $extra_opts,
        ];

        // Pre-request filter: a listener can take the whole call over — a cache hit, a
        // different transport, a refused host — by populating the Result (a code or msg) or
        // flagging ->_processed. Any of those means it was handled, so we short-circuit here
        // and the curl below never runs.
        $res_obj = Dj_App_Hooks::applyFilter('app.core.lib.http.filter.pre_request', $res_obj, $ctx);

        if (!empty($res_obj->code) || !empty($res_obj->msg) || !empty($res_obj->_processed)) {
            return $res_obj;
        }

        try {
            $url = Dj_App_String_Util::trim($url);

            if (empty($url)) {
                throw new Dj_App_Exception('Request URL is missing.', ['code' => 'http.url_missing']);
            }

            $method = empty($extra_opts['method']) ? 'GET' : strtoupper($extra_opts['method']);

            // JSON body by default; only an explicit json=false switches to form encoding.
            $is_json = true;

            if (isset($extra_opts['json'])) {
                $is_json = !empty($extra_opts['json']);
            }

            $headers = [];

            if (!empty($extra_opts['headers']) && is_array($extra_opts['headers'])) {
                $headers = $extra_opts['headers'];
            }

            $connect_timeout = empty($extra_opts['connect_timeout']) ? 10 : (int) $extra_opts['connect_timeout'];
            $timeout = empty($extra_opts['timeout']) ? 20 : (int) $extra_opts['timeout'];

            // GET/HEAD carry data in the query string; everything else gets a body.
            $has_body = $method != 'GET' && $method != 'HEAD';
            $request_url = $url;
            $body = '';

            if ($has_body) {
                if ($is_json) {
                    $body = json_encode($args);
                    $headers[] = 'Content-Type: application/json';
                } else {
                    $body = http_build_query($args);
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            } elseif (!empty($args)) {
                $query = http_build_query($args);
                $separator = strpos($request_url, '?') === false ? '?' : '&';
                $request_url = $request_url . $separator . $query;
            }

            // Headers accept curl-style "Key: Value" strings OR key => value pairs; a string key
            // becomes "key: value" here, so callers never hand-concatenate the ': ' themselves.
            $curl_headers = [];

            foreach ($headers as $header_name => $header_value) {
                if (is_int($header_name)) {
                    $curl_headers[] = $header_value;
                } else {
                    $curl_headers[] = $header_name . ': ' . $header_value;
                }
            }

            $curl_handle = curl_init($request_url);

            if (empty($curl_handle)) {
                throw new Dj_App_Exception('Failed to initialize the request.', ['code' => 'http.curl_init_failed']);
            }

            $curl_options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $curl_headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $connect_timeout,
                CURLOPT_TIMEOUT => $timeout,
            ];

            if ($has_body) {
                $curl_options[CURLOPT_POSTFIELDS] = $body;
            }

            // TLS verification stays ON by default; only an explicit opt-out disables
            // it (e.g. a known self-signed staging cert). Never silently off.
            $verify_ssl = true;

            if (isset($extra_opts['verify_ssl'])) {
                $verify_ssl = !empty($extra_opts['verify_ssl']);
            }

            if (empty($verify_ssl)) {
                $curl_options[CURLOPT_SSL_VERIFYPEER] = false;
                $curl_options[CURLOPT_SSL_VERIFYHOST] = 0;
            }

            $opts_ok = curl_setopt_array($curl_handle, $curl_options);

            if (empty($opts_ok)) {
                curl_close($curl_handle);

                throw new Dj_App_Exception('Failed to configure the request.', ['code' => 'http.curl_setopt_failed']);
            }

            $resp_body = curl_exec($curl_handle);
            $curl_error = curl_error($curl_handle);
            $http_status = curl_getinfo($curl_handle, CURLINFO_RESPONSE_CODE);
            $http_status = (int) $http_status;
            curl_close($curl_handle);

            // curl_exec returns false on a transport failure (DNS, timeout, refused,
            // TLS). An empty string is a body-less response — also nothing to parse.
            if ($resp_body === false || $resp_body === '') {
                $message = empty($curl_error) ? 'The request did not complete.' : $curl_error;

                throw new Dj_App_Exception($message, ['code' => 'http.network_failed', 'http_status' => $http_status]);
            }

            $data = [];
            $decoded = json_decode($resp_body, true);

            if (is_array($decoded)) {
                $data = $decoded;
            }

            $http_ok = $http_status >= 200 && $http_status < 300;
            $code = $http_ok ? 'http.ok' : 'http.http_error';

            $res_obj->status = $http_ok;
            $res_obj->code = $code;
            $res_obj->http_status = $http_status;
            $res_obj->data($data);
        } catch (Dj_App_Exception $e) {
            $exc_data = $e->getData();
            $res_obj->code = empty($exc_data['code']) ? '' : $exc_data['code'];
            $res_obj->msg = $e->getMessage();
            $res_obj->http_status = empty($exc_data['http_status']) ? 0 : $exc_data['http_status'];
        }

        // Response filter: a listener post-processes the Result — cache it, reshape it,
        // redact it — before it reaches the caller.
        $res_obj = Dj_App_Hooks::applyFilter('app.core.lib.http.filter.response', $res_obj, $ctx);

        return $res_obj;
    }

    /**
     * GET convenience wrapper. $args become query params.
     * @param string $url
     * @param array  $args
     * @param array  $extra_opts
     * @return Dj_App_Result
     */
    public function get($url, $args = [], $extra_opts = [])
    {
        $extra_opts['method'] = 'GET';
        $res_obj = $this->request($url, $args, $extra_opts);

        return $res_obj;
    }

    /**
     * POST convenience wrapper. $args become the (JSON by default) body.
     * @param string $url
     * @param array  $args
     * @param array  $extra_opts
     * @return Dj_App_Result
     */
    public function post($url, $args = [], $extra_opts = [])
    {
        $extra_opts['method'] = 'POST';
        $res_obj = $this->request($url, $args, $extra_opts);

        return $res_obj;
    }
}
