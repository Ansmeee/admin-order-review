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
        * @brief 获取设置权限信息
        *
        * @return
     */
    public function getSettingsOption()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        if ($me->isAllowedTo('管理权限')) {
            $data['list'][] = [
                "model"  => T("authority"),
                "title"  => T("设置分组"),
                "path"   => T("/order/review/authority")
            ];
        }

        $appInfo = \Gini\Gapper\Client::getInfo();
        $data['list'][] = [
            "model"  => T("wxbind"),
            "title"  => T("微信绑定"),
            "url"    => T($appInfo['url']."/qr")
        ];
        $response = $this->response(200, T('获取成功'), $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
    }

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
            $params['member'] = $me->id;
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
                    "code"   =>  $group->id,
                    "title"  =>  $group->name
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
            $instance = $engine->processInstance($task->processInstanceId);
            $order = $this->_getOrderObject($instance);

            $info = [
                "id"           => $task->id,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $order->price),
                "status"       => $this->_getInstanceStatus($engine, $instance)
            ];
            // 获取货物信息
            $items = (array)$order->items;
            foreach ($items as $vItem) {
                $vItem = (array)$vItem;
                $item = [
                    "is_customized"     => $vItem['customized'] ? 1 : 0,
                    "customized_reason" => ($vItem['customized'] && $vItem['reason']) ? $vItem['reason'] : T(),
                    "product_name"      => $vItem['name'].' * '.$vItem['quantity']
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

        // 获取审批流程
        list($process, $engine) = $this->_getProcessEngine();

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
        $search_params = [
            'sortBy'         => ['startTime' => 'desc'],
            'candidateGroup' => $candidateGroup->id,
            'history'        => true
        ];
        // 获取订单信息
        $result = $engine->searchTasks($search_params);
        $tasks  = $engine->getTasks($result->token, $start*$per_page, $per_page);
        $data['total'] = $result->total;
        if (!$result->total || !count($tasks)) {
            $response = $this->response(200, "获取成功", $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        foreach ($tasks as $task) {
            $instanceIds[] = $task->processInstanceId;
        }

        $search_params['processInstance'] = $instanceIds;
        $rdata = $engine->searchProcessInstances($search_params);
        $instances = $engine->getProcessInstances($rdata->token, 0, $rdata->total);

        foreach ($instances as $instance) {
            $order = $this->_getOrderObject($instance,true);

            $info = [
                "id"           => $instance->id,
                "ctime"        => $order->ctime ?: $order->request_date,
                "voucher"      => $order->voucher,
                "customer"     => $order->customer->name,
                "vendor_name"  => $order->vendor_name ?: $order->vendor->name,
                "price"        => money_format('%.2n', $order->price),
                "status"       => $this->_getInstanceStatus($engine, $instance)
            ];
            // 获取商品信息
            $items = (array)$order->items;
            foreach ($items as $vItem) {
                $vItem = (array)$vItem;
                $item = [
                    "is_customized"     => $vItem['customized'] ? 1 : 0,
                    "customized_reason" => $vItem['customized'] && $vItem['reason'] ? $vItem['reason'] : '',
                    "product_name"      => $vItem['name'].' * '.$vItem['quantity']
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
            // 获取订单审批
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
                                "group"  => $comment->group,
                                "user"   => $comment->user,
                                "time"   => $comment->date
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

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $db = \Gini\Database::db('mall-old');
        $db->beginTransaction();

        foreach ($ids as $id) {
            // 获取审批信息
            $task = $engine->task($id);
            // 获取组信息
            $candidate_group = $engine->group($task->assignee);
            // 获取订单信息
            $order = (array) json_decode($task->getVariables('data')['value']);

            $params = [
                ':voucher'     => $order['voucher'],
                ':date'        => date('Y-m-d H:i:s'),
                ':operator'    => $me->id,
                ':type'        => \Gini\ORM\Order::OPERATE_TYPE_APPROVE,
                ':name'        => $me->name,
                ':description' => $candidate_group->name.T('审批人'),
            ];
            // 向数据库插入信息
            $sql = "insert into order_operate_info (voucher,operate_date,operator_id,type,name,description) values (:voucher, :date, :operator, :type, :name, :description)";
            $query = $db->query($sql, null, $params);
            // 如果数据库插入错误，或者更新订单审批信息错误
            if (!$query || !$this->_updateOrder($id, $engine, $me, $reason, true)) {
                $db->rollback();
                $response = $this->response(400, T('操作失败'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
        }
        $db->commit();
        $response = $this->response(200, T('操作成功'));
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

        list($process, $engine) = $this->_getProcessEngine();
        if (!$process->id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        foreach ($ids as $id) {
            // 如果更新订单审批信息出现错误
            if (!$this->_updateOrder($id, $engine, $me, $reason, false)) {
                $response = $this->response(400, T('操作失败'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
        }

        $response = $this->response(200, T('操作成功'));
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
            "voucher"   => $order->voucher,
            "status"    => (int)$order->status
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
        $this->_addOrderInfoList($customer, T("买方负责人"), $group->creator->name);
        if ($order->idnumber) {
            $this->_addOrderInfoList($customer, T("身份证号"), $order->idnumber);
        } else {
            $this->_addOrderInfoList($customer, T("下单人"), $order->requester->name?:$order->requester_name);
        }
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
        $appInfo = \Gini\Gapper\Client::getInfo();
        $attach_id = ($type === 2) ? 'history-'.$instance->id : 'pending-'.$task->id;
        if (\Gini\Config::get('app.is_show_order_instruction') && is_array($order->instruction) && isset($order->instruction['path'])) {
            $attach_download = [
                "type"   => 1,
                "title"  => T('化学品合法使用说明')
            ];
            $this->_addOrderInfoList($attach_download, T("使用说明附件"), T($appInfo['url'].'/review/attach-download/'.$attch_id.'/0/0/instruction'));
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
                "price"             => ($vItem['unit_price'] == -1) ? T('待询价') : money_format('%.2n', $vItem['unit_price']*$vItem['quantity'])
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
                        "url"     => T($appInfo['url'].'/review/attach-download/'.$attach_id.'/'.$i.'/'.$index.'/license')
                    ];
                }
                // 其他执照
                foreach ((array)$vItem['extra_images'] as $index => $extra_image) {
                    $extra_image_arr[] = [
                        "content" => $license_image->name,
                        "url"     => T($appInfo['url'].'/review/attach-download/'.$attach_id.'/'.$i.'/'.$index.'/extra')
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

    private function _addOrderInfoList(&$info, $title, $content, $url=null)
    {
        if (!$info || !$title || !$content) return;
        // 新建 item
        $content_item['content'] = $content;
        if ($url) {
            $content_item['url'] = $url;
        }
        // 循环查看是否有 title = $title 的数据，如果有则插入，没有则新建
        foreach ($info['list'] as $i => $v) {
            if ($v['title'] === $title) {
                $index = $i;
                break;
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

    private function _addComment($engine, $task, array $comment) {
        $his_comment = [];
        $instance = $engine->processInstance($task->processInstanceId);
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

    private function _updateOrder($id, $engine, $user, $message, $type)
    {
        try {
            // 获取审批信息
            $task = $engine->task($id);
            // 获取组信息
            $candidate_group = $engine->group($task->assignee);
            // 获取订单信息
            $order = (array) json_decode($task->getVariables('data')['value']);

            $comment = [
                'message' => $message,
                'group'   => $candidate_group->name,
                'user'    => $user->name,
                'date'    => date('Y-m-d H:i:s')
            ];

            // 添加跟踪信息
            if ($this->_addComment($engine, $task, $comment)) {
                // 获取当前审批进度
                $step_arr = explode('-', $task->assignee);
                $conf = \Gini\Config::get('app.order_review_process');
                $steps = $conf['steps'];
                foreach ($step_arr as $step) {
                    if (in_array($step, $steps)) {
                        $now_step = $step;
                        break;
                    }
                }
                $_params[$now_step.'_'.$conf['option']] = $type;
                // 更新当前审批进度
                if ($task->complete($_params)) {
                    $description = [
                        'a' => T('**:group** **:name** **:opt**', [
                            ':group'=> $candidate_group->name,
                            ':name' => $user->name,
                            ':opt' => $type ? T('审核通过') : T('拒绝')
                        ]),
                        't' => date('Y-m-d H:i:s'),
                        'u' => $user->id,
                        'd' => $message,
                    ];

                    $customizedMethod = ['\\Gini\\Process\\Engine\\SJTU\\Task', 'doUpdate'];
                    if (method_exists('\\Gini\\Process\\Engine\\SJTU\\Task', 'doUpdate')) {
                        if (!call_user_func($customizedMethod, $order, $description)) return false;
                    } else {
                        return false;
                    }
                } else {
                    return false;
                }
            } else {
                return false;
            }
            return true;
        } catch (\Gini\BPM\Exception $e) {
            return false;
        }
    }

}