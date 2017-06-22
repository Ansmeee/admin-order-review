<?php

/**
* @file Base.php
* @brief 为所有APP提供通用的方法
* @author wenjun.zheng
* @version 0.1.0
* @date 2017-06-20
*/
namespace Gini\Controller\API;

abstract class Base extends \Gini\Controller\API
{
    // session key
    private static $_sessionKey = 'mall-api.appid';

    public function __construct()
    {
        $this->assertAuthorized();
    }

    /**
        * @brief 非正常退出
        *
        * @param $message
        * @param $code
        *
        * @return
     */
    public function quit($message, $code=1)
    {
        throw new \Gini\API\Exception($message, $code);
    }

    /**
        * @brief 设置当前请求的APP信息
        *
        * @param $id
        *
        * @return
     */
    public function setCurrentApp($clientID)
    {
        $_SESSION[self::$_sessionKey] = $clientID;
    }

    /**
        * @brief 获取当前请求的APP信息
        *
        * @return
     */
    public function getCurrentApp()
    {
        $clientID = $_SESSION[self::$_sessionKey];
        return $clientID;
    }

    /**
        * @brief 断言app已经通过验证
        *
        * @return
     */
    public function assertAuthorized()
    {
        $clientID = $this->getCurrentApp();
        if (!$clientID) {
            throw new \Gini\API\Exception('APP没有通过验证', 404);
        }
    }
}
