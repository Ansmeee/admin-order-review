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

        $bool = $this->_checkOrderCanApprove($data);
        if (!$bool) {
            return ;
        }

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

        $cacheData['customized'] = $data['customized'] ? true : false;
        $cacheData['chemicalTypes'] = $types;
        //设置 candidate_group
        $key = "labmai-".$node."/".$data['group_id'];
        $info = (array)\Gini\TagDB\Client::of('rpc')->get($key);
        $cacheData['candidate_group'] = $info['organization']['school_code'];
        $department = $info['organization']['department_code'];
        $cacheData['department_type'] = $this->_getDepartmentType($department) ?: '';

        $steps = $conf['steps'];
        foreach ($steps as $step) {
            if ($step == 'school') continue;
            $cacheData[$step] = $processName.'-'.$step;
        }
        $cacheData['data'] = $message['data'];
        $cacheData['key'] = $processName;

        $instanceID = $this->_getOrderInstanceID($processName, $data['voucher']);
        if ($instanceID) {
            $instance = $engine->processInstance($instanceID);
            if (!$instance->id || ($instance->state == 'COMPLETED')) {
                $instance = $process->start($cacheData);
            }
        } else {
            $instance = $process->start($cacheData);
        }

        if ($instance->id && $instance->id!=$instanceID) {
            $this->_setOrderInstanceID($processName, $data['voucher'], $instance->id);
        }
    }

    // 获取院系类别
    private function _getDepartmentType($dep = '')
    {
        $departmentTypes = \Gini\Config::get('app.department_type');
        if (!count($departmentTypes)) {
            return false;
        }

        foreach ($departmentTypes as $type => $codes) {
            if (in_array($dep, explode(',', $codes))) {
                return $type;
            }
        }

        return false;
    }

    // 订单是否可以进入审批流程
    private function _checkOrderCanApprove($data)
    {
        // 定制需求 如果订单 申购人 确认人 收货人不一致需要打回
        if (\Gini\Config::Get('mall.need_different_requester_and_receiver') === true) {
            if ($data['customized']) {
                return true;
            }

            if (!$data['requester_id'] || !$data['approver_id'] || !$data['default_receiver_id']) {
                return false;
            }

            $arr[] = $data['requester_id'];
            $arr[] = $data['approver_id'];
            $arr[] = $data['default_receiver_id'];

            if (count($arr) != count(array_unique($arr))) {
                return false;
            }
        }

        return true;
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
