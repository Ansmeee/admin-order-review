<?php

namespace Gini\Controller\CGI;

class Index extends Layout\Board{

    public function __index(){
        $this->redirect('review');
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

    public function actionHistory()
    {
        $vars = [
            'type'=> 'history'
        ];

        $this->view->body = V('review/index', $vars);
    }

    public function actionManager()
    {
        $me = _G('ME');
        if (!$me->isAllowedTo('管理权限')) {
            $this->redirect('error/401');
        }

        $engine = \Gini\Process\Engine::of('default');
        $processName = \Gini\Config::get('app.order_review_process');

        $process = $engine->getProcess($processName);
        $groups = $process->getGroups();

        $vars = [
            'type' => 'manager',
            'data' => [
                'groups'=> $groups
            ]
            
        ];

        $this->view->body = V('settings/access', $vars);
    }

}
