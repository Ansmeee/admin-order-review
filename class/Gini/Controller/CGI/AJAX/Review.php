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
        $limit = 15;
        $start = ($page - 1) * $limit;
        $instances = [];
        $objects = [];

        $user = $me->isAllowedTo('管理权限') ? null : $me;
        if (!$current_group) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

        try {
            // 权限判断
            list($process, $engine) = $this->_getProcessEngine();
            $candidateGroup         = $engine->group($current_group);
            $isMemberOfGroup        = $candidateGroup->hasMember($me->id);
            if ($candidateGroup->type != $process->id) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            if ($user->id && !$isMemberOfGroup) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

            // 构造检索条件
            $searchInstanceParams['orderBy']         = ['startTime' => 'desc'];
            $searchInstanceParams['key']             = $process->id;
            if ($this->_getCurrentGroupCode($candidateGroup->id)) {
                $searchInstanceParams['candidate_group'] = $this->_getCurrentGroupCode($candidateGroup->id);
            }

            $driver = \Gini\Process\Driver\Engine::of('bpm2');
            $rdata = $driver->searchInstances($searchInstanceParams);
            if (!$rdata['total']) {
                return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
            }
            $instances = $driver->getInstances($rdata['token'], $start, $limit);

            foreach ($instances as $oinstance) {
                $instance           = $engine->ProcessInstance($oinstance->id);
                $object             = new \stdClass();
                $object->instance   = $oinstance->id;
                $object->order      = $this->_getInstanceObject($instance);
                $object->status     = $this->_getInstanceStatus($instance);
                $objects[$oinstance->id] = $object;
            }
        } catch (\Gini\BPM\Exception $e){
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-instances', [
            'instances'     => $objects,
            'type'          => $type,
            'group'         => $candidateGroup->id,
            'page'          => $page,
            'total'         => ceil($rdata['total']/$limit),
            'vTxtTitles'    => \Gini\Config::get('haz.types')
        ]));
    }

    private function _getSearchGroup($group)
    {
        $conf       = \Gini\Config::get('app.order_review_process');
        $steps      = array_keys($conf['steps']);
        $groupArr   = explode('-', $group);
        $groupCode  = end($groupArr);
        if (!in_array($groupCode, $steps)) {
            return $groupCode;
        }
        return false;
    }

    private function _getInstanceStatus($instance)
    {

        try {
            $params['variableName'] = 'status';
            $rdata = (array) $instance->getVariables($params);
            $value = current($rdata)['value'];
            switch ($value) {
                case 'active':
                    $status = T('待审批');
                    break;
                case 'approved':
                    $status = T('已通过');
                    break;
                case 'rejected':
                    $status = T('已拒绝');
                    break;
            }
        } catch (\Gini\BPM\Exception $e) {
            return T('系统处理中');
        }

        return $status ?: T('系统处理中');
    }

    private function _getTaskStatus($engine, $task)
    {
        try {
            $group = $engine->group($task->assignee);
        } catch (\Gini\BPM\Exception $e) {
            return T('等待审批');
        }

        return T('等待 :group 审批', [':group' => $group->name]);
    }

    private function _getTaskObject($task)
    {
        $rdata = $task->getVariables('data');
        $data = json_decode($rdata['value']);

        return $data;
    }

    private function _showMoreTask($page, $querystring=null)
    {
        $me = _G('ME');
        $limit = 20;
        $start = ($page - 1) * $limit;
        $orders = [];

        try {
            // 构造搜索条件
            list($process, $engine) = $this->_getProcessEngine();
            $params['member']   = $me->id;
            $params['type']     = $process->id;
            $o      = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);

            if (!count($groups)) throw new \Gini\BPM\Exception();

            $search_params['candidateGroup']        = array_keys($groups);
            $search_params['includeAssignedTasks']  = true;
            $search_params['sortBy']                = ['created' => 'desc'];
            $rdata = $engine->searchTasks($search_params);
            $tasks = $engine->getTasks($rdata->token, $start, $limit);
            if (!count($tasks)) throw new \Gini\BPM\Exception();
        } catch (\Gini\BPM\Exception $e) {
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
        }

        // 处理数据
        foreach ($tasks as $id => $task) {
            try {
                $object = $this->_getTaskObject($task);
                $object->instanceId = $task->processInstanceId;
                $object->task_status = $this->_getTaskStatus($engine, $task);
                $orders[$id] = $object;
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

    private function _getInstanceObject($instance, $force = false)
    {
        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);

        if ($force) {
            $order = a('order', ['voucher' => $data->voucher]);
            if ($order->id) {
                $data  = $order;
            }
        }

        return $data;
    }

    public function actionGetOPForm()
    {
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
        // 验证 用户
        $me     = _G('ME');
        $group  = _G('GROUP');
        if (!$me->id || !$group->id) {
            return ;
        }

        // 获取 表单信息
        $form   = $this->form();
        $key    = $form['key'];
        $id     = $form['id'];
        $note   = $form['note'];

        // 实例化 process 和 engine, task
        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;
        try {
            $task               = $engine->task($id);
            $instance           = $engine->processInstance($task->processInstanceId);
            $candidateGroup     = $engine->group($task->assignee);
        } catch (\Gini\BPM\Exception $e) {
            return ;
        }

        // 是否可以操作这个 task
        $params = [
            'user'              => $me,
            'task'              => $task,
            'candidateGroup'    => $candidateGroup,
            'instance'          => $instance,
            'process'           => $process,
            'form'              => $form
        ];
        if (!$this->_isAllowToOP($params)) return;

        try {
            // 获取订单的数据 以及 task 的审批组
            $rdata              = $task->getVariables('data');
            $orderData          = (array)json_decode($rdata['value']);

            // 操作远程 task 需要的参数
            $data['task']           = $task;
            $data['instance']       = $instance;
            $data['engine']         = $engine;
            $data['step']           = $this->_getCurrentStep($task->assignee);
            $data['candidateGroup'] = $candidateGroup->name;
            $data['message']        = $note;

            // 操作本地订单记录 需要的参数
            $updateData['message']            = $note;
            $updateData['candidateGroup']     = $candidateGroup->name;
            $updateData['orderData']          = $orderData;
            $updateData['voucher']            = $orderData['voucher'];
            $updateData['customized']         = $orderData['customized'];
            $updateData['type']               = \Gini\ORM\Order::OPERATE_TYPE_APPROVE;

            if ($key=='approve') {
                // 结束远程的 task 同时记录操作记录
                $data['opt'] = true;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception();
                $updateData['opt']                = T('审核通过');
            } else {
                // 结束远程的 task 同时记录操作记录
                $data['opt'] = false;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception();
                $updateData['opt']                = T('审核拒绝');
            }
            // 更新本地订单的操作信息
            $this->_doUpdate($updateData, $me);
        } catch (\Gini\BPM\Exception $e) {
            $bool = false;
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'id'=> $id,
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
    }

    private function _doUpdate($data, $user)
    {
        try {
            $rpc = \Gini\Module\AppBase::getAppRPC('order');
            if (!$rpc) return false;
            // 更新订单的跟踪信息
            $now = date('Y-m-d H:i:s');
            $bool = $rpc->mall->order->updateOrder($data['voucher'], [
                'hash_rand_key' => $now,
                'description'   => [
                    'a' => T('**:group** **:name** **:opt**', [
                        ':group'    => $data['candidateGroup'],
                        ':name'     => $user->name,
                        ':opt'      => $data['opt']
                    ]),
                    't' => $now,
                    'u' => $user->id,
                    'd' => $data['message'],
                ]
            ]);

            // 在mall-old 记录操作记录
            if (!$data['customized']) {
                $params = [
                    ':voucher'      => $data['voucher'],
                    ':date'         => date('Y-m-d H:i:s'),
                    ':operator'     => $user->id,
                    ':type'         => $data['type'],
                    ':name'         => $user->name,
                    ':description'  => $data['candidateGroup'].T('审批人'),
                ];
                $db = \Gini\Database::db('mall-old');
                $sql = "insert into order_operate_info (voucher,operate_date,operator_id,type,name,description) values (:voucher, :date, :operator, :type, :name, :description)";
                $db->query($sql, null, $params);
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    private function _getCurrentStep($assignee)
    {
        $conf = \Gini\Config::get('app.order_review_process');
        $steps = array_keys($conf['steps']);
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

    private function _addComment($engine, $instance, array $comment)
    {
        $his_comment = [];
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

    private function _completeTask($criteria = [])
    {
        $task       = $criteria['task'];
        $instance   = $criteria['instance'];
        $engine     = $criteria['engine'];
        $step       = $criteria['step'];
        $option     = $criteria['opt'] ? T('审批通过') : T('审批拒绝');
        try {
            // 记录 instance 的操作信息
            $comment = [
                'message'   => $criteria['message'],
                'group'     => $criteria['candidateGroup'],
                'user'      => _G('ME')->name,
                'option'    => $option,
                'date'      => date('Y-m-d H:i:s')
            ];
            $res = $this->_addComment($engine, $instance, $comment);
            if ($res) {
                // 结束这个 task
                $params[$step]   = $criteria['opt'] ? true : false;
                $bool            = $task->complete($params);
            }
        } catch (\Gini\BPM\Exception $e) {
            return false;
        }

        return $bool;
    }

    private function _isAllowToOP($criteria = [])
    {
        $key            = $criteria['form']['key'];
        $user           = $criteria['user'];
        $task           = $criteria['task'];
        $candidateGroup = $criteria['candidateGroup'];
        $instance       = $criteria['instance'];
        $process        = $criteria['process'];

        // 参数是否合法
        if (!$task->id || !$instance->id || !$process->id || !$candidateGroup->id || !in_array($key, ['approve', 'reject'])) return;

        try {
            // 是否在 这些组里
            if ($candidateGroup->hasMember($user->id)) {
                return true;
            }
        } catch (\Gini\BPM\Exception $e) {
            return ;
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
        $order = $this->_getInstanceObject($instance, true);
        if (!$order->id) return;
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order,
            'vTxtTitles' => \Gini\Config::get('haz.types'),
            'type' => 'history',
            'instance' => $instance,
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
            $order = $this->_getTaskObject($task);
            if (!$order->id) return;
        } catch (\Gini\BPM\Exception $e) {
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order,
            'task'=> $task,
            'type'=> 'pending',
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
                $task       = $engine->task($id);
                $instance   = $engine->processInstance($task->processInstanceId);

                // 获取订单的数据 以及 task 的审批组
                $rdata              = $task->getVariables('data');
                $orderData          = (array)json_decode($rdata['value']);
                $candidateGroup     = $engine->group($task->assignee);

                // 操作远程 task 需要的参数
                $data['task']           = $task;
                $data['instance']       = $instance;
                $data['engine']         = $engine;
                $data['step']           = $this->_getCurrentStep($task->assignee);
                $data['candidateGroup'] = $candidateGroup->name;
                $data['message']        = $note;

                // 操作本地订单记录 需要的参数
                $updateData['message']            = $note;
                $updateData['candidateGroup']     = $candidateGroup->name;
                $updateData['orderData']          = $orderData;
                $updateData['voucher']            = $orderData['voucher'];
                $updateData['customized']         = $orderData['customized'];
                $updateData['type']               = \Gini\ORM\Order::OPERATE_TYPE_APPROVE;

                if ($key=='approve') {
                    // 结束远程的 task 同时记录操作记录
                    $data['opt'] = true;
                    $bool = $this->_completeTask($data);
                    if (!$bool) throw new \Gini\BPM\Exception();

                    $updateData['opt']                = T('审核通过');
                } else {
                    // 结束远程的 task 同时记录操作记录
                    $data['opt'] = false;
                    $bool = $this->_completeTask($data);
                    if (!$bool) throw new \Gini\BPM\Exception();

                    $updateData['opt']                = T('审核拒绝');
                }

                // 更新本地订单的操作信息
                $this->_doUpdate($updateData, $me);
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'ids'=> $ids,
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
    }
}
