<?php

namespace Gini\Controller\CGI\Third;

class Order extends \Gini\Controller\Rest
{
    public function postTest()
    {
        $form = $this->form('post');
        error_log(J($form));
    }

    // 用于 将需要审批的订单数据推送至 中爆
    public function postToZB()
    {
        $content    = file_get_contents('php://input');
        $rdata      = explode('&', $content);
        $client     = json_decode($rdata[0]);
        $order_data = json_decode($rdata[1]);

        // 权限验证
        $clientId       = \Gini\Config::get('gapper.rpc')['client_id'];
        $clientSecret   = \Gini\Config::get('gapper.rpc')['client_secret'];
        if ($clientId != $client->id || $clientSecret != $client->secret) {
            return false;
        }

        $tmpItems = $order_data->items;
        $items = [];
        foreach ($tmpItems as $item) {
            $items[] = [
                'qrcode'       => '',
                'product_id'   => $item->id,
                'manufacturer' => $item->manufacturer,
                'catalog_no'   => $item->catalog_no,
                'package'      => $item->package
            ];
        }
        // 推送至 中爆 的数据
        $data = [
            'voucher'     =>  $order_data->voucher,
            'date'        =>  $order_data->request_date,
            'price'       =>  $order_data->price,
            'vendor_code' =>  $order_data->vendor_code,
            'items'       =>  $items
        ];

        $api = \Gini\Config::get('app.order_review_process')['3rd']['api'];
        $http = new \Gini\HTTP();
        $http->header('Content-Type', 'application/json')->post($api, $data);

    }

    /**
     * [postReview 为中爆提供审批订单的接口]
     *
     * @return [bool]
     */
    public function postReview()
    {
        $form     = $this->form('post');
        $action   = $form['action'];
        $voucher  = $form['voucher'];
        $license  = $form['license'];
        $userName = $form['username'];
        $note     = $form['note'];
        $client_id      = $form['client_id'];
        $client_secret  = $form['client_secret'];

        // 首先做验证
        $verify = $this->_verify($client_id, $client_secret);
        if (!$verify) {
            $response = [
                'code'  => 401,
                'msg'   => T("Verify Failed!")
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 参数验证
        if (!in_array($action, ['approve', 'reject'])) {
            $response = [
                'code'  => 400,
                'msg'   => T("Bad Request: action must be 'approve' or 'reject' !")
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        if (!$userName) {
            $response = [
                'code'  => 400,
                'msg'   => T("Bad Request: user is not available !")
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        if (!count($license)) {
            $response = [
                'code'  => 400,
                'msg'   => T("Bad Request: license is not available !")
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        if (!$voucher) {
            $response = [
                'code'  => 400,
                'msg'   => T("Bad Request: voucher is not available !")
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        list($process, $engine) = $this->_getProcessEngine();
        try {

            // 拿到这个 instance
            $params['process']              = $process->id;
            $params['history']              = true;
            $params['variables']['voucher'] = '='.$voucher;

            $rdata       = $engine->searchProcessInstances($params);
            $instances   = $engine->getProcessInstances($rdata->token, 0, 1);
            $instance = current($instances);
            if (!$instance->id) throw new \Gini\BPM\Exception("Bad Request");

            // 拿到 instance 对应的 task
            $search_params['process']           = $process->id;
            $search_params['processInstance']   = $instance->id;
            $rdata      = $engine->searchTasks($search_params);
            $tasks      = $engine->getTasks($rdata->token, 0, 1);
            $task       = current($tasks);

            if (!$task->id) throw new \Gini\BPM\Exception("Bad Request");

            //需要对 task 的当前审批组做判断
            $candidateGroup = $engine->group($task->assignee);
            if (!$this->_isAvailableGroup($candidateGroup->id)) {
                throw new \Gini\BPM\Exception("Bad Request");
            }
        } catch (\Gini\BPM\Exception $e) {
            $response = [
                'code' => 500,
                'msg'  => $e->getMessage()
            ];
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            // 操作远程 task 需要的参数
            $data['task']           = $task;
            $data['instance']       = $instance;
            $data['engine']         = $engine;
            $data['step']           = $this->_getCurrentStep($candidateGroup->id);
            $data['candidateGroup'] = $candidateGroup->name;
            $data['message']        = $note;
            $data['userName']       = $userName;

            // 操作本地订单记录 需要的参数
            $updateData['userName']           = $userName;
            $updateData['message']            = $note;
            $updateData['candidateGroup']     = $candidateGroup->name;
            $updateData['voucher']            = $orderData['voucher'];
            $updateData['customized']         = $orderData['customized'];
            $updateData['type']               = \Gini\ORM\Order::OPERATE_TYPE_APPROVE;

            if ($action == 'approve') {
                // 结束远程的 task 同时记录操作记录
                $data['opt'] = true;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception('approve failed');
                $updateData['opt']                = T('审核通过');
            } else {
                // 结束远程的 task 同时记录操作记录
                $data['opt'] = false;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception('reject failed');
                $updateData['opt']                = T('审核拒绝');
            }

            // 更新本地订单的操作信息
            $this->_doUpdate($updateData);

            $response = [
                'code' => 200,
                'msg'  => 'success'
            ];
        } catch (\Gini\BPM\Exception $e) {
            $response = [
                'code' => 500,
                'msg'  => $e->getMessage()
            ];
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
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
                'user'      => $criteria['userName'],
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

    private function _doUpdate($data)
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
                        ':name'     => $data['userName'],
                        ':opt'      => $data['opt']
                    ]),
                    't' => $now,
                    'd' => $data['message'],
                ]
            ]);

            // 在mall-old 记录操作记录
            if (!$data['customized']) {
                $params = [
                    ':voucher'      => $data['voucher'],
                    ':date'         => date('Y-m-d H:i:s'),
                    ':type'         => $data['type'],
                    ':name'         => $data['userName'],
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

    private function _isAvailableGroup($groupId = '')
    {
        $group = \Gini\Config::get('app.order_review_process')['3rd']['approver'];
        $groupArr = explode('-', $groupId);
        if (in_array($group, $groupArr)) {
            return true;
        }

        return false;
    }

    // 请求验证
    private function _verify($clientId = '', $clientSecret = '')
    {
        if (!$clientId || !$clientSecret) {
            return false;
        }

        $client = \Gini\Config::get('app.order_review_process')['3rd']['client'];
        if ($clientId == $client['id'] && $clientSecret == $client['secret']) {
            return true;
        }

        return false;
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
