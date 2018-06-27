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
        $process_engine = $this->_getProcessEngine();
        if ($process_engine === false) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        list($process, $engine) = $process_engine;

        $params['member']  = $me->id;
        $params['type']    = $process->id;
        // 搜索组信息
        $o = $engine->searchGroups($params);
        // 获取组信息
        $groups = $engine->getGroups($o->token, 0, $o->total);
        $data['total'] = 0;
        if (!count($groups)) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 构建搜索条件
        $search_params = [
            'includeAssignedTasks' => true,
            'sortBy'               => ['created' => 'desc']
        ];

        foreach ($groups as $group) {
            $search_params['candidateGroup'][] = $group->id;
        }
        // 搜索数据
        $rdata = $engine->searchTasks($search_params);
        // 搜索结果为空直接返回
        if (!$rdata || !$rdata->total) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $tasks = $engine->getTasks($rdata->token, $start*$per_page, $per_page);
        $data['total'] = $rdata->total;

        foreach ($tasks as $task) {
            // 获取订单信息
            $order = $this->_getTaskObject($task);

            $info = [
                "id"           => $task->id,
                "instance_id"  => $task->processInstanceId,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $order->price),
                "status"       => $this->_getTaskStatus($engine, $task)
            ];
            // 获取货物信息
            $items = (array)$order->items;
            foreach ($items as $vItem) {
                $vItem = (array)$vItem;
                $productName = $vItem['package'] ? $vItem['name'].' * '.$vItem['quantity'].' ( '.$vItem['package'].' ) ' : $vItem['name'].' * '.$vItem['quantity'];
                $item = [
                    "is_customized"     => $vItem['customized'] ? 1 : 0,
                    "customized_reason" => ($vItem['customized'] && $vItem['reason']) ? $vItem['reason'] : T(''),
                    "product_name"      => $productName
                ];
                // 获取货物标签
                if ($vItem['cas_no']) {
                   $item['type'] = $this->_getCasNoTypes($vItem['cas_no']);
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

        // 验证订单状态
        $status = $form['status'] ? trim($form['status']) : '';
        $validate_status = [
            'active',       // 待审核
            'approved',     // 已通过
            'rejected'      // 已拒绝
        ];

        if ($status !== '' && !in_array($status, $validate_status)) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 获取审批流程
        $process_engine = $this->_getProcessEngine();
        if ($process_engine === false) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        list($process, $engine) = $process_engine;

        $data = [
            "total" => 0
        ];
        // 如果没有group_id，默认获取到第一个组的信息
        $group_id = $form['group_id'];
        if (!$group_id) {
            $params['member'] = $me->id;
            $params['type']   = $process->id;
            // 搜索组信息
            $o = $engine->searchGroups($params);
            // 获取组信息
            $groups = $engine->getGroups($o->token, 0, 1);

            if (!count($groups)) {
                 $response = $this->response(200, "获取成功", $data);
                 return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            $group_id = current($groups)->id;
        }

        $candidateGroup = $engine->group($group_id);

        if ($candidateGroup->type != $process->id) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        // 如果用户不在组里
        if (!$candidateGroup->hasMember($me->id)) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 构造搜索条件
        $searchInstanceParams['orderBy'] = ['startTime' => 'desc'];
        $searchInstanceParams['key']     = $process->id;

        if ($groupCode = $this->_getCurrentGroupCode($group_id)) {
            $searchInstanceParams['candidate_group'] = $groupCode;
        }

        if ($status !== '') {
            $searchInstanceParams['status'] = $status;
        }

        $driver = \Gini\Process\Driver\Engine::of('bpm2');
        $total  = $driver->getInstancesTotal($searchInstanceParams);

        if (!$total) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $instances = $driver->getInstances($searchInstanceParams, $start * $per_page, $per_page);
        $data['total'] = $total ?: 0;

        foreach ($instances as $oinstance) {
            $instance = $engine->ProcessInstance($oinstance->id);
            $order = $this->_getOrderObject($instance, true);

            $info = [
                "id"           => $instance->id,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->group->title ?: $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $order->price),
                "status"       => $this->_getInstanceStatus($instance)
            ];
            // 获取商品信息
            $items = (array)$order->items;
            foreach ($items as $vItem) {
                $vItem = (array)$vItem;
                $productName = $vItem['package'] ? $vItem['name'].' * '.$vItem['quantity'].' ( '.$vItem['package'].' ) ' : $vItem['name'].' * '.$vItem['quantity'];
                $item = [
                    "is_customized"     => $vItem['customized'] ? 1 : 0,
                    "customized_reason" => $vItem['customized'] && $vItem['reason'] ? $vItem['reason'] : '',
                    "product_name"      => $productName
                ];
                // 获取商品标签
                if ($vItem['cas_no']) {
                   $item['type'] = $this->_getCasNoTypes($vItem['cas_no']);
                }

                $info['items'][] = $item;
            }

            $data['list'][] = $info;
        }

        $response = $this->response(200, "获取成功", $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
    }

    /**
        * @brief 获待订单跟踪记录
        *
        * @return
     */
    public function getTraceMessage()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;
        $id = $form['id'];
        if (!$form || !$id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try{
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            // 获取订单信息
            $instance = $engine->processInstance($id);
            if (!$instance->id) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            };
            // 获取审批记录
            $params['variableName'] = 'comment';
            // 获取审批信息
            $rdata = $instance->getVariables($params);
            if (is_array($rdata) && count($rdata)) {
                if (current($rdata)['value']) {
                    $comments = json_decode(current($rdata)['value']);
                    $data['list'] = [];
                    foreach ($comments as $comment) {
                        $data['list'][] = [
                            "reason"    => $comment->message,
                            "traces"    => [
                                "group"  => $comment->group ?: T(''),
                                "user"   => $comment->user ?: T(''),
                                "option" => $comment->option ?: T(''),
                                "time"   => $comment->date ?: T('')
                            ]
                        ];
                    }

                    $response = $this->response(200, "获取成功", $data);
                    return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);

                } else {
                    throw new \Gini\BPM\Exception();
                }
            } else {
                throw new \Gini\BPM\Exception();
            }

        } catch (\Gini\BPM\Exception $e) {
            $data = [
                "list" => [
                    ["reason" => T("暂无跟踪记录"), "traces" => []]
                ]
            ];
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
    * @brief 同意订单
    *
    * @return
     */
    public function postAgree()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;
        $ids = $form['id'];
        if (!$form || !$ids) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $reason = $form['reason'];

        $process_engine = $this->_getProcessEngine();
        if ($process_engine === false) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        list($process, $engine) = $process_engine;

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
                $data['message']        = $reason;

                // 操作本地订单记录 需要的参数
                $updateData['message']            = $reason;
                $updateData['candidateGroup']     = $candidateGroup->name;
                $updateData['orderData']          = $orderData;
                $updateData['voucher']            = $orderData['voucher'];
                $updateData['customized']         = $orderData['customized'];
                $updateData['type']               = \Gini\ORM\Order::OPERATE_TYPE_APPROVE;

                $updateData['opt'] = T('审核通过');

                // 更新本地订单的操作信息
                $this->_doUpdate($updateData, $me);
                // 结束远程的 task 同时记录操作记录
                $data['opt'] = true;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception();
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        if ($bool) {
            $response = $this->response(200, T('操作成功'));
        } else {
            $response = $this->response(400, T('操作失败，请重试'));
        }
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
    }

    /**
    * @brief 拒绝订单
    *
    * @return
     */
    public function postRefuse()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;
        $ids = $form['id'];
        if (!$form || !$ids) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $reason = $form['reason'];

        $process_engine = $this->_getProcessEngine();
        if ($process_engine === false) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        list($process, $engine) = $process_engine;

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
                $data['message']        = $reason;

                // 操作本地订单记录 需要的参数
                $updateData['message']            = $reason;
                $updateData['candidateGroup']     = $candidateGroup->name;
                $updateData['orderData']          = $orderData;
                $updateData['voucher']            = $orderData['voucher'];
                $updateData['customized']         = $orderData['customized'];
                $updateData['type']               = \Gini\ORM\Order::OPERATE_TYPE_APPROVE;

                // 结束远程的 task 同时记录操作记录
                $data['opt'] = false;
                $bool = $this->_completeTask($data);
                if (!$bool) throw new \Gini\BPM\Exception();

                $updateData['opt'] = T('审核拒绝');

                // 更新本地订单的操作信息
                $this->_doUpdate($updateData, $me);
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        if ($bool) {
            $response = $this->response(200, T('操作成功'));
        } else {
            $response = $this->response(400, T('操作失败，请重试'));
        }
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
    }

    /**
    * @brief 获取订单详细信息
    *
    * @return
     */
    public function getOrder()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 验证参数
        $form = $this->form;
        $id = $form['id'];
        $type = (int)$form['type'];
        // type 定义：1 => pending-list , 2 => history-list
        $types = [1, 2];
        if (!$form || !$id || !in_array($type, $types)) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $processName = $conf['name'];
            $engine = \Gini\BPM\Engine::of('order_review');
            if ($type === 1) {
                // 获取订单审批
                $task = $engine->task($id);
                if (!$task->id) throw new \Gini\BPM\Exception();
                $id = $task->processInstanceId;
            }
            // 获取订单信息
            $instance = $engine->processInstance($id);
            if (!$instance || !$instance->id) throw new \Gini\BPM\Exception();

            $order = $this->_getOrderObject($instance);
            if (!$order->id) throw new \Gini\BPM\Exception();
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        // 订单基本信息
        $data = [
            "groupId"   => $order->group_id,
            "voucher"   => $order->voucher,
            "status"    => (int)$order->status,
            "customized"=> $order->customized ?: false
        ];
        // 供应商信息
        $vendor = [
            "type"   => 1,
            "title"  => T('供应商')
        ];
        $this->_addOrderInfoList($vendor, T("供应商"), $order->vendor_name ?: $order->vendor->name);
        $data['infos'][] = $vendor;
        // 买方信息
        $customer = [
            "type"   => 1,
            "title"  => T('买方信息')
        ];
        $this->_addOrderInfoList($customer, T("买方"), $order->group->title?:$order->customer->name);
        $orderGroup = a('group', (int)$order->group_id);
        $this->_addOrderInfoList($customer, T("买方负责人"), $orderGroup->creator->name);
        if ($order->idnumber) {
            $this->_addOrderInfoList($customer, T("身份证号"), $order->idnumber);
        } else {
            $this->_addOrderInfoList($customer, T("下单人"), $order->requester->name?:$order->requester_name);
        }

        $groupTagInfo = $this->_getGroupTagInfo($orderGroup->id);
        $this->_addOrderInfoList($customer, T("学院"), $groupTagInfo['organization']['school_name']);
        $data['infos'][] = $customer;
        // 送货信息
        $delivery = [
            "type"   => 1,
            "title"  => T('送货信息')
        ];
        $this->_addOrderInfoList($delivery, T("地址"), $order->address);
        $this->_addOrderInfoList($delivery, T("邮政编码"), $order->postcode);
        $this->_addOrderInfoList($delivery, T("电话"), $order->phone);
        $this->_addOrderInfoList($delivery, T("电子邮箱"), $order->email);
        $data['infos'][] = $delivery;
        // 易制爆合法使用说明
        $attach_id = ($type === 2) ? 'history-'.$instance->id : 'pending-'.$task->id;
        if (\Gini\Config::get('app.is_show_order_instruction') === true && isset($order->instruction) && isset($order->instruction->path)) {
            $attach_download = [
                "type"   => 1,
                "title"  => T('化学品合法使用说明')
            ];
            $this->_addOrderInfoList($attach_download, T("使用说明附件"), $order->voucher . '.' . pathinfo($order->instruction->name, PATHINFO_EXTENSION), \Gini\Module\AdminBase::getRedirectUrl('review/attach-download/'.$attach_id.'/instruction'));
            $data['infos'][] = $attach_download;
        }
        // 自购附件信息
        $license_image_arr = [];
        $extra_image_arr = [];
        // 商品清单
        $items = [
            "type"        => 2,
            "title"       => T('商品清单'),
            "total_price" => ($order->price==-1) ? T('待询价') : money_format('%.2n', $order->price)
        ];
        // 获取货物信息
        $item_arr = (array)$order->items;
        foreach ($item_arr as $i => $vItem) {
            $vItem = (array)$vItem;
            $item = [
                "product_name"      => $vItem['name'],
                "is_customized"     => $vItem['customized'] ? 1 : 0,
                "customized_reason" => $vItem['customized'] && $vItem['reason'] ? $vItem['reason'] : T(''),
                "manufacturer"      => $vItem['manufacturer'],
                "catalog_no"        => $vItem['catalog_no'],
                "package"           => $vItem['package'],
                "unit_price"        => ($vItem['unit_price'] == -1) ? T('待询价') : money_format('%.2n', $vItem['unit_price']),
                "quantity"          => $vItem['quantity'],
                "price"             => ($vItem['unit_price'] == -1) ? T('待询价') : money_format('%.2n', $vItem['unit_price']*$vItem['quantity']),
                "cas_no"            => $vItem['cas_no'] ?: ''
            ];
            // 获取货物标签
            if ($vItem['cas_no']) {
               $item['types'] = $this->_getCasNoTypes($vItem['cas_no']);
            }

            $items['list'][] = $item;
            // 自购附件信息
            if ($vItem['customized']) {
                // 营业执照
                foreach ((array)$vItem['license_images'] as $index => $license_image) {
                    $license_image_arr[] = [
                        "content" => $license_image->name,
                        "url"     => \Gini\Module\AdminBase::getRedirectUrl('review/attach-download/'.$attach_id.'/license/'.$i.'/'.$index)
                    ];
                }
                // 其他执照
                foreach ((array)$vItem['extra_images'] as $index => $extra_image) {
                    $extra_image_arr[] = [
                        "content" => $extra_image->name,
                        "url"     => \Gini\Module\AdminBase::getRedirectUrl('review/attach-download/'.$attach_id.'/extra/'.$i.'/'.$index)
                    ];
                }
            }
        }
        // 自购附件信息
        if (count($license_image_arr) || count($extra_image_arr)) {
            $customized_attach = [
                "type"  => 1,
                "title" => T('资质信息')
            ];
            // 营业执照
            if (count($license_image_arr)) {
                $customized_attach['list'][] = [
                    "title"         => T('营业执照'),
                    "content_list"  => $license_image_arr
                ];
            }
            // 其他其他
            if (count($extra_image_arr)) {
                $customized_attach['list'][] = [
                    "title"         => T('其他执照'),
                    "content_list"  => $extra_image_arr
                ];
            }
            $data['infos'][] = $customized_attach;
        }
        // 货物列表
        $data['infos'][] = $items;
        // 用途
        if (\Gini\Config::get('app.is_show_order_purpose') === true && $order->purpose) {
            $purpose = [
                "type"   => 1,
                "title"  => T('用途')
            ];
            $this->_addOrderInfoList($purpose, T("用途"), $order->purpose);
            $data['infos'][] = $purpose;
        }


        $response = $this->response(200, T('获取成功'), $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);

    }

     /**
      * [getHazTotal 获取存量]
      * @return
      */
     public function getHazTotal()
     {
         $me = _G('ME');
         $group = _G('GROUP');
         if (!$me->id || !$group->id) {
             $response = $this->response(401, T('无权访问'));
             return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
         }

         $form    = $this->form;
         $groupId = $form['groupId'];
         $casNo   = $form['casNo'];

         if (!$groupId || !$casNo) {
             $response = $this->response(400, T('请求错误'));
             return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
         }

         try {
             $rpc = \Gini\Module\AppBase::getAppRPC('inventory');
             $total = $rpc->mall->inventory->GetHazardousTotal($casNo, $groupId);
         } catch (\Exception $e) {
             $response = $this->response(500, T('获取失败'));
             return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
         }

         $data['total'] = $total ?: '';
         $response = $this->response(200, T('获取成功'), $data);
         return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
     }

    private function _getGroupTagInfo($groupID = '')
    {
        $info = [];

        if (!$groupID) {
            return $info;
        }

        $node = \Gini\Config::get('app.node');
        $key = "labmai-{$node}/{$groupID}";;
        $info = (array)\Gini\TagDB\Client::of('rpc')->get($key);
        return $info;
    }

    private function _addOrderInfoList(&$info, $title, $content, $url=null)
    {
        if (!$info || !$title || !$content) return;
        // 新建 item
        $content_item['content'] = $content;
        if ($url) {
            $content_item['url'] = $url;
        }
        // 循环查看是否有 title = $title 的数据，如果有则插入，没有则新建
        if ($info['list']) {
            foreach ($info['list'] as $i => $v) {
                if ($v['title'] === $title) {
                    $index = $i;
                    break;
                }
            }
        }
        if ($index) {
            $info['list'][$index]['content_list'][]= $content_item;
        } else {
            $list['title'] = $title;
            $list['content_list'][] = $content_item;
            $info['list'][] = $list;
        }
    }

    private function _getProcessEngine()
    {
        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);
        } catch (\Gini\BPM\Exception $e) {
            return false;
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
            if ($order->id) {
                $data = $order;
            }
        }

        if (\Gini\Config::get('app.is_show_order_reagent_purpose') === true) {
            $data->purpose = $data->purpose;
        }

        return $data;
    }

    private function _getTaskObject($task)
    {
        $rdata = $task->getVariables('data');
        $data = json_decode($rdata['value']);

        return $data;
    }

    private function _getInstanceStatus($instance)
    {
        try {
            $params['variableName'] = 'status';
            $rdata = (array) $instance->getVariables($params);
            $value = current($rdata)['value'];
            switch ($value) {
                case 'approved':
                    $code = T('已通过');
                    break;
                case 'rejected':
                    $code = T('已拒绝');
                    break;
                case 'active':
                    $code = T('待审批');
                    break;
                default:
                    $value = 'error';
                    $code  = T('系统处理中');
            }
        } catch (\Gini\BPM\Exception $e) {
            $value = 'error';
            $code  = T('系统处理中');
        }

        return [
            'code' =>  $value,
            'text' =>  $code
        ];
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

    private function _getCurrentGroupCode($group)
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

}
