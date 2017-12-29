<?php

namespace Gini\Controller\API\Mall;

class Portal extends \Gini\Controller\API
{
    public function actionGetView($id, $mode)
    {
        list($process, $engine) = $this->_getProcessEngine();

        if (!$id || !$process->id) {
            return false;
        }

        try {
            $params['member'] = $id;
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
            $tasks = $engine->getTasks($rdata->token, 0, $rdata->total);

        } catch (\Gini\BPM\Exception $e) {
        }
        if (!count($tasks)) return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('review/list-none'));

        $orders = [];
        foreach ($tasks as $task) {
            try {
                $instance = $engine->processInstance($task->processInstanceId);
                $object = $this->_getOrderObject($instance);
                $orders[$task->id] = $object;
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        $base_url = \Gini\URI::base();
        return (string) V('portal/order-review',[
            'url' => $base_url.'option',
            'userId' => $id,
            'orders'=> $orders,
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]);
    }


    public function actionHasPerm($userId, $mode)
    {
        switch ($mode) {
            case 'admin_order_review':
                $result = $this->_hasOrderReviewPerm($userId, $mode);
                break;
        }

        return $result;
    }

    private function _hasOrderReviewPerm($uid, $mode)
    {
        $user = a('user', (int)$uid);
        if (!$user->id) return fasle;
        $perms = [];
        list($process, $engine) = $this->_getProcessEngine();
        try {
            $params['member'] = $user->id;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);
            if (!count($groups)) return $perms;
            $perms[] = $mode;
            return $perms;
        } catch (\Gini\BPM\Exception $e) {
            return $perms ;
        }
    }

    private function _getOrderObject($instance)
    {
        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);

        return $data;
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
}
