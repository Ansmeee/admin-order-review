<?php

namespace Gini\Controller\CLI\BPM;

class Task extends \Gini\Controller\CLI
{
    public function actionRun($argv)
    {
        if (count($argv) == 0) return;
        $candidateGroup = $argv[0];
        $orderData = unserialize($argv[0]);
        $confBpm = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        try {
            $group = $engine->group($candidateGroup);
            if (!$group->id) return ;
        } catch (\Gini\BPM\Exception $e) {
            return ;
        }

        $conf = \Gini\Config::get('wechat.gateway');
        $templates = \Gini\Config::get('wechat.templates');
        $template = $templates['order-need-review'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['api_url']);
        $token = $rpc->wechat->authorize($conf['client_id'], $conf['client_secret']);
        $templateID = $template['id'];
        $content = $template['content'];
        if (!$token) return;
        $rdata = explode(',', rtrim(ltrim($orderData,'{'), '}'));
        foreach($rdata as $d) {
            $d = explode(':', $d);
            $data[$d[0]] = $d[1];
        }
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
                'value' => $data['customer_name'],
            ],
            'type' => [
                'color' => '#173177',
                'value' => $data['customized']?'线下':'线上',
            ],
            'price' => [
                'color' => '#173177',
                'value' => money_format('%.2n', $data['price']),
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

        try {
            $gus = $group->getMembers();
            if (!count($gus)) return ;
        } catch (\Gini\BPM\Exception $e) {
            return ;
        }

        $tagRpc = \Gini\Module\AppBase::getTagDBRPC();
        foreach ($gus as $user) {
            $uid = (int)$user->id;
            $tag = "labmai-user/{$uid}";
            $tagData = $tagRpc->tagdb->data->get($tag);
            if ($tagData['openid'] && $tagData['unionid']) {
                $openID = $tagData['openid'];
                $rpc->wechat->sendTemplateMessage($openID, $templateID, $data);
            }
        }
    }
}
