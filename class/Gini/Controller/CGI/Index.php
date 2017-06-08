<?php

namespace Gini\Controller\CGI;

class Index extends Layout\Board{

    public function __index(){
        $this->redirect('pending');
    }

    public function actionLogout()
    {
        \Gini\Gapper\Client::logout();
        $this->redirect('/');
    }

    public function actionPending()
    {
        $vars = [
            'type'=> 'pending'
        ];

        $this->view->body = V('review/index', $vars);
    }

    public function actionHistory($group = '')
    {
        $me = _G('ME');
        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);
            $params['member'] = $me->id;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);
            $current_group = $engine->group($group);
        } catch (\Gini\BPM\Exception $e) {
        }
        $vars = [
            'type'=> 'history',
            'current_group' => $current_group->id ? $current_group : current($groups),
            'groups' => $groups
        ];

        $this->view->body = V('review/index', $vars);
    }

    public function actionWechatBind()
    {
        $me = _G('ME');
        $vars = [
            'user' => $me,
        ];
        $this->view->body = V('wechat/bind', $vars);
    }

    public function actionManager()
    {
        $me = _G('ME');
        if (!$me->isAllowedTo('管理权限')) {
            $this->redirect('error/401');
        }

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            $o = $engine->searchGroups(['type' => $processName]);
            $groups = $engine->getGroups($o->token, 0, $o->total);
        } catch (\Gini\BPM\Exception $e) {
            $groups = [];
        }

        $vars = [
            'type' => 'manager',
            'data' => [
                'groups'=> $groups
            ]
        ];

        $this->view->body = V('settings/access', $vars);
    }

    public function actionQR()
    {
        $group = _G('GROUP');
        if (!$group->id) {
            return $this->redirect('error/404');
        }
        header('Pragma: no-cache');
        header('Content-type: image/png');
        $url = URL('/wechat/user-bind');
        $qrCode = new \TCPDF2DBarcode($url, 'QRCODE,L');
        echo $qrCode->getBarcodePNG(4, 4);
        exit;
    }
}

