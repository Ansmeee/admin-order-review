<?php

namespace Gini\Controller\CLI\BPM;

class User extends \Gini\Controller\CLI
{
    public function actionSyncWechatInfo()
    {
    	$users = Those('sjtu/bpm/process/group/user');
    	$conf = \Gini\Config::get('tag-db.rpc');
    	$client = \Gini\Config::get('tag-db.client');
    	$rpc = \Gini\Ioc::construct('\Gini\RPC', $conf['url']);
    	$token = $rpc->tagdb->authorize($client['id'], $client['secret']);
    	if (!$token) return;
    	foreach ($users as $user) {
    		if (!$user->wechat_data) {
				$wechat_data = [];
				$uid = $user->user->id;
				$tag = "labmai-user/{$uid}";
				$tagData = $rpc->tagdb->data->get($tag);
				if ($tagData['openid'] && $tagData['unionid']) {
					$wechat_data = [
						'openid' => $tagData['openid'],
						'unionid' => $tagData['unionid'],
					];
					$user->wechat_data = $wechat_data;
					$user->save();
					echo '.';
				}
    		}
    	}
    }
}
