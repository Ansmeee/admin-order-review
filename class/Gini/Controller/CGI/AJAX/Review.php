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
            return $this->_showMoreInstance($page, $q);
        }

        return $this->_showMoreTask($page, $q);
    }

    private function _showMoreInstance($page, $querystring=null)
    {
        $me = _G('ME');
        $limit = 25;
        $start = ($page - 1) * $limit;

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;

        $instances = $process->getInstances($start, $limit, $me);
        if (!count($instances)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
        }
        $totalCount = $process->searchInstances($me);

        $objects = [];
        foreach ($instances as $instance) {
            $object = new \stdClass();
            $object->instance = $instance;
            $object->order = $this->_getInstanceObject($instance);
            $object->status = $this->_getInstanceStatus($engine, $instance);
            $objects[$instance->id] = $object;
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-instances', [
            'instances'=> $objects,
            'type'=> $type,
            'page'=> $page,
            'total'=> ceil($totalCount/$limit)
        ]));
    }

    private function _getInstanceStatus($engine, $instance)
    {
        if ($instance->status == \Gini\Process\IInstance::STATUS_END) {
            return T('已结束');
        }

        $tasks = $engine->those('task')
                ->whose('instance')->is($instance)
                ->orderBy('ctime', 'desc')
                ->orderBy('id', 'desc');
        while (true) {
            $task = $tasks->current();
            if ($task->auto_callback) {
                $tasks->next();
                continue;
            }
            if (!$task->id) break;
            $status_title = T('待 :group 审批', [
                ':group'=> $task->candidate_group->title
            ]);
            break;
        }
        return $status_title ?: T('正在初始化');
    }

    private function _showMoreTask($page, $querystring=null)
    {
        $me = _G('ME');
        $limit = 25;
        $start = ($page - 1) * $limit;

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) return;

        $tasks = $engine->those('task')
            ->whose('process')->is($process)
            ->whose('candidate_group')->isIn($process->getGroups($me))
            ->whose('status')->is(\Gini\Process\ITask::STATUS_PENDING)
            ->limit($start, $limit);

        if (!count($tasks)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));
        }

        $orders = [];
        foreach ($tasks as $task) {
            $orders[$task->id] = $this->_getInstanceObject($task->instance);
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-tasks', [
            'orders'=> $orders,
            'type'=> $type,
            'page'=> $page,
            'total'=> ceil($tasks->totalCount()/$limit)
        ]));
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

        $task = $engine->getTask($id);
        if ($key=='approve') {
            $bool = $task->approve($note);
        } else {
            $bool = $task->reject($note);
        }

        $bool && $task->complete();

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'id'=> $id,
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
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

        $task = $engine->getTask($id);
        if (!$task->id) return;
        if (!$task->candidate_group->id) return;

        $groups = $process->getGroups($me);
        foreach ($groups as $g) {
            if ($task->candidate_group->id==$g->id) {
                return true;
            }
        }
    }

    private function _getProcessEngine()
    {
        $processName = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\Process\Engine::of('default');
        $process = $engine->getProcess($processName);
        return [$process, $engine];
    }

    private function _getInstanceObject($instance, $force=false)
    {
        $data = $instance->getVariable('data');
        // TODO 移除测试数据
        $force = false;
        $data = [
            'id'=> 1,
            'voucher'=> 'M201608020001',
            'price'=> round('1000', 2),
            'ctime'=> date('Y-m-d H:i:s'),
            'status'=> 1,
            'group_id'=> 1,
            'vendor_id'=> 1,
            'user_id'=> 1,
            'items'=> []
        ];
        $voucher = $data['voucher'];
        if ($force) {
            $order = a('order', ['voucher'=> $voucher]);
        } else {
            $order = a('order');
            $order->setData($data);
        }

        return $order;
    }

    public function actionInstance($id)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $processName = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\Process\Engine::of('default');

        $instance = $engine->fetchProcessInstance($processName, $id);
        if (!$instance || !$instance->id) return;

        $order = $this->_getInstanceObject($instance, true);
        if (!$order->id) return;
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order
        ]));
    }

    public function actionTask($id)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $processName = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\Process\Engine::of('default');

        $task = $engine->getTask($id);
        if (!$task || !$task->id) return;

        $order = $this->_getInstanceObject($task->instance, true);
        if (!$order->id) return;
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/info', [
            'order'=> $order
        ]));
    }

    public function actionPreview($instanceID)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $processName = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\Process\Engine::of('default');
        $instance = $engine->fetchProcessInstance($processName, $instanceID);
        if (!$instance || !$instance->id) return;

        $tasks = $engine->those('task')
            ->whose('instance')->is($instance)
            ->whose('status')->isIn([
                \Gini\Process\ITask::STATUS_APPROVED,
                \Gini\Process\ITask::STATUS_UNAPPROVED,
            ])
            ->orderBy('ctime', 'desc');
        $data = [];
        foreach ($tasks as $task) {
            if ($task->auto_callback) continue;
            $data[$task->id] = $task;
        }

        $vars = [
            'tasks'=> $data
        ];
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/preview', $vars));
    }

}
