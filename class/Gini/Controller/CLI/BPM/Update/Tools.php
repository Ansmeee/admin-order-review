<?php

namespace Gini\Controller\CLI\BPM\Update;

class Tools extends \Gini\Controller\ClI
{
    public function __index($args)
    {
        echo "订单审批数据升级脚本:\n";
        echo "审批组升级: gini bpm update tools groups \n";
        echo "用户升级: gini bpm update tools users \n";
        echo "审批组成员升级: gini bpm update tools members \n";
    }

    public function actionGroups()
    {
        //获取历史审批组
        $his_groups = Those('sjtu/bpm/process/group');
        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        foreach ($his_groups as $his_group) {
            try {
                $key = $his_group->name;
                $arr = explode('-', $key);

                if (in_array('school', $arr)) {
                    $params['id'] = $conf['name'].'-'.$key;
                } else {
                    $params['id'] = $key;
                }
                $params['name'] = $his_group->title;
                $params['type'] = $conf['name'];
                $group = $engine->group();
                $bool = $group->create($params);
                if ($bool) {
                    echo $his_group->name."--o \n";
                } else {
                    echo $his_group->name."--x \n";
                }
            } catch (\Gini\BPM\Exception $e) {
            }
        }

        echo "DONE \n";
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

    public function actionMembers()
    {
        $start = 0;
        $perpage = 25;
        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        while (true) {
            $his_members = Those('sjtu/bpm/process/group/user')->limit($start, $perpage);
            $start += $perpage;
            if (!count($his_members)) return ;

            foreach ($his_members as $his_member) {
                try {
                    $key = $his_member->group->name;
                    $arr = explode('-', $key);

                    if (in_array('school', $arr)) {
                        $gid = $conf['name'].'-'.$key;
                    } else {
                        $gid = $key;
                    }
                    $group = $engine->group($gid);
                    $bool = $group->addMember($his_member->user->id);
                    if ($bool) {
                        echo $his_member->id."--o\n";
                    } else {
                        echo $his_member->id."--x\n";
                    }
                } catch (\Gini\BPM\Exception $e) {
                    continue;
                }
            }
        }
        echo "DONE\n";
    }

    public function actionInstances()
    {
        $start = 0;
        $perpage = 25;
        $node = \Gini\Config::get('app.node');
        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        $process = $engine->process($conf['name']);
        while (true) {
            $instances = Those('sjtu/bpm/process/instance')->limit($start, $perpage);
            $start += $perpage;
            if (!count($instances)) return;
            $cacheData = [];
            foreach ($instances as $instance) {
                $order_data = $instance->data['data'];
                $cacheData['data'] = $order_data;
                $types = [];
                $items = (array) json_decode($order_data['items']);
                $types = [];
                foreach ($items as $item) {
                    $item = (array) $item;
                    $casNO = $item['cas_no'];
                    $chem_types = (array) \Gini\ChemDB\Client::getTypes($casNO)[$casNO];
                    $types = array_unique(array_merge($types, $chem_types));
                }
                $cacheData['customized'] = $order_data['customized'];
                $cacheData['chemicalTypes'] = $types;

                $key = "labmai-".$node."/".$order_data['group_id'];
                $info = (array)\Gini\TagDB\Client::of('rpc')->get($key);
                $cacheData['candidate_group'] = (int)$info['organization']['school_code'];

                $steps = $conf['steps'];
                foreach ($steps as $step) {
                    if ($step == 'school') continue;
                    $cacheData[$step] = $step;
                }
                $cacheData['key'] = $conf['name'];
                try {
                    $create_instance = $process->start($cacheData);
                    if ($create_instance->id) {
                        while (true) {
                            $search_params['active'] = true;
                            $search_params['instance'] = $create_instance->id;

                            $o = $engine->searchTasks($search_params);
                            if (!$o->total) break;
                            $tasks = $engine->getTasks($o->token, 0, $o->total);
                            $task = current($tasks);
                            $assignee = $task->assignee;
                            $ass = explode('-', $assignee);
                            $as = end($ass);
                            $his_tasks = Those('sjtu/bpm/process/task')
                                ->Whose('instance')->is($instance);
                            $params = [];
                            if (count($his_tasks)) {
                                foreach ($his_tasks as $his_task) {
                                    $candidate_group = $his_task->candidate_group;
                                    $cgn = $candidate_group->name;
                                    $cgs = explode('-', $cgn);
                                    $gid = end($cgs);
                                    if ($gid == $as) {
                                        $opt = $this->_getCurrentStep($assignee);
                                        if ($his_task->status == \Gini\Process\ITask::STATUS_APPROVED) {
                                            $params[$opt] = true;
                                        } else if ($his_task->status == \Gini\Process\ITask::STATUS_UNAPPROVED) {
                                            $params[$opt] = false;
                                        }

                                        $message = $his_task->message;
                                        $date = $his_task->date;
                                        $group = $his_task->group;
                                        $user = $his_task->user;
                                        $params[$opt.'_comment'] = [
                                            'message' => $message,
                                            'group' => $group,
                                            'user' => $user,
                                            'date' => $date
                                        ];

                                        $bool = $task->complete($params);
                                        if ($bool) {
                                            echo $task->id."-".$task->assignee.'-'.$his_task->id."-o\n";
                                        } else {
                                            echo $task->id."-".$task->assignee.'-'.$his_task->id."-x\n";
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        echo $instance->id."--x\n";
                    }
                    echo $instance->id."--o\n";
                } catch (\Gini\BPM\Exception $e) {
                    echo $instance->id."---x\n";
                }
            }
        }

        echo "DONE \n";
    }

    private function _getCurrentStep($assignee)
    {
        $conf = \Gini\Config::get('app.order_review_process');
        $steps = $conf['steps'];
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
}
