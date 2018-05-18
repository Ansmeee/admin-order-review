<?php

namespace Gini\Controller\API\Mall;

class Order extends \Gini\Controller\API
{
    public function actionGetComments($voucher = '')
    {
        if (!$voucher) return false;

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);

            $params['key'] = $process->id;
            $params['voucher'] = $voucher;
            $driver = \Gini\Process\Driver\Engine::of('bpm2');
            $rdata  = $driver->searchInstances($params);

            if (!$rdata['total']) {
                return false;
            }

            $instances = $driver->getInstances($rdata['token'], 0, 1);
            $instanceId = current($instances)->id;
            $instance = $engine->ProcessInstance($instanceId);

            $his_comment = [];
            $params['variableName'] = 'comment';
            $rdata = $instance->getVariables($params);
            if ($rdata) {
                $his_comment = json_decode(current($rdata)['value']);
                return $his_comment;
            }
        } catch (\Gini\BPM\Exception $e) {
        }

        return false;
    }

    // 课题组负责人可以取消审批中的订单，接口用来结束审批中的进程
    public function actionCancelOrder($voucher = '')
    {
        if (!$voucher) return false;

        $node = \Gini\Config::get('app.node');
        try {

            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);

            $tagName = "$node#order#$voucher";
            $orderInstance = a('tagdb/tag', ['name' => $tagName]);
            if (!$orderInstance->id) {
                return false;
            }

            $data = $orderInstance->data;
            $instances = $data['bpm'][$process->id]['instances'];

            $searchParams = [
                'process' => $process->id
            ];
            $completeTasks = [];
            foreach ($instances as $instanceId) {
                $instance = $engine->processInstance($instanceId);
                if (!$instance->id || $instance->state == 'COMPLETED') {
                    continue;
                }


                $searchParams['processInstance'] = $instance->id;
                $rdata = $engine->searchTasks($searchParams);
                if (!$rdata->total) {
                    continue;
                }

                $params['variableName'] = 'review_type';
                $params['value'] = 'group';
                $params['type'] = 'String';
                $result = $instance->setVariable($params);

                if (!$result) {
                    return false;
                }

                $tasks = $engine->getTasks($rdata->token, 0, 1);
                $task = current($tasks);
                if (!$task->id) {
                    continue;
                }

                $taskId = $task->id;
                $step = $this->_getCurrentStep($task->assignee);
                $params[$step]   = false;
                $bool            = $task->complete($params);
                if ($bool) {
                    $completeInstances[$instance->id] = $instance;
                    $completeTasks[] = $taskId;
                }
            }

            if (count($completeTasks)) {
                return true;
            }
        } catch (\Gini\BPM\Exception $e) {
            $instanceKeys = array_keys($completeInstances);
            $diffIds = array_diff($instances, $instanceKeys);
            if (count($diffIds)) {
                foreach($diffIds as $id) {
                    $instance = $engine->processInstance($id);
                    $params['variableName'] = 'review_type';
                    $params['value'] = 'group';
                    $params['type'] = 'String';
                    $instance->setVariable($params);
                }
            }
        }

        return false;
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
}
