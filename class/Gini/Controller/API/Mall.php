<?php

namespace Gini\Controller\API;

class Mall extends \Gini\Controller\API\Base
{
    /**
        * @brief ÖØÐ´¹¹Ôìº¯Êý£¬±ÜÃâauthorize¶ÏÑÔÅÐ¶Ï
        *
        * @return
     */
    public function __construct()
    {
    }

    public function actionAuthorize($clientID, $clientSecret)
    {
        $conf = \Gini\Config::get('gapper.rpc');
        $url = $conf['url'];
        try {
            $cacheKey = "app#{$url}#{$clientID}#token#{$clientSecret}";
            $token = \Gini\Module\AppBase::cacheData($cacheKey);
            if (!$token) {
                $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
                $token = $rpc->gapper->app->authorize($clientID, $clientSecret);
                \Gini\Module\AppBase::cacheData($cacheKey, $token);
            }
        }
        catch (\Exception $e) {
            throw new \Gini\API\Exception('ÍøÂç¹ÊÕÏ', 503);
        }
        if ($token) {
            $this->setCurrentApp($clientID);
            return session_id();
        }
        throw new \Gini\API\Exception('·Ç·¨µÄAPP', 404);
    }
}

