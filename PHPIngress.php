<?php

namespace PHPIngress;

include __DIR__ . '/Client/Ingress.php';
include __DIR__ . '/Client/GoogleAccount.php';

use PHPIngress\Client\Env\Device,
    PHPIngress\Client\Ingress,
    PHPIngress\Client\GoogleAccount;

class PHPIngress {
    /**
     * @var GoogleAccount
     */
    protected $account;
    /**
     * @var Ingress
     */
    protected $api;
    /**
     * @var string|null
     */
    protected $proxy;

    protected $inventory;
    /**
     * Enable XM auto collecting on any game action
     *
     * @var bool
     */
    protected $autoCollectXM = true;

    /**
     * Constructor
     *
     * @param Device $env
     */
    public function __construct(Device $env)
    {
        $this->account = new GoogleAccount($env);
        $this->api = new Ingress($env);
        if(!empty($this->proxy)) {
            $this->account->setProxy($this->proxy);
            $this->api->setProxy($this->proxy);
        }
    }

    /**
     * Return current environment configuration
     *
     * @return Device
     */
    public function getEnvironment()
    {
        return $this->api->getEnvironment();
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
        $this->account->setProxy($this->proxy);
        $this->api->setProxy($this->proxy);
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
     * Auth
     *
     * @param string $userEmail user email (gmail)
     * @param string $password user password
     * @return $this
     */
    public function login($userEmail, $password)
    {
        $this->account->login($userEmail, $password);

        if($this->account->isLogged()) {
            $this->api->doLogin($this->account->getAuthToken('Auth'));
        }

        return $this;
    }

    /**
     * Logout and clear auth credentials
     *
     * @return $this
     */
    public function logout()
    {
        $this->account->logout();
        $this->api->clearAuthCredentials();
        return $this;
    }

}