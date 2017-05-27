<?php

namespace Gini\Controller\API;

class Debade extends \Gini\Controller\API
{
    public function actionGetNotified($message)
    {
        $hash = $_SERVER['HTTP_X_DEBADE_TOKEN'];
        $secret = \Gini\Config::get('app.debade_secret');
        $str = file_get_contents('php://input');

        if ($hash != \Gini\DeBaDe::hash($str, $secret)) {
            return;
        }

        $id = strtolower($message['id']);
        if ($id=='lab-order') {
            return $this->_getLabOrderNotified($message);
        }
    }

    private function _getLabOrderNotified($message)
    {
        if (!isset($message['data']['voucher'])) return;
        $data = $message['data'];

        if ($data['status']!=\Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE) return;

        $node = \Gini\Config::get('app.node');
        $conf = \Gini\Config::get('app.order_review_process');
        $processName = $conf['name'];

        try {
            $engine =  \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($processName);
        } catch (\Gini\BPM\Exception $e) {
        }

        $types = [];
        $items = (array)$message['data']['items'];
        foreach ($items as $item) {
            $casNO = $item['cas_no'];
            $chem_types = (array) \Gini\ChemDB\Client::getTypes($casNO)[$casNO];
            $types = array_unique(array_merge($types, $chem_types));
        }
        
        $cacheData['customized'] = $data['customized'];
        $cacheData['chemicalTypes'] = $types;

        //设置 candidate_group
        $key = "labmai-".$node."/".$data['group_id'];
        $info = (array)\Gini\TagDB\Client::of('rpc')->get($key);
        $cacheData['candidate_group'] = (int)$info['organization']['school_code'];

        $steps = $conf['steps'];
        foreach ($steps as $step) {
            if ($step == 'school') continue;
            $cacheData[$step] = $step;
        }

        $cacheData['data'] = $message['data'];
        $cacheData['key'] = $processName;
        $cacheData['tag'] = $data['voucher'];

        $instanceID = $this->_getOrderInstanceID($processName, $data['voucher']);
        if ($instanceID) {
            $instance = $engine->processInstance($instanceID);
            if (!$instance->id) {
                $instance = $process->start($cacheData);
            }
        } else {
            $instance = $process->start($cacheData);
        }

        if ($instance->id && $instance->id!=$instanceID) {
            $this->_setOrderInstanceID($processName, $data['voucher'], $instance->id);
        }
    }

    private function _getOrderInstanceID($processName, $voucher)
    {
        $node = \Gini\Config::get('app.node');
        $key = "{$node}#order#{$voucher}";
        $info = (array)\Gini\TagDB\Client::of('default')->get($key);
        //$info = [ 'bpm'=> [ $processName=> [ 'instances'=> [ $instanceID, $latestinstanceid ] ] ] ]
        $info = (array) $info['bpm'][$processName]['instances'];
        return array_pop($info);
    }

    private function _setOrderInstanceID($processName, $voucher, $instanceID)
    {
        $node = \Gini\Config::get('app.node');
        $key = "{$node}#order#{$voucher}";
        $info = (array)\Gini\TagDB\Client::of('default')->get($key);
        $info['bpm'][$processName]['instances'] = $info['bpm'][$processName]['instances'] ?: [];
        array_push($info['bpm'][$processName]['instances'], $instanceID);
        \Gini\TagDB\Client::of('default')->set($key, $info);
    }
}
