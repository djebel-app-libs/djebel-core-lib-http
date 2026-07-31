# djebel-core-lib-http

Generic outbound HTTP client — `Dj_App_Core_Lib_Http`. Lazily loaded, shared by any plugin
that needs to call an external API. Knows nothing app-specific; every point where you'd want
to customize a request is a **hook**, so consumers extend it without this lib coupling to them.

## Usage

```php
Dj_App_Lib::loadLib('djebel-core-lib-http');
$http_obj = Dj_App_Core_Lib_Http::getInstance();

$args = [
    'q' => 'hello',
];

$extra_opts = [
    'timeout' => 15,
];

$res_obj = $http_obj->get('https://api.example.com/search', $args, $extra_opts);

if ($res_obj->isError()) {
    return $res_obj;
}

$data = $res_obj->data();
```

`$extra_opts` keys: `method`, `headers` (array), `timeout`, `connect_timeout`, `json` (bool),
`verify_ssl` (bool), `basic_auth_user` + `basic_auth_password`.

## Hooks

Fired in this order during a request. **Filters** transform a value (return it); **actions**
observe only (no return). Seams whose name contains `curl` hand you the transport internals —
if the lib ever swaps off curl, those are the ones to revisit.

| Hook | Kind | Receives | Purpose |
|---|---|---|---|
| `app.core.lib.http.filter.pre_request` | filter | `Dj_App_Result` | short-circuit (cache hit, banned host) — populate the Result to skip the call |
| `app.core.lib.http.filter.request_url` | filter | `string $url` | rewrite the target URL |
| `app.core.lib.http.filter.request_opts` | filter | `array $extra_opts` | adjust method / headers / timeout / auth |
| `app.core.lib.http.filter.request_args` | filter | `array $args` | add / modify the request payload (POST body / GET query) |
| `app.core.lib.http.filter.curl_options` | filter | `array $curl_options` | add / remove / override any `CURLOPT_*` flag |
| `app.core.lib.http.filter.curl_handle` | filter | curl handle | raw `curl_setopt`, a `CURLSH` share handle |
| `app.core.lib.http.action.after_curl_exec` | action | `array $ctx` | observe the finished transfer (log, metrics) |
| `app.core.lib.http.filter.response` | filter | `Dj_App_Result` | post-process the result (cache, reshape, redact) |

Every filter/action also receives a `$ctx` array (`url`, `args`, `extra_opts`; the
`after_curl_exec` action adds `response_body`, `http_status`, `curl_error`, `curl_handle`).

## Examples

Callbacks are **named methods** — never closures (project rule). Register once at bootstrap.

```php
// Bootstrap (top of your plugin file) — register the listeners on the seams.
Dj_App_Hooks::addFilter('app.core.lib.http.filter.request_url', ['My_Http_Hooks', 'routeStagingToLocal']);
Dj_App_Hooks::addFilter('app.core.lib.http.filter.request_opts', ['My_Http_Hooks', 'addBasicAuth']);
Dj_App_Hooks::addFilter('app.core.lib.http.filter.request_args', ['My_Http_Hooks', 'stampApiVersion']);
Dj_App_Hooks::addFilter('app.core.lib.http.filter.curl_options', ['My_Http_Hooks', 'tuneCurlFlags']);
Dj_App_Hooks::addFilter('app.core.lib.http.filter.curl_handle', ['My_Http_Hooks', 'attachShareHandle']);
Dj_App_Hooks::addAction('app.core.lib.http.action.after_curl_exec', ['My_Http_Hooks', 'logTiming']);
Dj_App_Hooks::addFilter('app.core.lib.http.filter.response', ['My_Http_Hooks', 'redactResponse']);

class My_Http_Hooks
{
    /**
     * Route a staging host to a local dev box. FILTER on request_url — return the new URL.
     * @param string $url
     * @param array  $ctx  url, args, extra_opts
     * @return string
     */
    public static function routeStagingToLocal($url, $ctx = [])
    {
        if (strpos($url, 'https://staging.example.com') === 0) {
            $url = str_replace('https://staging.example.com', 'http://127.0.0.1:8080', $url);
        }

        return $url;
    }

    /**
     * Add HTTP Basic auth. FILTER on request_opts — curl builds the header from user:password.
     * @param array $extra_opts
     * @param array $ctx
     * @return array
     */
    public static function addBasicAuth($extra_opts, $ctx = [])
    {
        $extra_opts['basic_auth_user'] = 'staging';
        $extra_opts['basic_auth_password'] = 'secret';

        return $extra_opts;
    }

    /**
     * Stamp a field onto every request payload. FILTER on request_args — return the modified args.
     * @param array $args
     * @param array $ctx
     * @return array
     */
    public static function stampApiVersion($args, $ctx = [])
    {
        $args['api_version'] = '2';

        return $args;
    }

    /**
     * ADD a flag and REMOVE one, declaratively. FILTER on curl_options — return the modified map.
     * @param array $curl_options
     * @param array $ctx
     * @return array
     */
    public static function tuneCurlFlags($curl_options, $ctx = [])
    {
        $curl_options[CURLOPT_FOLLOWLOCATION] = true;   // add: follow redirects
        unset($curl_options[CURLOPT_TIMEOUT]);          // remove: drop the timeout for a long poll

        return $curl_options;
    }

    /**
     * Attach a shared connection/DNS handle for keep-alive across calls. FILTER on curl_handle —
     * raw curl_setopt, then return the handle.
     * @param mixed $curl_handle  7.4 resource / 8+ CurlHandle
     * @param array $ctx
     * @return mixed
     */
    public static function attachShareHandle($curl_handle, $ctx = [])
    {
        $share_handle = My_Http_Share::getInstance()->getHandle();
        curl_setopt($curl_handle, CURLOPT_SHARE, $share_handle);

        return $curl_handle;
    }

    /**
     * Log the request timing. ACTION on after_curl_exec — observe only, no return.
     * @param array $ctx  url, http_status, curl_error, curl_handle, ...
     * @return void
     */
    public static function logTiming($ctx = [])
    {
        $curl_handle = empty($ctx['curl_handle']) ? null : $ctx['curl_handle'];

        if (empty($curl_handle)) {
            return;
        }

        $total_time = curl_getinfo($curl_handle, CURLINFO_TOTAL_TIME);

        $log_data = [
            'url' => empty($ctx['url']) ? '' : $ctx['url'],
            'http_status' => empty($ctx['http_status']) ? 0 : $ctx['http_status'],
            'total_time' => $total_time,
        ];

        My_Http_Log::getInstance()->write($log_data);
    }

    /**
     * Redact a secret before the result travels on. FILTER on response — return the Result.
     * @param Dj_App_Result $res_obj
     * @param array         $ctx
     * @return Dj_App_Result
     */
    public static function redactResponse($res_obj, $ctx = [])
    {
        $data = $res_obj->data();

        if (!empty($data['token'])) {
            $data['token'] = '***';
            $res_obj->data($data);
        }

        return $res_obj;
    }
}
```

### Short-circuit from cache (`pre_request`)

```php
Dj_App_Hooks::addFilter('app.core.lib.http.filter.pre_request', ['My_Http_Cache', 'serveIfCached']);

class My_Http_Cache
{
    /**
     * Serve a cached body and skip the network. FILTER on pre_request — populate the Result
     * (a code / msg / ->_processed) to short-circuit; return it untouched to let the call run.
     * @param Dj_App_Result $res_obj
     * @param array         $ctx  url, args, extra_opts
     * @return Dj_App_Result
     */
    public static function serveIfCached($res_obj, $ctx = [])
    {
        $url = empty($ctx['url']) ? '' : $ctx['url'];
        $cached = My_Http_Store::getInstance()->get($url);

        if (!empty($cached)) {
            $res_obj->status = true;
            $res_obj->code = 'http.cache_hit';
            $res_obj->data($cached);
        }

        return $res_obj;
    }
}
```

**Notes**
- Register at bootstrap (before the request fires), not inside the callback.
- A filter MUST return its value; an action returns nothing.
- Prefer `curl_options` (declarative add/remove) over `curl_handle` (raw) unless you need the
  handle for something the options map can't express.
