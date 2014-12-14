<?php
/**
 * @author: Artem Korneenkov <artem.korneenkov@gmail.com>
 *
 * Date: 13.12.14
 * Time: 20:09
 */

namespace PHPIngress\Client;

use PHPIngress\Client\Env\Device;

class GoogleAccount {
    const API_HOST = 'android.clients.google.com';
    const API_LOGIN_URL = '/auth';
    const API_USER_AGENT = 'GoogleLoginService/1.3';

    const REQUEST_TIMEOUT = 90;
    const CONNECTION_TIMEOUT = 60;

    /**
     * @var Device
     */
    private $env;
    /**
     * @var string
     */
    private $proxy;
    /**
     * @var string|null
     */
    private $currentUser;
    /**
     * @var array
     */
    private $authTokens = [];

    /**
     * @var int
     */
    private $connectionTimeout = self::CONNECTION_TIMEOUT;
    /**
     * @var int
     */
    private $requestTimeout = self::REQUEST_TIMEOUT;

    public function __construct(Device $env)
    {
        $this->env = $env;
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
     * Login to google services
     *
     * @param string $userEmail
     * @param string $password
     * @return $this
     * @throws \ErrorException
     */
    public function login($userEmail, $password)
    {
        if(empty($userEmail) || empty($password)) {
            throw new \InvalidArgumentException('Auth failed: invalid login/password');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://' . self::API_HOST . self::API_LOGIN_URL);
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
        curl_setopt($curl, CURLOPT_USERAGENT,
            self::API_USER_AGENT .
            ' (' .
            $this->env->getParam('ro.product.device') .
            ' ' .
            $this->env->getParam('ro.build.id') .
            ')'
        );
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept:']); // remove header "Accept: */*" from request

        $params = [
            'accountType' => 'HOSTED_OR_GOOGLE',
            'Email' => $userEmail,
            'Passwd' => $password,
            'has_permission' => 1,
            'service' => 'ah',
            'source' => 'android',
            'androidId' => $this->env->getParam('device.androidId'),
            'app' => $this->env->getParam('ingress.app'),
            'client_sig' => $this->env->getParam('client.sign'),
            'device_country' => $this->env->getParam('device.country'),
            'operatorCountry' => $this->env->getParam('operator.country'),
            'lang' => $this->env->getParam('ro.product.locale.language'),
            'sdk_version' => intval($this->env->getParam('ro.build.version.sdk'))
        ];

        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));

        // Execute
        $response = trim(curl_exec($curl));
        if($response === false) {
            throw new \ErrorException('Server error: ' . curl_error($curl));
        }
        curl_close($curl);
        if(mb_strpos($response, 'Auth=') === false) {
            throw new \InvalidArgumentException('Auth failed: invalid login/password');
        }

        $this->currentUser = $userEmail;

        $this->authTokens = [];
        foreach(explode("\n", $response) as $item) {
            list($name, $value) = explode('=', $item, 2);
            $this->authTokens[trim($name)] = trim($value);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function logout()
    {
        $this->authTokens = [];
        $this->currentUser = null;
        return $this;
    }

    /**
     * @return bool
     */
    public function isLogged()
    {
        return (!empty($this->currentUser) && $this->authTokens !== []);
    }

    /**
     * @param string $tokenName
     * @return string|null
     */
    public function getAuthToken($tokenName)
    {
        return isset($this->authTokens[$tokenName]) ? $this->authTokens[$tokenName] : null;
    }
}