<?php

namespace Gini\Controller\CGI\AJAX\Settings;

class Member extends \Gini\Controller\CGI
{
    public function actionGetAddMemberTypes()
    {
        $current = \Gini\Gapper\Client::getLoginStep();
        if ($current!==\Gini\Gapper\Client::STEP_DONE) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');

        $app = \Gini\Gapper\Client::getInfo();
        if (strtolower($app['type'])!='group') return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');

        $conf = (array) \Gini\Config::get('gapper.auth');
        $data = [];
        foreach ($conf as $type=>$info) {
            $handler = $info['add_member_handler'] ?: "\Gini\Controller\CGI\AJAX\Gapper\Auth\\{$type}::addmember";
            if (is_callable($handler)) {
                $data[$type] = $info;
                continue;
            }
        }
        if (!empty($data)) {
                return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('settings/member/add-member-types', [
                'data'=> $data,
                'group'=> \Gini\Gapper\Client::getGroupID()
            ]));
        }
    }

    public function actionGetAddModal()
    {
        $form = $this->form('post');
        $conf = (array) \Gini\Config::get('gapper.auth');
        $type = $form['type'];
        $gid = $form['gid'];
        if ($gid!=\Gini\Gapper\Client::getGroupID()) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }

        $info = $conf[$type];
        if (empty($info)) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');

        $handler = $info['add_member_handler'] ?: "\Gini\Controller\CGI\AJAX\Gapper\Auth\\{$type}::addmember";
        if (!is_callable($handler)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }
        return call_user_func_array($handler, ['get-add-modal', $type, $gid]);
    }

    public function actionSearch()
    {
        $data = $this->form('get');
        $value = $data['value'];
        $type = $data['type'];
        if (!$value) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        $conf = (array) \Gini\Config::get('gapper.auth');
        $info = $conf[$type];
        if (empty($info)) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        $handler = $info['add_member_handler'] ?: "\Gini\Controller\CGI\AJAX\Gapper\Auth\\{$type}::addmember";

        if (!is_callable($handler)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }
        return call_user_func_array($handler, ['search', $type, $value]);
    }

    public function actionPostAdd()
    {
        $form = $this->form('post');
        $username = $form['username'];
        if (empty($username)) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        $get = $this->form('get');
        $type = $get['type'];
        $conf = (array) \Gini\Config::get('gapper.auth');
        $info = $conf[$type];
        if (empty($info)) return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        $handler = $info['add_member_handler'] ?: "\Gini\Controller\CGI\AJAX\Gapper\Auth\\{$type}::addmember";
        if (!is_callable($handler)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }

        if ($type === 'gateway') {
            $identitySource = \Gini\Config::get('app.node');
            $info = \Gini\Gapper\Client::getRPC()->gapper->user->getUserByIdentity($identitySource, $username);
            $username = $info['email'] ?: $username;
        }

        $user = a('user', ['username' => $username]);
        if (!$user->id) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }

        $engine = \Gini\BPM\Engine::of('order_review');
        try {
            $camunda_user = $engine->user($user->id);
            if ($camunda_user->id) {
                return call_user_func_array($handler, ['post-add', $type, $form]);
            }
        } catch (\Gini\BPM\Exception $e) {
            $camunda_user = $engine->user();
        }

        //密码暂时这样设置,得再想想
        $params['id'] = $user->id;
        $params['firstName'] = $user->name;
        $params['lastName'] = $user->name;
        $params['email'] = $user->email;
        $arr = explode('@', $user->email, 2);
        $password = $arr[0].'_'.$user->id;
        $params['password'] = $password;
        try {
            $bool = $camunda_user->create($params);
            if ($bool) {
                return call_user_func_array($handler, ['post-add', $type, $form]);
            }
        } catch (\Gini\BPM\Exception $e) {
            return \Gini\IoC::construct('\Gini\CGI\Response\Nothing');
        }
    }
}
