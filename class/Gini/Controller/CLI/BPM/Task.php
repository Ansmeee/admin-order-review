<?php

namespace Gini\Controller\CLI\BPM;

class Task extends \Gini\Controller\CLI
{
    public function actionRun($argv)
    {
        if (count($argv) == 0) return;
        $id = (int)$argv[0];
        $task = a('sjtu/bpm/process/task', $id);
        if (!$task->id) return;
        $conf = \Gini\Config::get('wechat.gateway');
        $templates = \Gini\Config::get('wechat.templates');
        $template = $templates['order-need-review'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['api_url']);
        $token = $rpc->api->wechat->authorize($conf['client_id'], $conf['client_secret']);
		$templateID = $template['id'];
		$content = $template['content'];
        if (!$token) return;
        $group = $task->candidate_group;
        $users = $group->getUsers();
        $instance = $task->instance;
        $data = (array)$instance->data;
        $data = $data['data'];
		$raw_data = [
		    'title'=> [
		        'color'=>'#173177',
		        'value'=> '您有一个新订单需要审核'
		    ],
		    'vendor' => [
		        'color' => '#173177',
		        'value' => $data['vendor_name'],
		    ],
		    'customer' => [
		        'color' => '#173177',
		        'value' => $data['customer']['name'],
		    ],
		    'type' => [
		        'color' => '#173177',
		        'value' => $data['customized']?'线下':'线上',
		    ],
		    'price' => [
		        'color' => '#173177',
		        'value' => $data['price'],
		    ],
		    'time' => [
		        'color' => '#173177',
		        'value' => date('Y-m-d H:i:s'),
		    ],
		    'note' => [
		        'color' => '#173177',
		        'value' => $data['note'],
		    ],
		];
		$data = [];
		foreach ($content as $k => $v) {
		    $data[$v] = $raw_data[$k];
		}
        foreach ($users as $user) {
        	$wechat_data =  (array)$user->wechat_data;
        	if ($openID = $wechat_data['openid']) {
        		$rpc->wechat->sendTemplateMessage($openID, $templateID, $data);
        	}
        }
    }
}
