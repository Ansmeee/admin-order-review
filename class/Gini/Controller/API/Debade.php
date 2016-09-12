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

        $processName = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\Process\Engine::of('default');

        $instanceID = $this->_getOrderInstanceID($processName, $data['voucher']);

        $cacheData = $message;
        $cacheData['data']['items'] = json_encode($message['data']['items']);
        if ($instanceID) {
            $instance = $engine->fetchProcessInstance($processName, $instanceID);
            if (!$instance->id || $instance->status==\Gini\Process\IInstance::STATUS_END) {
                $instance = $engine->startProcessInstance($processName, $cacheData);
            }
        } else {
            $instance = $engine->startProcessInstance($processName, $cacheData, "order#{$data['voucher']}");
        }

        if ($instance->id && $instance->id!=$instanceID) {
            $this->_setOrderInstanceID($processName, $data['voucher'], $instance->id);
        }
    }

    private function _getOrderInstanceID($processName, $voucher)
    {
        $key = "order#{$voucher}";
        $info = (array)\Gini\TagDB\Client::of('default')->get($key);
        //$info = [ 'bpm'=> [ $processName=> [ 'instances'=> [ $instanceID, $latestinstanceid ] ] ] ]
        $info = (array)@$info['bpm'][$processName]['instances'];
        return array_pop($info);
    }

    private function _setOrderInstanceID($processName, $voucher, $instanceID)
    {
        $key = "order#{$voucher}";
        $info = (array)\Gini\TagDB\Client::of('default')->get($key);
        $info['bpm'][$processName]['instances'] = @$info['bpm'][$processName]['instances'] ?: [];
        array_push($info['bpm'][$processName]['instances'], $instanceID);
        \Gini\TagDB\Client::of('default')->set($key, $info);
    }

}
