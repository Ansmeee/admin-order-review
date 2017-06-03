<?php

namespace Gini\Controller\CGI\AJAX;

class Review extends \Gini\Controller\CGI
{
    public function actionMore($page = 1, $type = 'pending')
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $page = (int) max($page, 1);
        $form = $this->form();
        $q = $form['q'];
        $type = strtolower($type);

        if ($type=='history') {
            return $this->_showMoreInstance($page, $q, $type);
        }

        return $this->_showMoreTask($page, $q);
    }

    private function _showMoreInstance($page, $querystring=null, $type='pending')
    {
        $me = _G('ME');
        $group = _G('GROUP');
        $limit = 25;
        $start = ($page - 1) * $limit;

        try {
            list($process, $engine) = $this->_getProcessEngine();
            if (!$process->id) return;

            $user = $me->isAllowedTo('管理权限') ? null : $me;
            $params['process'] = $process->id;
            $params['history'] = true;

            $o = $engine->searchProcessInstances($params);
            $instances = $engine->getProcessInstances($o->token, $start, $limit);

            if (!count($instances)) {
                return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            }
            $objects = [];

            foreach ($instances as $instance) {
                $object = new \stdClass();
                $object->instance = $instance;
                $object->order = $this->_getOrderObject($instance,true);
                $object->status = $this->_getInstanceStatus($engine, $instance);
                $objects[$instance->id] = $object;
            }
        } catch (\Gini\BPM\Exception $e) {
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-instances', [
            'instances'=> $objects,
            'type'=> $type,
            'page'=> $page,
            'total'=> ceil($o->total/$limit),
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]));
    }

    private function _getInstanceStatus($engine, $instance)
    {
        try {
            if ($instance->state === 'COMPLETED') {
                return T('已结束');
            }

            $params['instance'] = $instance->id;
            $o = $engine->searchTasks($params);
            $tasks = $engine->getTasks($o->token, 0, $o->total);
            $task = current($tasks);
            $group = $engine->group($task->assignee);

            return T('等待 :group 审批', [':group' => $group->name]);
        } catch (\Gini\BPM\Exception $e) {
        }
    }

    private function _showMoreTask($page, $querystring=null)
    {
        $me = _G('ME');
        $limit = 25;
        $start = ($page - 1) * $limit;
        list($process, $engine) = $this->_getProcessEngine();

        try {
            $params['member'] = $me->id;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);

            if (!count($groups)) {
                return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            }

            foreach ($groups as $group) {
                $search_params['candidateGroups'][] = $group->id;
            }

            $search_params['includeAssignedTasks'] = true;
            $o = $engine->searchTasks($search_params);
            $tasks = $engine->getTasks($o->token, $start, $limit);

            if (!count($tasks)) {
                return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            }

        } catch (\Gini\BPM\Exception $e) {
        }

        $orders = [];
        foreach ($tasks as $task) {
            try {
                $instance = $engine->processInstance($task->processInstanceId);
                $object = $this->_getOrderObject($instance);
                $object->instance = $instance;
                $object->task_status = $this->_getInstanceStatus($engine, $instance);
                $orders[$task->id] = $object;
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-tasks', [
            'orders'=> $orders,
            'page'=> $page,
            'total'=> ceil(($o->total)/$limit),
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]));
    }

    private function _getOrderObject($instance, $force=false)
    {
        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);

        if ($force) {
            $order = a('order', ['voucher' => $data->voucher]);
        }

        if (!$order || !$order->id) {
            $order = $data;
        }

        return $order;
    }

    public function actionGetOPForm()
    {
        if (!$this->_isAllowToOP()) return;

        $form = $this->form();
        $key = $form['key'];
        $id = $form['id'];

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/op-form', [
            'id'=> $id,
            'key'=> $key,
            'title'=> $key=='approve' ? T('通过') : T('拒绝')
        ]));
    }

    public function actionPost()
    {
        if (!$this->_isAllowToOP()) return;

        $form = $this->form();
        $key = $form['key'];
        $id = $form['id'];
        $note = $form['note'];

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;

        $me = _G('ME');
        try {
            $task = $engine->task($id);
            $rdata = $task->getVariables('data');
            $data = (array)json_decode($rdata['value']);
            $candidate_group = $engine->group($task->assignee);
        } catch (\Gini\BPM\Exception $e) {
        }

        if ($key=='approve') {
            $db = \Gini\Database::db('mall-old');
            $db->beginTransaction();
            try {
                $params = [
                    ':voucher' => $data['voucher'],
                    ':date' => date('Y-m-d H:i:s'),
                    ':operator' => $me->id,
                    ':type' => \Gini\ORM\Order::OPERATE_TYPE_APPROVE,
                    ':name' => $me->name,
                    ':description' => $candidate_group->name.T('审批人'),
                ];

                $sql = "insert into order_operate_info (voucher,operate_date,operator_id,type,name,description) values (:voucher, :date, :operator, :type, :name, :description)";
                $query = $db->query($sql, null, $params);

                if (!$query) throw new \Exception();
                $bool = $this->_approve($engine, $task, $note);
                if (!$bool) throw new \Exception();
                $db->commit();
            } catch (\Exception $e) {
                $bool = false;
                $db->rollback();
            }
        } else {
            $bool = $this->_reject($engine, $task, $note);
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'id'=> $id,
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
    }

    private function _doUpdate($data, $user=null)
    {
        $now = date('Y-m-d H:i:s');
        $user = $user ?: _G('ME');
        $description = [
            'a' => T('**:group** **:name** **:opt**', [
                ':group'=> $data['candidate_group'],
                ':name' => $user->name,
                ':opt' => $data['opt']
            ]),
            't' => $now,
            'u' => $user->id,
            'd' => $data['message'],
        ];

        $customizedMethod = ['\\Gini\\Process\\Engine\\SJTU\\Task', 'doUpdate'];
        if (method_exists('\\Gini\\Process\\Engine\\SJTU\\Task', 'doUpdate')) {
            $bool = call_user_func($customizedMethod, $data['order_data'], $description);
        }
        return $bool;
    }

    private function _getCurrentStep($assignee)
    {
        $conf = \Gini\Config::get('app.order_review_process');
        $steps = $conf['steps'];
        $step_arr = explode('-', $assignee);
        foreach ($step_arr as $step) {
            if (in_array($step, $steps)) {
                $now_step = $step;
                break;
            }
        }
        $opt = $now_step.'_'.$conf['option'];
        return $opt;
    }

    private function _approve($engine, $task, $message = '') {
        try {
            $rData = $task->getVariables('data');
            $order_data = (array) json_decode($rData['value']);
            $assignee = $task->assignee;
            $candidate_group = $engine->group($assignee);
            $opt = $this->_getCurrentStep($assignee);
            $params[$opt] = true;
            $params[$opt.'_comment'] = [
                'message' => $message,
                'group' => $candidate_group->name,
                'user' => _G('ME')->name,
                'date' => date('Y-m-d H:i:s')
            ];

            $bool = $task->complete($params);
            if ($bool) {
                $data['opt'] = T('审核通过');
                $data['message'] = $message;
                $data['candidate_group'] = $candidate_group->name;
                $data['order_data'] = $order_data;
                $this->_doUpdate($data);
            }
        } catch (\Gini\BPM\Exception $e) {
        }

        return $bool;
    }

    private function _reject($engine, $task, $message = '') {
        try {
            $rData = $task->getVariables('data');
            $order_data = (array) json_decode($rData['value']);
            $assignee = $task->assignee;
            $candidate_group = $engine->group($assignee);
            $opt = $this->_getCurrentStep($assignee);
            $params[$opt] = false;
            $params[$opt.'_comment'] = [
                'message' => $message,
                'group' => $candidate_group->name,
                'user' => _G('ME')->name,
                'date' => date('Y-m-d H:i:s')
            ];

            $bool = $task->complete($params);
            if ($bool) {
                $data['opt'] = T('拒绝');
                $data['message'] = $message;
                $data['candidate_group'] = $candidate_group->name;
                $data['order_data'] = $order_data;
                $this->_doUpdate($data);
            }
        } catch (\Gini\BPM\Exception $e) {
        }
        return $bool;
    }

    private function _isAllowToOP()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $form = $this->form();
        $key = $form['key'];
        $id = $form['id'];

        if (!$id || !in_array($key, ['approve', 'reject'])) return;

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;

        try {
            $task = $engine->task($id);
            $instance_id = $task->processInstanceId;
            $params['member'] = $me->id;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);

            if (!$task->id || !$instance_id || !count($groups)) return;

            $candidate_groups = [];
            foreach ($groups as $g) {
                $candidate_groups[] = $g->id;
            }

            $assignee_group = $task->assignee;
            if (in_array($assignee_group, $candidate_groups)) {
                return true;
            }
        } catch (\Gini\BPM\Exception $e) {
        }

        return;
    }

    private function _getProcessEngine()
    {
        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);
        } catch (\Gini\BPM\Exception $e) {
        }
        return [$process, $engine];
    }

    public function actionInstance($id)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $conf = \Gini\Config::get('app.order_review_process');
        $processName = $conf['name'];
        $engine = \Gini\BPM\Engine::of('order_review');

        $instance = $engine->processInstance($id);
        if (!$instance || !$instance->id) return;
        $order = $this->_getOrderObject($instance);
        if (!$order->id) return;
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order,
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]));
    }

    public function actionTask($id)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            $task = $engine->task($id);
            if (!$task->id) return;
            $instance = $engine->processInstance($task->processInstanceId);
            $order = $this->_getOrderObject($instance);
            if (!$order->id) return;
        } catch (\Gini\BPM\Exception $e) {
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order,
            'task'=> $task
        ]));
    }

    public function actionPreview($instanceID)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            $instance = $engine->processInstance($instanceID);
            if (!$instance->id) return;
            $params['variableName'] = '$=comment';
            $rdata = $instance->getVariables($params);
        } catch (\Gini\BPM\Exception $e) {
        }

        if (!empty($rdata)) {
            $comments = [];
            foreach ($rdata as $variable) {
                $comment = json_decode($variable['value']);
                $comments[] = $comment;
            }
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/preview', ['comments' => $comments]));
    }
}
