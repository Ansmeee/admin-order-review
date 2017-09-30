<?php

namespace Gini\Controller\CGI\AJAX;

class Review extends \Gini\Controller\CGI
{
    public function actionMore($page = 1, $type = 'pending', $current_group = '')
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
            return $this->_showMoreInstance($page, $q, $type, $current_group);
        }

        return $this->_showMoreTask($page, $q);
    }

    private function _showMoreInstance($page, $querystring=null, $type='pending', $current_group = '')
    {
        $me = _G('ME');
        $group = _G('GROUP');
        $limit = 25;
        $start = ($page - 1) * $limit;
        $instances = [];
        $objects = [];

        $user = $me->isAllowedTo('管理权限') ? null : $me;
        if (!$current_group) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

        try {
            list($process, $engine) = $this->_getProcessEngine();
            $tasks = [];
            $candidateGroup = $engine->group($current_group);
            $isMemberOfGroup = $candidateGroup->hasMember($me->id);
            if ($candidateGroup->type != $process->id) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            if ($user->id && !$isMemberOfGroup) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

            $sortBy = [
                'startTime' => 'desc'
            ];
            $params['sortBy'] = $sortBy;
            $params['candidateGroup'][] = $candidateGroup->id;
            $params['history'] = true;

            $result = $engine->searchTasks($params);
            $tasks = $engine->getTasks($result->token, $start, $limit);
            if (!count($tasks)) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

            foreach ($tasks as $task) {
                $instanceIds[] = $task->processInstanceId;
            }

            $searchInstanceParams['sortBy'] = $sortBy;
            $searchInstanceParams['history'] = true;
            $searchInstanceParams['processInstance'] = $instanceIds;
            $rdata = $engine->searchProcessInstances($searchInstanceParams);
            $instances = $engine->getProcessInstances($rdata->token, 0, $rdata->total);

            foreach ($instances as $instance) {
                $object = new \stdClass();
                $object->instance = $instance;
                $object->order = $this->_getOrderObject($instance,true);
                $object->status = $this->_getInstanceStatus($engine, $instance);
                $objects[$instance->id] = $object;
            }
        } catch (\Gini\BPM\Exception $e){
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-instances', [
            'instances'=> $objects,
            'type'=> $type,
            'group'=> $candidateGroup->id,
            'page'=> $page,
            'total'=> ceil($result->total/$limit),
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]));
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

    private function _showMoreTask($page, $querystring=null)
    {
        $me = _G('ME');
        $limit = 25;
        $start = ($page - 1) * $limit;
        list($process, $engine) = $this->_getProcessEngine();
        $params['member'] = $me->id;
        $params['type'] = $process->id;
        $o = $engine->searchGroups($params);
        $groups = $engine->getGroups($o->token, 0, $o->total);

        if (!count($groups)) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

        foreach ($groups as $group) {
            $search_params['candidateGroup'][] = $group->id;
        }
        $search_params['includeAssignedTasks'] = true;
        $sortBy = [
            'created' => 'desc'
        ];
        $search_params['sortBy'] = $sortBy;
        $rdata = $engine->searchTasks($search_params);
        $tasks = $engine->getTasks($rdata->token, $start, $limit);

        if (!count($tasks)) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

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
            'total'=> ceil(($rdata->total)/$limit),
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

        if (\Gini\Config::get('app.is_show_order_reagent_purpose') === true) {
            $order->purpose = $data->purpose;
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

    private function _addComment($engine, $task, array $comment) {
        $his_comment = [];
        $instance = $engine->processInstance($task->processInstanceId);
        $params['variableName'] = 'comment';
        $rdata = $instance->getVariables($params);
        if ($rdata) {
            $his_comment = json_decode(current($rdata)['value']);
        }
        array_push($his_comment, $comment);
        $params['value'] = json_encode($his_comment);
        $params['type'] = 'Json';
        $result = $instance->setVariable($params);
        return $result;
    }

    private function _approve($engine, $task, $message = '') {
        $comment = [];
        try {
            $rData = $task->getVariables('data');
            $order_data = (array) json_decode($rData['value']);
            $assignee = $task->assignee;
            $candidate_group = $engine->group($assignee);
            $comment = [
                'message' => $message,
                'group' => $candidate_group->name,
                'user' => _G('ME')->name,
                'date' => date('Y-m-d H:i:s')
            ];
            $res = $this->_addComment($engine, $task, $comment);
            if ($res) {
                $opt = $this->_getCurrentStep($assignee);
                $params[$opt] = true;
                $bool = $task->complete($params);
                if ($bool) {
                    $data['opt'] = T('审核通过');
                    $data['message'] = $message;
                    $data['candidate_group'] = $candidate_group->name;
                    $data['order_data'] = $order_data;
                    $this->_doUpdate($data);
                }
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
            $comment = [
                'message' => $message,
                'group' => $candidate_group->name,
                'user' => _G('ME')->name,
                'date' => date('Y-m-d H:i:s')
            ];
            $res = $this->_addComment($engine, $task, $comment);
            if ($res) {
                $opt = $this->_getCurrentStep($assignee);
                $params[$opt] = false;
                $bool = $task->complete($params);
                if ($bool) {
                    $data['opt'] = T('拒绝');
                    $data['message'] = $message;
                    $data['candidate_group'] = $candidate_group->name;
                    $data['order_data'] = $order_data;
                    $this->_doUpdate($data);
                }
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
            $comments = [];
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            $instance = $engine->processInstance($instanceID);
            if (!$instance->id) return;
            $params['variableName'] = 'comment';
            $rdata = $instance->getVariables($params);
            if (is_array($rdata) && count($rdata)) {
                $comments = json_decode(current($rdata)['value']);
            }
        } catch (\Gini\BPM\Exception $e) {
        }
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/preview', ['comments' => $comments]));
    }

    public function actionGetBatchOPForm()
    {
        $form = $this->form();
        $key = $form['key'];
        $ids= $form['ids'];
        if (!strlen($ids)) {
            $response = [
                'code' => true,
                'message' =>  T('请选择要审批的订单')
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/op-form', [
            'ids'=> $ids,
            'key'=> $key,
            'batch'=> true,
            'title'=> $key=='approve' ? T('通过') : T('拒绝')
        ]));
    }

    public function actionPostBatch()
    {
        $me = _G('ME');
        $group =_G('GROUP');
        if (!$group->id || !$me->id) return ;


        $form = $this->form();
        $key = $form['key'];
        if (!strlen($form['ids'])) {
            $response = [
                'code' => true,
                'message' =>  T('请选择要审批的订单')
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        $ids = explode(',', $form['ids']);
        $note = $form['note'];

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;

        foreach ($ids as $id) {
            try {
                $task = $engine->task($id);
                $rdata = $task->getVariables('data');
                $data = (array)json_decode($rdata['value']);
                $candidate_group = $engine->group($task->assignee);
            } catch (\Gini\BPM\Exception $e) {
                continue;
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
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'ids'=> $ids,
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
    }
}
