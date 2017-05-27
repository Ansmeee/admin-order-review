<?php

namespace Gini\Controller\CGI\AJAX\Settings;

class BPM extends \Gini\Controller\CGI
{
    public function actionMoreMembers($start = 0)
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        $per_page = 25;
        $next_start = $start + $per_page;

        $form = $this->form();
        $members = thoseIndexed('user')->filter(['query' => $form['q']])->fetch($start, $per_page);

        return new \Gini\CGI\Response\HTML(V(
            'settings/bpm/members',
            [
                'members' => $members,
                'start' => $start,
                'next_start' => $next_start,
            ]));
    }

    public function actionAddUser($pname = '')
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        $post = $this->form('post');
        if (!isset($post['id'])) return false;

        $user = a('user', $post['id']);
        if (!$user->id) return false;
        try {
            $engine = \Gini\BPM\Engine::of('order_review');
            $group  = $engine->group($pname);

            if (!$group->id) return false;
            $success = $group->addMember($user->id);
        } catch (\Gini\BPM\Exception $e) {
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $success);
    }

    /**
     * @brief 移除member的角色
     *
     * @return
     */
    public function actionRemoveUser($pname = '')
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        $post = $this->form('post');
        if (!isset($post['id'])) return false;

        $user = a('user', $post['id']);
        if (!$user->id) return false;

        try {
            $engine = \Gini\BPM\Engine::of('order_review');
            $group  = $engine->group($pname);
            if (!$group->id) return false;

            $success = $group->removeMember($user->id);
        } catch (\Gini\BPM\Exception $e) {
        }
        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $success);
    }

    public function actionRemoveGroup()
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        $post = $this->form('post');
        if (!isset($post['group'])) return false;

        $engine = \Gini\BPM\Engine::of('order_review');
        $group = $engine->group($post['group']);
        $success = $group->delete();

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $success ? true : T('操作失败, 请重试'));
    }

    public function actionAddGroup()
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        return self::_showEditGroupForm();
    }

    public function actionEditGroup()
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }
        $get = $this->form('get');
        if (!isset($get['group'])) return false;

        $engine = \Gini\BPM\Engine::of('order_review');
        $group  = $engine->group($get['group']);

        if (!$group->id) return false;
        return self::_showEditGroupForm([
            'group'=> $group
        ]);
    }

    private static function _showEditGroupForm(array $data=[])
    {
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', (string)V('settings/bpm/edit-group-form', $data));
    }

    public function actionSubmitGroup()
    {
        $group = _G('GROUP');
        $me = _G('ME');
        if (!$group->id || !$me->isAllowedTo('管理权限')) {
            return false;
        }

        $post = $this->form('post');
        $id = $post['id'];
        $key = $post['key'];
        $name = $post['name'];

        $validator = new \Gini\CGI\Validator();
        try {
            $validator
                ->validate('key', $key, T('请填写分组标识'))
                ->validate('name', $name, T('请填写分组名称'))
                ->done();
        } catch (\Gini\CGI\Validator\Exception $e) {
            $errors = $validator->errors();
        }

        if (!empty($errors)) {
            return self::_showEditGroupForm([
                'errors'=> $errors,
                'form'=> $post
            ]);
        }

        $engine = \Gini\BPM\Engine::of('order_review');
        $conf = \Gini\Config::get('app.order_review_process');
        $processName = $conf['name'];
        if ($id) {
            try {
                $rgroup  = $engine->group($id);
            } catch (\Gini\BPM\Exception $e) {
            }

            $method = 'update';
            if (!$rgroup->id) return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('操作失败'));

            try {
                $update_group  = $engine->group($key);
            } catch (\Gini\BPM\Exception $e) {
            }

            if ($update_group->id && $update_group->id != $rgroup->id) {
                return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('操作失败, 分组标识已被占用'));
            }
        } else {
            try {
                $rgroup  = $engine->group($key);
                if ($rgroup->id) {
                    return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('操作失败, 分组标识已被占用'));
                }
            } catch (\Gini\BPM\Exception $e) {
                $rgroup = $engine->group();
            }
            $method = 'create';
        }

        try {
            $bool = $rgroup->$method([
                'id'=> $key,
                'name'=> $name,
                'type'=> $processName
            ]);
        } catch (\Gini\BPM\Exception $e) {
            return \Gini\IoC::construct('\Gini\CGI\Response\JSON', T('操作失败'));
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $bool ? true : T('操作失败'));
    }
}

