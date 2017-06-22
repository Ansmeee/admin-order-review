<?php

namespace Gini\Controller\API\Mall;

class Portal extends \Gini\Controller\API
{
    public function actionGetView($user)
    {
        $user = $this->_getUserInfo($user);
        $id = $user['id'];
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

        //获取当前url
        $base_url = \Gini\URI::base();
        return (string) V('portal/order-review',[
            'url' => $base_url.'option',
            'userId' => $id,
            'orders'=> $orders,
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]);
    }

    private function _getOrderObject($instance)
    {
        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);

        return $data;
    }

    private function _getUserInfo($identity)
    {
        if (!$identity) return false;
        try {
            $infos = (array)\Gini\Config::get('gapper.auth');
            $gInfo = (object)$infos['gateway'];
            $identitySource = @$gInfo->source;
            $info = \Gini\Gapper\Client::getRPC()->gapper->user->getUserByIdentity($identitySource, $identity);
        } catch (\Exception $e) {
            return false;
        }
        return $info;
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

