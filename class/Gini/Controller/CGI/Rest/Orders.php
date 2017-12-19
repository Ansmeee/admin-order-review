<?php
/**
* @file Orders.php
* @brief  为FE提供的前端接口
* @author xuguang.chen
* @version 0.1.0
* @date 2017-12-08
 */

namespace Gini\Controller\CGI\Rest;

class Orders extends Base\Index
{

    /**
        * @brief 获取组信息
        *
        * @return
     */
    public function getGroups()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            list($process, $engine) = $this->_getProcessEngine();
            $user = $me->isAllowedTo('管理权限') ? null : $me;
            $params['member'] = $user->id;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);

            $data = [
                "total" => count($groups),
                "list"  => []
            ];

            if (!count($groups)) {
                $response = $this->response(200, T('获取成功'), $data);
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            foreach ($groups as $group) {
                $data['list'][] = [
                    "id"   =>  $group->id,
                    "name" =>  $group->name
                ];
            }

            $response = $this->response(200, T('获取成功'), $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('获取失败'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 获待审核订单列表
        *
        * @return
     */
    public function getPendingList()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;

        $start = (int)$form['current_page'] - 1;
        if ($start < 0) $start = 0;

        $per_page = (int)$form['page_size'];
        if ($per_page < 1) $per_page = 10;

        // 获取审批流程
        list($process, $engine) = $this->_getProcessEngine();
        $params['member'] = $me->id;
        $params['type'] = $process->id;
        // 搜索组信息
        $o = $engine->searchGroups($params);
        // 获取组信息
        $groups = $engine->getGroups($o->token, 0, $o->total);

        $data = [
            "total" => 0,
            "list"  => []
        ];

        if (!count($groups)) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        // 构建搜索条件
        foreach ($groups as $group) {
            $search_params['candidateGroup'][] = $group->id;
        }

        $search_params['includeAssignedTasks'] = true;
        $sortBy = [
            'created' => 'desc'
        ];
        $search_params['sortBy'] = $sortBy;
        $rdata = $engine->searchTasks($search_params);
        $tasks = $engine->getTasks($rdata->token, $start*$per_page, $per_page);
        $data['total'] = $rdata->total;
        // 搜索结果为空直接返回
        if (!$rdata->total) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        foreach ($tasks as $task) {
            $instance = $engine->processInstance($task->processInstanceId);
            $order = $this->_getOrderObject($instance);

            $info = [
                "id"           => (int)$order->id,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $vOrder->price),
                "status"       => $this->_getInstanceStatus($engine, $instance),
                "items"        => [],
            ];

            $items = (array)$order->items;
            foreach ($items as $vPData) {
                $vPData = (array)$vPData;
                $item = [
                    "is_customized"     => $vPData['customized'] ? 1 : 0,
                    "customized_reason" => $vPData['customized'] && $vPData['reason'] ? $vPData['reason'] : '',
                    "product_name"      => $vPData['name'].' * '.$vPData['quantity'],
                    "type"              => []
                ];

                if ($vPData['cas_no']) {
                   $item['type'] = $this->_getCasNoTypes($vPData['cas_no']);
                }

                $info['items'][] = $item;
            }

            $data['list'][] = $info;
        }

        $response = $this->response(200, "获取成功", $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
    }

    /**
        * @brief 获待审核订单历史记录
        *
        * @return
     */
    public function getHistoryList()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;

        $start = (int)$form['current_page'] - 1;
        if ($start < 0) $start = 0;

        $per_page = (int)$form['page_size'];
        if ($per_page < 1) $per_page = 10;

        // 获取审批流程
        list($process, $engine) = $this->_getProcessEngine();
        $tasks = [];

        $data = [
            "total" => 0,
            "list"  => []
        ];

        $group_id = $form['group_id'];
        if (!$group_id) {
           $params['member'] = $me->id;
           $params['type'] = $process->id;
           // 搜索组信息
           $o = $engine->searchGroups($params);
           // 获取组信息
           $groups = $engine->getGroups($o->token, 0, $o->total);

           if (!count($groups)) {
                $response = $this->response(200, "获取成功", $data);
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
           }

           $_group = current($groups);
           $group_id = $_group->id;
        }

        $candidateGroup = $engine->group($group_id);

        if ($candidateGroup->type != $process->id) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $user = $me->isAllowedTo('管理权限') ? null : $me;
        $isMemberOfGroup = $candidateGroup->hasMember($me->id);

        if ($user->id && !$isMemberOfGroup) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 构造搜索条件
        $params['sortBy'] = [
            'startTime' => 'desc'
        ];
        $params['candidateGroup'][] = $candidateGroup->id;
        $params['history'] = true;
        // 获取订单信息
        $result = $engine->searchTasks($params);
        $tasks = $engine->getTasks($result->token, $start*$per_page, $per_page);

        $data['total'] = $result->total;
        if (!$result->total || !count($tasks)) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        foreach ($tasks as $task) {
            $instanceIds[] = $task->processInstanceId;
        }

        $searchInstanceParams['sortBy'] = $sortBy;
        $searchInstanceParams['history'] = true;
        $searchInstanceParams['processInstance'] = $instanceIds;
        $rdata = $engine->searchProcessInstances($searchInstanceParams);
        $instances = $engine->getProcessInstances($rdata->token, 0, $rdata->total);

        foreach ($instances as $instance) {
            $order = $this->_getOrderObject($instance,true);

            $info = [
                "id"           => (int)$order->id,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $vOrder->price),
                "status"       => $this->_getInstanceStatus($engine, $instance),
                "items"        => [],
            ];

            $items = (array)$order->items;
            foreach ($items as $vPData) {
                $vPData = (array)$vPData;
                $item = [
                    "is_customized"     => $vPData['customized'] ? 1 : 0,
                    "customized_reason" => $vPData['customized'] && $vPData['reason'] ? $vPData['reason'] : '',
                    "product_name"      => $vPData['name'].' * '.$vPData['quantity'],
                    "type"              => []
                ];

                if ($vPData['cas_no']) {
                   $item['type'] = $this->_getCasNoTypes($vPData['cas_no']);
                }

                $info['items'][] = $item;
            }

            $data['list'][] = $info;
        }

        $response = $this->response(200, "获取成功", $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);

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

    private function _getCasNoTypes($cas_no)
    {
        $arr = [];
        if ($vTypes=\Gini\ChemDB\Client::getTypes($cas_no)) {
          foreach ($vTypes[$cas_no] as $value) {
              $key = array_search($value, \Gini\ORM\Product::$rgt_types);
              if (\Gini\ORM\Product::$rgt_titles[$key]) {
                  $arr[] = [
                      "type"  => $value,
                      "title" => \Gini\ORM\Product::$rgt_titles[$key]
                  ];
              }
          }
        }

        return $arr;
    }

}
