<?php

namespace Gini\Controller\CGI;

class Wechat extends Layout\Wechat
{
    public function actionUserBind()
    {
		$this->view->body = V('wechat/bind-success');
    }

    public function actionUserBindFail()
    {
        $this->view->body = V('wechat/bind-fail');
    }

    public function actionStareAccount()
    {
        $this->view->body = V('wechat/stare-labmai-account');
    }

    public function actionBindWechat($token='') {
        $username = \Gini\Gapper\Client::getUserName();
        if (!$username) {
            $this->redirect('error/404');
        }

        $userInfo = $this->getUserInfo();
        $unionid = $userInfo['unionid'];
        $openid = $userInfo['openid'];
        if (!$openid) {
            $this->redirect('wechat/stare-account');
        }
        $me = _G('ME');
        $userID = $me->id;
        $conf = \Gini\Config::get('tag-db.rpc');
        $url = $conf['url'];
        $client = \Gini\Config::get('tag-db.client');
        $clientID = $client['id'];
        $clientSecret = $client['secret'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        $rpc->TagDB->authorize($clientID, $clientSecret);
        $tagName = "labmai-user/{$userID}";
        $data = [
            'unionid' => $unionid,
            'openid' => $openid,
        ];
        if ($rpc->tagdb->data->set($tagName, $data) && \Gini\Gapper\Client::linkIdentity('wechat', $unionid)) {
            $redirect = $_SESSION[$token] ?: 'mobile';
            $this->redirect($redirect);
        }
    }
}