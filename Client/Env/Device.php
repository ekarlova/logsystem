<?php
/**
 * @author: Artem Korneenkov <artem.korneenkov@gmail.com>
 *
 * Date: 14.12.14
 * Time: 00:04
 */

namespace PHPIngress\Client\Env;


class Device {
    /**
     * @var string
     */
    private $deviceId;
    /**
     * @var array
     */
    private $config = [];

    /**
     * @param string $deviceId
     * @param null|string $configFile
     * @throws \ErrorException
     */
    public function __construct($deviceId, $configFile = null)
    {
        if(empty($configFile)) {
            $configFile = __DIR__ . '/../../devices.ini';
        }
        $devices = parse_ini_file($configFile, true);

        if(isset($devices[$deviceId])) {
            $this->config = $devices[$deviceId];
            $this->deviceId = $deviceId;
        }
        else {
            throw new \ErrorException('Device config: can not load config for device ' . $deviceId);
        }
    }

    /**
     * @return string
     */
    public function getCurrentDeviceId()
    {
        return $this->deviceId;
    }

    /**
     * Emulate build.prop config file
     *
     * @param string $paramName
     * @return string|int
     */
    public function getBuildPropParam($paramName)
    {
        return isset($this->config[$paramName]) ? $this->config[$paramName] : null;
    }
}