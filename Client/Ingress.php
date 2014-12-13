<?php
/**
 * @author: Artem Korneenkov <artem.korneenkov@gmail.com>
 *
 * Date: 13.12.14
 * Time: 16:27
 */

namespace PHPIngress\Client;


class Ingress {
    const API_VERSION = 2;
    const API_HOST = 'm-dot-betaspike.appspot.com';
    const API_USER_AGENT = 'Nemesis (gzip)';

    const REQUEST_TIMEOUT = 90;
    const CONNECTION_TIMEOUT = 60;

    private $authCookie;
    private $xsrfToken;
    private $proxy;

    private $connectionTimeout = self::CONNECTION_TIMEOUT;
    private $requestTimeout = self::REQUEST_TIMEOUT;

    /**
     * Constructor
     *
     * @param array $cookies
     * @param string $token
     * @param string|null $proxy
     */
    public function __construct(array $cookies = [], $token = null, $proxy = null)
    {
        if($cookies !== [] && !empty($token))
        {
            $this->setAuthCredentials($cookies, $token);
        }
        $this->setProxy($proxy);
    }

    /**
     * Return true, if api are ready for send/receive requests
     *
     * @return bool
     */
    public function isReady()
    {
        if(empty($this->authCookie) || empty($this->xsrfToken)) {
            return false;
        }
        return true;
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
        $this->authCookie = implode(';', $cookies);
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
     * Return Ingress api version
     *
     * @return int
     */
    public function getGameApiVersion()
    {
        return self::API_VERSION;
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
        return $this->sendRequest('/rpc/gameplay/' . $action, $params, $useGzip);
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
        return $this->sendRequest('/rpc/playerUndecorated/' . $action, $params, $useGzip);
    }

    /**
     * Send request
     *
     * @param string $url
     * @param array $params request params
     * @param boolean $useGzip enable gzip for compress big requests
     * @return array decoded response
     * @throws \ErrorException
     */
    public function sendRequest($url, array $params, $useGzip = true)
    {
        if(!$this->isReady()) {
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
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json;charset=UTF-8',
                                                'Content-Encoding:' . ($useGzip ? ' gzip' : null),
                                                'Accept:', // remove header "Accept: */*" from request
                                                'X-XsrfToken: ' . $this->xsrfToken]);

        // Data
        $requestJSON = json_encode(['params' => $params]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $useGzip ? gzencode($requestJSON) : $requestJSON);
        // Execute
        $response = curl_exec($curl);
        if($response === false) {
            throw new \ErrorException('Server error: ' . curl_error($curl));
        }
        curl_close($curl);

        return json_decode($response, true);
    }
}