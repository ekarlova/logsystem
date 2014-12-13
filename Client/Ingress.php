<?php
/**
 * @author: Artem Korneenkov <artem.korneenkov@gmail.com>
 *
 * Date: 13.12.14
 * Time: 16:27
 */

namespace PHPIngress\Client;

include __DIR__ . '/Env/Device.php';

use PHPIngress\Client\Env\Device;

class Ingress {
    const API_HOST = 'm-dot-betaspike.appspot.com';
    const API_USER_AGENT = 'Nemesis (gzip)';
    const API_LOGIN_URL = '/_ah/login';
    const API_HANDSHAKE_URL = '/handshake';

    const CLIENT_SOFTWARE_VERSION = '2014-11-25T19:22:37Z 6522e66c7180 opt';
    const CLIENT_SOFTWARE_SIGNATURE = 'axhVCDsCfxgxfnw01TP2TDRWFgkHYno0NnF5Y9aMoPpeiE7vRAEPbXGqX5ayTtBlTOgLj4RIzSrzyrK+4fOk0c9ynRgOKUBBUpszmpKqvKoVOFMJqPam22bWcsaLMOwBxgayRMCC4eglCjZpXSkITdNTtG44407UYYU5wmdEPmLB0oOyNJJs2CnCUuWRAaZUTK4Z0mJneEDkPudlIelno6bEz5k6lwWTfAI1Fcyw164hDU5KuJvuxuui0dfAwP2d4Inc0coQ4mjtdJE9fN81/5JWjGFLlAPKYrsH8EY0XUlIsgrwo2PkSAnfFJ6JhZ2tH0IwQByAatbCwv2CawoaRSk3NOEcfU1WVm4natcmsat1FBJHdDlLLAo8CPa0+uVZecNw7AtcbBEjymXZzELhwn3Kf7KGQfcgmI+dONCbyIC9adlIRmkxTX69fcjx+b77Lw1Zxtu/0pYkzQcsuUfQj6QCyKWtstAkLmEYESMMc5v6WKWrI4wXtSXeAL9SZ0r58PnGqgaTNEVa2DFW9h3+qlP3PEl5XwaE6yv0KAyXcoCX9MJteJJ5Q3hELkun24N6JV4u5c+7/7X4l/32xpCnSN6p5WP+HKv74lP1yQLZDRqUb72NQsA2Jxm+JbgPM2QgXOJne9x0xYsOx0YovaqgI3hiIGI1iEuB9+Ghk3ZFOtM';

    const REQUEST_TIMEOUT = 90;
    const CONNECTION_TIMEOUT = 60;

    /**
     * @var Device
     */
    private $env;
    /**
     * @var string
     */
    private $authCookie;
    /**
     * @var string
     */
    private $xsrfToken;
    /**
     * @var string
     */
    private $proxy;

    /**
     * @var array
     */
    private $lastHandshakeResponse = [];

    /**
     * @var int
     */
    private $connectionTimeout = self::CONNECTION_TIMEOUT;
    /**
     * @var int
     */
    private $requestTimeout = self::REQUEST_TIMEOUT;

    /**
     * Constructor
     *
     * @param Device $env environment configuration
     */
    public function __construct(Device $env)
    {
        $this->env = $env;
    }

    /**
     * Return current environment configuration
     *
     * @return Device
     */
    public function getEnvironment()
    {
        return $this->env;
    }

    /**
     * Login to Ingress account
     *
     * @param string $authToken auth-token from android tokens storage or google oauth
     * @throws \ErrorException
     */
    public function doLogin($authToken)
    {
        $this->clearAuthCredentials();

        $loginUrl = 'https://' .
                    self::API_HOST .
                    self::API_LOGIN_URL .
                    '?' .
                    http_build_query([
                        'continue' => 'https://' . self::API_HOST,
                        'auth' => $authToken]);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($curl, CURLOPT_HTTPGET, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        if($this->proxy) {
            curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($curl, CURLOPT_PROXY, $this->proxy);
        }
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->requestTimeout);
        // Headers
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_USERAGENT, $this->getUserAgentForLogin());
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept:']); // remove header "Accept: */*" from request
        // Execute
        $response = curl_exec($curl);
        if($response === false) {
            if(curl_getinfo($curl, CURLINFO_HTTP_CODE) == 500) {
                throw new \InvalidArgumentException('Auth failed: invalid token');
            }
            else {
                throw new \ErrorException('Auth failed: ' . curl_error($curl));
            }
        }
        curl_close($curl);

        $data = explode("\r\n\r\n", $response);
        // Get last headers block
        $data = $data[count($data) - 2];

        $cookies = [];
        preg_match_all('/Set-Cookie: (.*?);/ism', $data, $cookies);
        if(count($cookies) > 1) {
            $this->authCookie = implode('; ', array_unique($cookies[1]));
        }
        if(empty($this->authCookie)) {
            throw new \ErrorException('Auth failed: response contains no cookies');
        }
        $this->processHandshake();
    }

    /**
     * Return true, if api are ready for send/receive requests
     *
     * @return bool
     */
    public function isReady()
    {
        if(empty($this->authCookie) || empty($this->xsrfToken) || $this->lastHandshakeResponse === []) {
            return false;
        }
        return true;
    }

    /**
     * Return array of last handshake data
     *
     * @return array
     */
    public function getLastHandshakeResponse()
    {
        return $this->lastHandshakeResponse;
    }

    /**
     * Set/update auth credentials
     *
     * @param array $cookies
     * @param string $token
     * @return $this
     */
    public function setAuthCredentials(array $cookies, $token)
    {
        array_walk($cookies, function(&$value, $key) {
            $value = $key . '=' . $value;
        });
        $this->authCookie = implode('; ', $cookies);
        $this->xsrfToken = $token;
        return $this;
    }

    /**
     * Remove auth credentials
     *
     * @return $this
     */
    public function clearAuthCredentials()
    {
        $this->authCookie = null;
        $this->xsrfToken = null;
        $this->lastHandshakeResponse = [];
        return $this;
    }

    /**
     * Set proxy server
     *
     * @param string $proxyUrl proxy address (such as 192.168.0.1:8080), use NULL for remove proxy
     * @return $this
     */
    public function setProxy($proxyUrl)
    {
        $this->proxy = $proxyUrl;
        return $this;
    }

    /**
     * Return proxy server url or NULL, if proxy is not configured
     *
     * @return string|null
     */
    public function getProxy()
    {
        return $this->proxy;
    }

    /**
     * @param string $action
     * @param array $params
     * @param boolean $useGzip enable gzip for compress big requests
     * @return array
     * @throws \HttpUrlException
     */
    public function sendGameplayRequest($action, array $params, $useGzip = true)
    {
        return $this->sendRequest('/rpc/gameplay/' . $action, $params, $useGzip, false);
    }

    /**
     * @param string $action
     * @param array $params
     * @param boolean $useGzip enable gzip for compress big requests
     * @return array
     * @throws \HttpUrlException
     */
    public function sendPlayerRequest($action, array $params, $useGzip = true)
    {
        return $this->sendRequest('/rpc/playerUndecorated/' . $action, $params, $useGzip, false);
    }

    /**
     * Send request
     *
     * @param string $url
     * @param array $params request params
     * @param boolean $useGzip enable gzip for compress big requests
     * @param boolean $handshakeMode set TRUE for handshake mode
     * @return array decoded response
     * @throws \ErrorException
     */
    public function sendRequest($url, array $params, $useGzip = true, $handshakeMode = false)
    {
        if(!$handshakeMode && !$this->isReady()) {
            throw new \ErrorException('Missing auth credentials (cookies, xsrf-token)');
        }
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://' . self::API_HOST . $url);
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        if($this->proxy) {
            curl_setopt($curl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            curl_setopt($curl, CURLOPT_PROXY, $this->proxy);
        }
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->requestTimeout);
        // Headers
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_USERAGENT, self::API_USER_AGENT);
        curl_setopt($curl, CURLOPT_COOKIE, $this->authCookie);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json;charset=UTF-8',
                'Content-Encoding:' . ($useGzip ? ' gzip' : null),
                'Accept:', // remove header "Accept: */*" from request
                'X-XsrfToken:' . ($handshakeMode ? null : ' ' . $this->xsrfToken)]
        );

        $request = $handshakeMode ? rawurlencode(json_encode($params)) : json_encode(['params' => $params]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $useGzip ? gzencode($request) : $request);
        // Execute
        $response = trim(curl_exec($curl));
        if($response === false) {
            throw new \ErrorException('Server error: ' . curl_error($curl));
        }
        curl_close($curl);
        if($handshakeMode) {
            // Fix google anti-ajax protection
            $pos = mb_strpos($response, '{');
            if($pos === false) {
                throw new \ErrorException('Handshake error: invalid response');
            }
            else {
                $response = mb_substr($response, $pos);
            }
        }

        return json_decode($response, true);
    }

    /**
     * Handshake procedure
     *
     * @return $this
     * @throws \ErrorException
     */
    private function processHandshake()
    {
        if($this->isReady()) {
            throw new \ErrorException('Auth failed: handshake procedure already completed');
        }
        // Build handshake request
        $request = [
            'nemesisSoftwareVersion' => self::CLIENT_SOFTWARE_VERSION,
            'deviceSoftwareVersion' => $this->env->getBuildPropParam('ro.build.version.release'),
            'a' => self::CLIENT_SOFTWARE_SIGNATURE,
            'reason' => 'SUP'
        ];
        // Send handshake
        $this->lastHandshakeResponse = $this->sendRequest(self::API_HANDSHAKE_URL, $request, true, true);
        // Get XSRF-Token
        $this->xsrfToken = $this->lastHandshakeResponse['result']['xsrfToken'];
        return $this;
    }

    /**
     * Build user-agent header value for auth request
     *
     * @return string
     */
    private function getUserAgentForLogin()
    {
        return 'Dalvik/1.6.0 (Linux; U; Android 4.3; Nexus-4 Build/JLS36G)';
    }
}