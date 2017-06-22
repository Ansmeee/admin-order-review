<?php

/**
* @file Base.php
* @brief Ϊ����APP�ṩͨ�õķ���
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
        * @brief �������˳�
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
        * @brief ���õ�ǰ�����APP��Ϣ
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
        * @brief ��ȡ��ǰ�����APP��Ϣ
        *
        * @return
     */
    public function getCurrentApp()
    {
        $clientID = $_SESSION[self::$_sessionKey];
        return $clientID;
    }

    /**
        * @brief ����app�Ѿ�ͨ����֤
        *
        * @return
     */
    public function assertAuthorized()
    {
        $clientID = $this->getCurrentApp();
        if (!$clientID) {
            throw new \Gini\API\Exception('APPû��ͨ����֤', 404);
        }
    }
}

