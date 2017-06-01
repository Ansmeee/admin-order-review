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
}

