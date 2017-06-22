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
        $user = $me->isAllowedTo('管理权限') ? null : $me;
        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);
            $params['member'] = $user->id;
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

    public function actionOption($uId, $taskId)
    {
        $me = _G('ME');
        $gorup = _G('GROUP');
        $user = a('user', (int)$uId);
        if (!$gorup->id || !$user->id || !$me->id || $me->id != $user->id) {
            $this->redirect('error/401');
        }

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);

            $task = $engine->task($taskId);
            if (!$task->processInstanceId) {
                $this->redirect('pending');
            }
            $instance = $engine->processInstance($task->processInstanceId);
            $params['variableName'] = 'data';
            $rdata = $instance->getVariables($params);
            $object = json_decode(current($rdata)['value']);
            $object->instance = $instance;
            $object->task_status = $this->_getInstanceStatus($engine, $instance);
        } catch (\Gini\BPM\Exception $e) {
        }

        $this->view->body = V('portal/list-task',[
            'task' => $task,
            'order' => $object,
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]);
    }

    private function _getInstanceStatus($engine, $instance)
    {
        try {
            if ($instance->state === 'COMPLETED') {
                return T('已结束');
            }

            $params['processInstance'] = $instance->id;
            $o = $engine->searchTasks($params);
            $tasks = $engine->getTasks($o->token, 0, $o->total);
            $task = current($tasks);
            $group = $engine->group($task->assignee);

            return T('等待 :group 审批', [':group' => $group->name]);
        } catch (\Gini\BPM\Exception $e) {
        }
    }
}

