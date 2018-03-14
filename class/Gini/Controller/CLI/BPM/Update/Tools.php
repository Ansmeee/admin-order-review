<?php

namespace Gini\Controller\CLI\BPM\Update;

class Tools extends \Gini\Controller\ClI
{
    public function __index($args)
    {
        echo "订单审批数据升级脚本:\n";
        echo "请务必先执行 gini bpm update tools users ！\n";
        echo "用户升级: gini bpm update tools users \n";
        echo "审批组升级: gini bpm update tools groups \n";
        echo "审批组成员升级: gini bpm update tools group-members \n";
        echo "待审批数据升级: gini bpm update tools update-instances \n";
        echo "审批历史数据升级: gini bpm update tools update-finished-instances \n";
    }

    public function actionUsers()
    {
        $group_id = readline("please enter admin group's id: \n");
        $start = 0;
        $per_page = 25;
        $engine = \Gini\BPM\Engine::of('order_review');
        $rpc = \Gini\Gapper\Client::getRPC()->gapper->group;
        $group_id = (int) $group_id;

        $query = [];

        while (true) {
            $r = $rpc->searchMembers($group_id, []);
            $token = $r['token'];
            $members = $rpc->getMembers($group_id, $token, $start, $per_page);
            $start += $per_page;
            if (!count($members)) return;
            foreach ($members as $member) {
                try {
                    $params['id'] = $member['id'];
                    $params['firstName'] = $member['name'];
                    $params['lastName'] = $member['name'];
                    $params['email'] = $member['email'];
                    $params['password'] = md5($member['eamil'].'_'.$member['id']);
                    $user = $engine->user();
                    $bool = $user->create($params);
                    if ($bool) {
                        echo $member['id']."--o\n";
                    } else {
                        echo $member['id']."--x\n";
                    }
                } catch (\Gini\BPM\Exception $e) {
                    continue;
                }
            }
        }
    }

    public function actionGroups()
    {
        //获取历史审批组
        $his_process_name = 'order-review-process';
        $his_engine = \Gini\Process\Engine::of('default');
        $his_process = $his_engine->getProcess($his_process_name);

        $his_groups = Those('sjtu/bpm/process/group')
            ->Whose('process')->is($his_process);
        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');

        foreach ($his_groups as $his_group) {
            try {
                $key = $his_group->name;
                $params['id'] = $conf['name'].'-'.$key;
                $params['name'] = $his_group->title;
                $params['type'] = $conf['name'];
                $group = $engine->group();
                $bool = $group->create($params);
            } catch (\Gini\BPM\Exception $e) {
                echo $params['id'].'--x \n';
            }
            if ($bool) {
                echo $his_group->name."---".$group_user->user->id."--o \n";
            } else {
                echo $his_group->name."--x \n";
            }
        }

        echo "DONE \n";
    }

    public function actionGroupMembers()
    {
        $his_process_name = 'order-review-process';
        $his_engine = \Gini\Process\Engine::of('default');
        $his_process = $his_engine->getProcess($his_process_name);

        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');

        try {
            $params['type'] = $conf['name'];
            $rData = $engine->searchGroups($params);
            $candidateGroups = $engine->getGroups($rData->token, 0, $rData->total);
        } catch (\Gini\BPM\Exception $e) {
            echo "获取分组异常 \n";
        }

        if (!count($candidateGroups)) {
            return ;
        }
        foreach ($candidateGroups as $candidateGroup) {
            $hisGroupName = substr($candidateGroup->id, strlen($conf['name'].'-'));
            $hisGroup = a('sjtu/bpm/process/group', ['name' => $hisGroupName]);
            if (!$hisGroup->id) {
                continue;
            }
            $hisGroupMembers = Those('sjtu/bpm/process/group/user')
                ->Whose('group')->is($hisGroup);
            foreach ($hisGroupMembers as $hisGroupMember) {
                try {
                    $bool = $candidateGroup->addMember($hisGroupMember->user->id);
                } catch (\Gini\BPM\Exception $e) {
                    echo $candidateGroup->id."--".$hisGroupMember->user->name."--x \n";
                    continue;
                }
                if (!$bool) {
                    echo $candidateGroup->id."--".$hisGroupMember->user->name."--x \n";
                    continue;
                }
                echo $candidateGroup->id."--".$hisGroupMember->user->name."--o \n";
            }
        }
    }

    public function actionPrepareUpdate()
    {
        $start = 0;
        $limit = 100;

        $node = \Gini\Config::get('app.node');
        $processId = readline("process id : \n");
        $process = a('sjtu/bpm/process', (int)$processId);
        echo "node: $node \n";
        echo "name: $process->name \n";

        $con = readline("run or exit: (Y / N) ");
        if ($con !== 'Y') {
            return ;
        }

        $pID = $process->id;
        $db = \Gini\DataBase::db();
        while (true) {
            $sql = "select `tag` from `sjtu_bpm_process_instance` where `process_id` = {$pID} limit ${start}, ${limit}";
            $rows = @$db->query($sql)->rows();
            if (!count($rows)) {
                break;
            }
            $start += $limit;

            foreach ($rows as $tag) {
                $newTag = $node.'#'.$tag->tag;
                $selSql = "select `name` from `tagdb_tag` where name = '{$newTag}'";
                $name = $db->query($selSql);
                if ($name) {
                    $delSql = "delete from `tagdb_tag` where `name` = '{$newTag}'";
                    if ($db->query($delSql)) {
                        echo $newTag."--done \n";
                        continue;
                    }
                }
                echo $newTag."--fail \n";
            }
        }

        echo "DONE \n";
    }

    public function actionUpdateInstances()
    {
        $start = 0;
        $perpage = 25;
        $node = \Gini\Config::get('app.node');

        $his_process_name = 'order-review-process';
        $his_engine = \Gini\Process\Engine::of('default');
        $his_process = $his_engine->getProcess($his_process_name);

        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        $process = $engine->process($conf['name']);
        while (true) {
            $instances = Those('sjtu/bpm/process/instance')
                ->Whose('process')->is($his_process)
                ->andWhose('status')->isNot(\Gini\Process\IInstance::STATUS_END)
                ->limit($start, $perpage);
            $start += $perpage;
            if (!count($instances)) return;
            foreach ($instances as $instance) {
                $order_data = $instance->data['data'];
                $items = (array) json_decode($order_data['items']);
                $order_data['items'] = $items;
                $cacheData['data'] = $order_data;
                $types = [];
                foreach ($items as $item) {
                    $item = (array) $item;
                    $casNO = $item['cas_no'];
                    $chem_types = (array) \Gini\ChemDB\Client::getTypes($casNO)[$casNO];
                    $types = array_unique(array_merge($types, $chem_types));
                }
                $cacheData['customized'] = $order_data['customized'] ? true : false;
                $cacheData['chemicalTypes'] = $types;

                $key = "labmai-".$node."/".$order_data['group_id'];
                $info = (array)\Gini\TagDB\Client::of('rpc')->get($key);
                $cacheData['candidate_group'] = $info['organization']['school_code'];

                $steps = array_keys($conf['steps']);
                foreach ($steps as $step) {
                    if ($step == 'school') continue;
                    $cacheData[$step] = $step;
                }
                $cacheData['key'] = $conf['name'];
                try {
                    $create_instance = $process->start($cacheData);
                    if ($create_instance->id) {
                        echo $instance->id."--".$create_instance->id."--o\n";
                    }
                } catch (\Gini\BPM\Exception $e) {
                    echo $instance->id."--x\n";
                }
            }
        }

        echo "DONE \n";
    }

    public function actionUpdateFinishedInstances()
    {
        $start = 0;
        $perpage = 100;
        $node = \Gini\Config::get('app.node');

        $his_process_name = 'order-review-process';
        $his_engine = \Gini\Process\Engine::of('default');
        $his_process = $his_engine->getProcess($his_process_name);

        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        $processName = $conf['name'];
        $process = $engine->process($processName);
        while (true) {
            $instances = Those('sjtu/bpm/process/instance')
                ->Whose('process')->is($his_process)
                ->andWhose('status')->is(\Gini\Process\IInstance::STATUS_END)
                ->limit($start, $perpage);
            $start += $perpage;
            if (!count($instances)) return;
            foreach ($instances as $instance) {
                $comment = [];
                $cacheData = [];
                $candidate_groups = [];
                $order_data = $instance->data['data'];
                $items = (array) json_decode($order_data['items']);
                $order_data['items'] = $items;
                $cacheData['data'] = $order_data;
                $his_tasks = Those('sjtu/bpm/process/task')
                    ->Whose('instance')->is($instance)
                    ->orderBy('ctime', 'desc');
                foreach ($his_tasks as $his_task) {
                    $com = [];
                    if ($his_task->user) {
                        $com = [
                            'message' => $his_task->message,
                            'group' => $his_task->group,
                            'user' => $his_task->user,
                            'date' => $his_task->date
                        ];
                    }

                    $comment[] = $com;
                    if ($his_task->candidate_group->id) {
                        $candidate_groups[] = $conf['name'].'-'.$his_task->candidate_group->name;
                    }
                }

                $cacheData['candidate_groups'] = implode(',', $candidate_groups);
                $cacheData['comment'] = $comment;
                $cacheData['key'] = $processName;
                try {
                    $create_instance = $process->start($cacheData);
                    if ($create_instance->id) {
                        $search_params['processInstance'] =  $create_instance->id;
                        $result = $engine->searchTasks($search_params);
                        $tasks = $engine->getTasks($result->token, 0, $result->total);
                        $task = current($tasks);
                        if (!$task->complete()) {
                            echo ($start-100).'-'.$instance->id.'---'.$create_instance->id."---task---".$task->id."---x\n";
                            continue;
                        }
                        echo ($start-100).'-'.$instance->id.'---'.$create_instance->id."---o\n";
                    }
                } catch (\Gini\BPM\Exception $e) {
                   echo ($start-100).'-'.$instance->id."---x\n";
                }
            }
        }

        echo "DONE \n";
    }

    public function actionUpdateVariables()
    {
        $file = APP_PATH.'/'.DATA_DIR.'/instance.csv';
        $myfile = fopen($file, 'r') or die("Unable to open file!");

        $conf = (array) \Gini\Config::get('app.order_review_process');
        foreach (array_keys($conf['steps']) as $step) {
            $steps[] = $step.'_approved';
        }
        $engine = \Gini\BPM\Engine::of('order_review');
        $db = \Gini\DataBase::db('camunda');
        while ($instanceId = trim(fgets($myfile))) {
            $instance = $engine->processInstance($instanceId);
            if ($instance->state !== 'COMPLETED') continue;

            $sql = "SELECT `NAME_` as name, `LONG_` as 'long' FROM `ACT_HI_VARINST` WHERE `PROC_INST_ID_`= '{$instanceId}'";
            $rows = @$db->query($sql)->rows();
            if (count($rows)) {
                foreach ($rows as $row) {
                     // 判断 status 的状态
                     if (in_array($row->name, $steps)) {
                         $opts[$row->name] = $row->long;
                     }
                }

                $status = 'approved';
                // 如果有一个是拒绝的，那就是 rejected
                if (in_array(0, $opts)) {
                    $status = 'rejected';
                }

                $updateSql = "UPDATE `ACT_HI_VARINST` SET `TEXT_` = '{$status}' WHERE `PROC_INST_ID_`= '{$instanceId}' AND `NAME_` = 'status'";
                $query = $db->query($updateSql);
                if ($query) {
                     echo $instanceId."--done \n";
                     continue;
                }
                echo $instanceId."--file \n";
            }
        }
    }

    public function actionUpdateInstanceVariables()
    {
        $start = 0;
        $limit = 100;

        $file = APP_PATH.'/'.DATA_DIR.'/instance.csv';
        // 搜索条件
        list($process, $engine) = $this->_getProcessEngine();
        $searchInstanceParams['active']  = true;
        $searchInstanceParams['process'] = $process->id;
        // 检索数据 处理数据
        $rdatas      = $engine->searchProcessInstances($searchInstanceParams);
        while (true) {
            $instances  = $engine->getProcessInstances($rdatas->token, $start, $limit);
            if (!count($instances)) {
                break;
            }

            $start += $limit;

            foreach ($instances as $instance) {
                file_put_contents($file, $instance->id."\n", FILE_APPEND);
                $params['variableName'] = 'data';
                $rdata = $instance->getVariables($params);
                $data = json_decode(current($rdata)['value']);
                $bool = $this->_setVariable($engine, $instance, $data);
                if ($bool) {
                    echo $instance->id."--done \n";
                    continue;
                }
                echo $instance->id."--fail \n";
            }
        }
        echo "DONE \n";
    }


    public function actionUpdateFinishedInstanceVariables()
    {
        $start = 0;
        $limit = 100;

        // 搜索条件
        list($process, $engine) = $this->_getProcessEngine();
        $searchInstanceParams['history'] = true;
        $searchInstanceParams['process'] = $process->id;

        // 检索数据 处理数据
        $rdatas     = $engine->searchProcessInstances($searchInstanceParams);
        while (true) {
            $instances  = $engine->getProcessInstances($rdatas->token, $start, $limit);
            if (!count($instances)) {
                break;
            }

            $start += $limit;

            foreach ($instances as $instance) {
                if ($instance->state !== 'COMPLETED') continue;
                $params['variableName'] = 'data';
                $rdata = $instance->getVariables($params);
                $data = json_decode(current($rdata)['value']);

                $result = $this->_doIt($engine, $instance, $data);

                if (is_array($result) && count($result)) {
                    foreach ($result as $name) {
                        echo $instance->id.'--'.$name.'--failed';
                        echo "\n";
                    }
                    continue;
                } else if($result) {
                    echo $instance->id."--done \n";
                    continue;
                }

                echo $instance->id."--fail \n";
            }
        }
        echo "DONE \n";
    }

    public function actionOne()
    {
        $Id = readline("instance Id: \n");
        list($process, $engine) = $this->_getProcessEngine();
        $instance = $engine->processInstance($Id);
        if (!$instance->id) {
            echo "error \n";
            return ;
        }

        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);
        $bool = $this->_doIt($engine, $instance, $data);
        var_dump($bool);
    }

    private function _doIt($engine, $instance, $data = [])
    {
        $ids = [];
        $opts = [];
        $variables = $this->_getVariables($data);
        $status = 'approved';

        $conf = (array) \Gini\Config::get('app.order_review_process');
        foreach (array_keys($conf['steps']) as $step) {
            $steps[] = $step.'_approved';
        }

        $db = \Gini\Database::db('camunda');

        $sql = "SELECT `ID_` as id, `PROC_DEF_KEY_` as def_key, `PROC_DEF_ID_` as def_id,
         `EXECUTION_ID_` as execution_id, `ACT_INST_ID_` as act_inst_id, `NAME_` as name,
         `LONG_` as 'long'
         FROM `ACT_HI_VARINST` WHERE `PROC_INST_ID_`= '{$instance->id}'";

        $rows = @$db->query($sql)->rows();
        if (count($rows)) {
            foreach ($rows as $row) {
                $ids[] = $row->id;

                // 拿到不变的量
                $def_key = $row->def_key;
                $def_id  = $row->def_id;
                $inst_id = $instance->id;
                $execution_id = $row->execution_id;
                $act_inst_id  = $row->act_inst_id;

                // 判断 status 的状态
                if (in_array($row->name, $steps)) {
                    $opts[$row->name] = $row->long;
                }
            }

            // 如果有一个是拒绝的，那就是 rejected
            if (in_array(0, $opts)) {
                $status = 'rejected';
            }

            foreach ($variables as $name => $variable) {
                // 自己造一个 id 必须和原来的 id 不同

                $cid = current($ids);
                $cidArr = explode('-', $cid);
                $newCidArr = $cidArr;
                while (true) {
                    $newPriId = rand(10000000, 99999999);
                    $newCidArr[0] = $newPriId;
                    $nId = implode('-', $newCidArr);
                    if (!in_array($nId, $ids)) {
                        $ids[] = $nId;
                        break ;
                    }
                }

                $type = $variable['type'];
                if ($name == 'status') {
		    $value = $status;
                } else {
                    $value = $variable['value'];
                }
                $sql = "INSERT INTO `ACT_HI_VARINST`
                (`ID_`, `PROC_DEF_KEY_`, `PROC_DEF_ID_`, `PROC_INST_ID_`, `EXECUTION_ID_`,
                `ACT_INST_ID_`, `NAME_`, `VAR_TYPE_`, `REV_`, `TEXT_`)
                VALUES
                ('$nId', '$def_key', '$def_id', '$inst_id', '$execution_id', '$act_inst_id', '$name', '$type', 0, '$value')";
                $query = $db->query($sql);
                if (!$query) {
                    $failed[] = $name;
                }
            }
        }

        if (count($failed)) {
            return $failed;
        }

        return true;
    }

    public function actionTestOne()
    {
        $Id = readline("instance Id: \n");
        list($process, $engine) = $this->_getProcessEngine();
        $instance = $engine->processInstance($Id);
        if (!$instance->id) {
            echo "error \n";
            return ;
        }

        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);
        $bool = $this->_setVariable($engine, $instance, $data);
        echo $bool."\n";
    }

    public function actionForSJTU()
    {
        $start = 0;
        $limit = 100;
        list($process, $engine) = $this->_getProcessEngine();
        $searchInstanceParams['key']             = $process->id;
        $driver = \Gini\Process\Driver\Engine::of('bpm2');
        $results = $driver->searchInstances($searchInstanceParams);

        while (true) {
            $instances = $driver->getInstances($results['token'], $start, $limit);
            if (!count($instances)) {
                break ;
            }

            $start += $limit;

            foreach ($instances as $oinstance) {
                $instance = $engine->ProcessInstance($oinstance->id);
                if ($instance->state !== 'COMPLETED') continue;
                $params['variableName'] = 'data';
                $rdata = $instance->getVariables($params);
                $data = json_decode(current($rdata)['value']);

                $result = $this->_doIt($engine, $instance, $data);

                if (is_array($result) && count($result)) {
                    foreach ($result as $name) {
                        echo ($start-100).'--'.$instance->id.'--'.$name.'--failed';
                        echo "\n";
                    }
                    continue;
                } else if($result) {
                    echo ($start-100).'--'.$instance->id."--done \n";
                    continue;
                }

                echo ($start-100).'--'.$instance->id."--fail \n";
            }
        }

        echo "DONE \n";
    }

    private function _getVariables($data)
    {
        // 订单编号
        $variables['voucher'] = [
            'variableName'  => 'voucher',
            'type'          => 'string',
            'value'         => $data->voucher ?: ''
        ];

        // 状态
        $variables['status'] = [
            'variableName'  => 'status',
            'type'          => 'string',
            'value'         => 'active'
        ];

        // 下单时间
        $variables['request_date'] = [
            'variableName'  => 'request_date',
            'type'          => 'string',
            'value'         => $data->request_date ?: ''
        ];

        // 买方
        $variables['customer'] = [
            'variableName'  => 'customer',
            'type'          => 'string',
            'value'         => $data->customer->name ?: ''
        ];

        // 下单人
        $variables['requester'] = [
            'variableName'  => 'requester',
            'type'          => 'string',
            'value'         => $data->requester_name ?: ''
        ];

        // 商品
        $items = $data->items;
        $types = [];
        foreach ($items as $item) {
            $products .= $item->name.' ';
            $casNO = $item->cas_no;
            $chem_types = (array) \Gini\ChemDB\Client::getTypes($casNO)[$casNO];
            $types = array_unique(array_merge($types, $chem_types));
        }
        $variables['products'] = [
            'variableName'  => 'products',
            'type'          => 'string',
            'value'         => trim($products) ?: ''
        ];

        $variables['types'] = [
            'variableName'  => 'types',
            'type'          => 'string',
            'value'         => count($types) ? implode(' ', $types) : ''
        ];

        $variables['vendor'] = [
            'variableName'  => 'vendor',
            'type'          => 'string',
            'value'         => $data->vendor_name ?: ''
        ];

        return $variables;
    }

    private function _setVariable($engine, $instance, $data = [])
    {

        $variables = $this->_getVariables($data);
        foreach ($variables as $name => $variable) {
            try {
                $bool = $instance->setVariable($variable);
            } catch (\Gini\BPM\Exception $e) {
                continue;
            }
        }

        return $bool ?: false;
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
