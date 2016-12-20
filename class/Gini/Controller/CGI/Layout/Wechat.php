<?php
namespace Gini\Controller\CGI\Layout;

abstract class Wechat extends \Gini\Controller\CGI\Layout
{
    protected static $layout_name = 'layout/mobile';
    public $page_class = '';
    public function __preAction($action, &$params)
    {
        $unionId = $this->getWechatId();
        if (!\Gini\Gapper\Client::getUserName()) {
            $user_data = \Gini\Gapper\Client::getUserByIdentity('wechat', $unionId, true);
            $username = $user_data['username'];
            if ($username) {
                // gapper与wechat绑定 但是没有记录openID 则需要记录openID
                \Gini\Gapper\Client::loginByUserName($username);
                $userID = _G('ME')->id;
                if ($userID) {
                    $conf = \Gini\Config::get('tag-db.rpc');
                    $client = \Gini\Config::get('tag-db.client');
                    $url = $conf['url'];
                    $clientID = $client['id'];
                    $clientSecret = $client['secret'];
                    $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
                    $token = $rpc->TagDB->authorize($clientID, $clientSecret);
                    if ($token) {
                        $tagName = "labmai-user/{$userID}";
                        $data = $rpc->tagdb->data->get($tagName);
                        if (!$data) {
                            $userInfo = $this->getUserInfo();
                            if (!$userInfo['openid']) {
                                $this->redirect('wechat/stare-account');
                            }
                            $data = [
                                'openid' => $userInfo['openid'],
                                'unionid' => $userInfo['unionid'],
                            ];
                            if (!$rpc->tagdb->data->set($tagName, $data)) {
                                $this->redirect('wechat/user-bind-fail');
                            }
                        }
                        $this->redirect($_SERVER['REQUEST_URI']);
                    }
                }
            }
        }
        $me = _G('ME');
        if (!$me->id) {
            $token = \Gini\Session::tempToken();
            $_SESSION[$token] = $_SERVER['REQUEST_URI'];
            \Gini\Gapper\Client::goLogin($unionId ? 'wechat/bind-wechat/'.$token :null);
        }

        return parent::__preAction($action, $params);
    }

    protected function redirectToGateway() {
        $gatewayUrl = \Gini\Config::get('wechat.gateway')['url'];
        $token = md5(mt_rand());
        $this->redirect($gatewayUrl, [
            'wx-redirect' => URL($_SERVER['REQUEST_URI']),
            'wx-token' => $token
        ]);
    }

    protected function getWechatId() {
        $unionId = $_SESSION['wechat-gateway.unionid'];
        if (!$unionId) {
            $token = $_GET['wx-token'];
            if (!$token) {
                // 如果没有微信号而且没有token, 应该跳转到微信网关
                $this->redirectToGateway();
            }

            $rpc = new \Gini\RPC(\Gini\Config::get('wechat.gateway')['api_url']);
            $unionId = $rpc->Wechat->getUnionId($token);
            $openId = $userInfo['openid'];
            if ($unionId) {
                $_SESSION['wechat-gateway.unionid'] = $unionId;
            } else {
                $this->redirectToGateway();
            }
        }
        return $unionId;
    }

    protected function getUserInfo() {
        $userInfo = $_SESSION['wechat-gateway.userInfo'];
        if (!$userInfo) {
            $token = $_GET['wx-token'];
            if (!$token) {
                // 如果没有微信号而且没有token, 应该跳转到微信网关
                $this->redirectToGateway();
            }

            $rpc = new \Gini\RPC(\Gini\Config::get('wechat.gateway')['api_url']);
            $userInfo = $rpc->Wechat->getUserInfo($token);
            if ($userInfo) {
                $_SESSION['wechat-gateway.userinfo'] = $userInfo;
            } else {
                $this->redirectToGateway();
            }
        }
        return $userInfo;
    }

    public function __postAction($action, &$params, $response) {
        $class = [];
        if (!$this->page_class) {
            $args = explode('/', $this->env['route']);
            if (count($args) == 0) {
                $args = ['home'];
            }
            while (count($args) > 0) {
                $class[] = 'page-'.implode('-', $args);
                array_pop($args);
            }
        } else {
            $class[] = $this->page_class;
        }
        $this->view->page_classes = array_reverse($class);

        return parent::__postAction($action, $params, $response);
    }
}
