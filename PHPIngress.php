<?php

namespace PHPIngress;

include __DIR__ . '/Client/Ingress.php';
include __DIR__ . '/Client/GoogleAccount.php';

use PHPIngress\Client\Env\Device,
    PHPIngress\Client\Ingress,
    PHPIngress\Client\GoogleAccount;

class PHPIngress {
    const FRACTION_ENLIGHTENED = 'enlightened';
    const FRACTION_RESISTANCE = 'resistance';

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

    /**
     * Return array with global score info
     *
     * array('enlightened' => SCORE_VALUE, 'resistance' => SCORE_VALUE)
     *
     * @return array
     */
    public function getGameScore()
    {
        $rawScore = $this->processApiRequest('getGameScore', [], false);

        return [
            self::FRACTION_ENLIGHTENED => isset($rawScore['alienScore']) ? (int)$rawScore['alienScore'] : 0,
            self::FRACTION_RESISTANCE => isset($rawScore['resistanceScore']) ? (int)$rawScore['resistanceScore'] : 0
        ];
    }

    /**
     * Return last news of the day
     *
     * @param string|null $lastNewsId previous news id
     * @return array
     */
    public function getNewsOfTheDay($lastNewsId = null)
    {
        return $this->processApiRequest('getNewsOfTheDay', [$lastNewsId], false);
    }

    /**
     * Return only actually news (
     *
     * @return array
     */
    public function getActuallyNewsOfTheDay()
    {
        static $lastNewsId = null;
        $news = $this->getNewsOfTheDay($lastNewsId);
        if(isset($news['contentId'])) {
            $lastNewsId = $news['contentId'];
        }
        return $news;
    }

    /**
     * @return int
     */
    public function getAvailableInvitesCount()
    {
        $invitesInfo = $this->processApiRequest('getInviteInfo', [], false);
        $count = 0;
        if(isset($invitesInfo['numAvailableInvites'])) {
            $count = intval($invitesInfo['numAvailableInvites']);
        }
        return $count;
    }

    /**
     * @param array $params
     * @return bool
     */
    public function putBulkPlayerStorage(array $params)
    {
        $result = $this->processApiRequest('putBulkPlayerStorage', $params);
        return $result == 'SUCCESS' ? true : false;
    }

    /**
     * @param array $ids
     * @return array
     */
    public function getNickNamesByPlayerIds(array $ids)
    {
        return $this->processApiRequest('getNickNamesFromPlayerIds', [$ids], count($ids) > 10);
    }

    /**
     * @param array $params
     * @return bool
     */
    public function updateProfileSettings(array $params)
    {
        $result = true;
        try {
            $this->processApiRequest('setProfileSettings', $params, false);
        }
        catch (\Exception $e) {
            $result = false;
        }
        return $result;
    }

    private function processApiRequest($action, $params = [], $useGzip = true)
    {
        $response = $this->api->sendPlayerRequest($action, $params, $useGzip);
        $output = [];
        if(isset($response['result'])) {
            $output = $response['result'];
        }
        if(isset($response['gameBasket'])) {

        }
        return $output;
    }
}