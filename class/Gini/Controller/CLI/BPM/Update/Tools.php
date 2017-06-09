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
                if ($bool) {
                    $group = $engine->group($params['id']);
                    echo $his_group->name."--o \n";
                    $group_users = Those('sjtu/bpm/process/group/user')
                        ->Whose('group')->is($his_group);
                    foreach ($group_users as $group_user) {
                        $ret = $group->addMember($group_user->user->id);
                        if (!$ret) {
                            echo $group->id."---".$group_user->user->id."--x \n";
                            continue;
                        }
                        echo $group->id."---".$group_user->user->id."--o \n";
                    }
                } else {
                    echo $his_group->name."--x \n";
                }
            } catch (\Gini\BPM\Exception $e) {
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

                $steps = $conf['steps'];
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
        $perpage = 25;
        $node = \Gini\Config::get('app.node');

        $his_process_name = 'order-review-process';
        $his_engine = \Gini\Process\Engine::of('default');
        $his_process = $his_engine->getProcess($his_process_name);

        $processName = 'update-his-instance';
        $engine = \Gini\BPM\Engine::of('order_review');
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
                        $search_params['instance'] =  $create_instance->id;
                        $result = $engine->searchTasks($search_params);
                        $tasks = $engine->getTasks($result->token, 0, $result->total);
                        $task = current($tasks);
                        if (!$task->complete()) {
                            echo $instance->id.'---'.$create_instance->id."---task---".$task->id."---x\n";
                            continue;
                        }
                        echo $instance->id.'---'.$create_instance->id."---o\n";
                    }
                } catch (\Gini\BPM\Exception $e) {
                   echo $instance->id."---x\n";
                }
            }
        }
    }
}
