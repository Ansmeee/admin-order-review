<?php
/**
* @file Settings.php
* @brief  为FE提供的前端接口
* @author xuguang.chen
* @version 0.1.0
* @date 2018-01-03
 */
namespace Gini\Controller\CGI\Rest;

class Settings extends Base\Index
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
                "path"   => T("review/authority")
            ];
        }

        $data['list'][] = [
            "model"  => T("wxbind"),
            "title"  => T("微信绑定"),
            "url"    => T(\Gini\Module\AdminBase::getRedirectUrl('qr'))
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
        // 获取组信息
        $groups = $this->_getGroups();

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
    }

    /**
        * @brief 获取组内成员
        *
        * @return
     */
    public function getGroupMembers()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form     = $this->form;
        $id       = $form['id'];
        if (!$form || !$id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) throw new \Gini\BPM\Exception();
            list($process, $engine) =  $process_engine;
            // 获取当前组内用户
            $rgroup  = $engine->group($id);
            if (!$rgroup->id) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            $members = $rgroup->getMembers();
            $data['total'] = count($members);
            $data['list']  = [];
            foreach ($members as $member) {
                $data['list'][] = [
                    'id'   => (int)$member->id,
                    'name' => $member->firstName
                ];
            }

            $response = $this->response(200, T('获取成功'), $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 获取全部用户
        *
        * @return
     */
    public function getMembers()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form     = $this->form;
        $groupId  = $form['group_id'];
        $keywords = $form['keywords'];

        $start = (int)$form['current_page'] - 1;
        if ($start < 0) $start = 0;

        $per_page = (int)$form['page_size'];
        if ($per_page < 1) $per_page = 12;

        if (!$form || !$groupId) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $data = [];
        try {
            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) throw new \Gini\BPM\Exception();
            list($process, $engine) =  $process_engine;
            // 获取当前组内用户
            $rgroup  = $engine->group($groupId);
            if (!$rgroup) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            // 获取本地用户
            $users = thoseIndexed('user');
            if ($keywords) $users = $users->filter(['query' => $keywords]);
            $users = $users->fetch($start*$per_page, $per_page);

            foreach ($users as $user) {
                // 查看用户是否在组里
                $checked = false;
                if ($rgroup->hasMember($user->id)) {
                    $checked = true;
                }
                // 获取用户信息
                $gapperUser = a('user', (int) $user->id);
                if (!$gapperUser->id) {
                    continue;
                }
                // 获取用户头像
                if (parse_url($gapperUser->icon)['scheme'] == 'initials') {
                    $icon = $gapperUser->initials;
                } else {
                    $icon = $gapperUser->icon(72);
                }

                $data['list'][] = [
                    'id'      => $gapperUser->id,
                    'name'    => $gapperUser->name,
                    'icon'    => $icon,
                    'checked' => $checked
                ];
            }

            $response = $this->response(200, T('获取成功'), $data);
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('获取失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 添加组成员
        *
        * @return
     */
    public function postAddGroupMember()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$group->id || !$me->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form    = $this->form;
        $userId  = $form['user_id'];
        $groupId = $form['group_id'];
        // 验证参数
        if (!$userId || !$groupId) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            // 查找分组
            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) throw new \Gini\BPM\Exception();
            list($process, $engine) =  $process_engine;
            $need_create = false;
            // 验证用户参数
            try {
                // 判断用户是否已经在camunda 上注册了，如果没有，就给他注册上
                $camunda_user = $engine->user($userId);
                if (!$camunda_user->id) {
                    $need_create = true;
                }
            } catch (\Gini\BPM\Exception $e) {
                $need_create = true;
            }

            if ($need_create) {
                $user = a('user', $userId);
                if (!$user->id) {
                    $response = $this->response(403, T('参数错误'));
                    return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
                }
                
                $camunda_user = $engine->user();

                $params['id']        = $user->id;
                $params['firstName'] = $user->name;
                $params['lastName']  = $user->name;
                $params['email']     = $user->email;
                $arr                 = explode('@', $user->email, 2);
                $password            = $arr[0].'_'.$user->id;
                $params['password']  = $password;

                $bool = $camunda_user->create($params);
                if (!$bool) {
                    throw new \Gini\BPM\Exception();
                }
            }

            // 验证组参数
            $rgroup  = $engine->group($groupId);
            if (!$rgroup->id) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            // 添加成员
            $bool = $rgroup->addMember($camunda_user->id);
            if ($bool) {
                $response = $this->response(200, T('操作成功'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            } else{
                $response = $this->response(400, T('操作失败，请重试'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('操作失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 删除组成员
        *
        * @return
     */
    public function postDeleteGroupMember()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$group->id || !$me->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form    = $this->form;
        $userId  = $form['user_id'];
        $groupId = $form['group_id'];
        // 验证参数
        if (!$userId || !$groupId) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {
            // 查找分组
            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) throw new \Gini\BPM\Exception();
            list($process, $engine) =  $process_engine;
            // 验证用户参数
            $camunda_user = $engine->user($userId);
            if (!$camunda_user->id) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
            // 验证组参数
            $rgroup  = $engine->group($groupId);
            if (!$rgroup->id) {
                $response = $this->response(403, T('参数错误'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

            // 添加成员
            $bool = $rgroup->removeMember($camunda_user->id);
            if ($bool) {
                $response = $this->response(200, T('操作成功'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            } else{
                $response = $this->response(400, T('操作失败，请重试'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('操作失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 获取可创建的组信息
        *
        * @return
     */
    public function getAddGroupList()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$group->id || !$me->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 获取组织机构
        $organizations = $this->_getOrganization();

        // 获取已存在的组别
        $groups = $this->_getGroups();
    
        // 去除已存在的组
        $rgroups = array_udiff($organizations, $groups, function ($a, $b) {
            $a = (array) $a;
            $b = (array) $b;
            if ($a['id'] === $b['id']) {
                return 0;
            }
            return ($a['id'] > $b['id']) ? 1 : -1;
        });

        foreach ($rgroups as $key => $value) {
           $data['list'][] = $value;
        }
        
        $response = $this->response(200, T('获取成功'), $data);
        return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        
    }

    /**
        * @brief 添加组
        *
        * @return
     */
    public function postAddGroup()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$group->id || !$me->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form    = $this->form;
        $id      = $form['id'];
        // 验证参数
        if (!$form || !$id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $process_engine = $this->_getProcessEngine();
        if ($process_engine === false) {
            $response = $this->response(400, T('操作失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
        list($process, $engine) =  $process_engine;
        // 查看是组是否已存在
        try {
            $rgroup = $engine->group($id);
        } catch (\Gini\BPM\Exception $e) {
        }
        
        if ($rgroup && $rgroup->id) {
            $response = $this->response(400, T('此组已存在，请不要重复创建'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        // 获取组织机构
        $organizations = $this->_getOrganization();
        // 查看是否存在此组织机构
        foreach ($organizations as $organization) {
            if ($id === $organization['id']) {
                $info = $organization;
                break;
            }
        }

        if (!$info) {
            $response = $this->response(403, T('请选择正确的组织机构'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $info['type'] = $process->id;

        // 创建分组
        $bool = $engine->group()->create($info);
        if ($bool) {
            $response = $this->response(200, T('操作成功'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        } else {
            $response = $this->response(400, T('操作失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    /**
        * @brief 删除组
        *
        * @return
     */
    public function postDeleteGroup()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$group->id || !$me->id || !$me->isAllowedTo('管理权限')) {
            $response = $this->response(401, T('无权访问'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        $form    = $this->form;
        $id      = $form['id'];
        // 验证参数
        if (!$form || !$id) {
            $response = $this->response(403, T('参数错误'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }

        try {

            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) {
                $response = $this->response(400, T('操作失败，请重试'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
            list($process, $engine) =  $process_engine;
            // 查看是组是否已存在
            $rgroup = $engine->group($id);
            if (!$rgroup->id) {
                $response = $this->response(400, T('请选择正确的组'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }
            // 删除分组
            $bool = $rgroup->delete();
            if ($bool) {
                $response = $this->response(200, T('操作成功'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            } else {
                $response = $this->response(400, T('操作失败，请重试'));
                return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
            }

        } catch (\Gini\BPM\Exception $e) {
            $response = $this->response(400, T('操作失败，请重试'));
            return \Gini\IoC::construct('\Gini\CGI\Response\Json', $response);
        }
    }

    private function _getProcessEngine()
    {
        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);
        } catch (\Gini\BPM\Exception $e) {
            return  false;
        }
        return [$process, $engine];
    }

    // 获取组织机构信息
    private function _getOrganization()
    {
        // 获取 bpm 流程配置
        $conf = \Gini\Config::get('app.order_review_process');
        if ($conf) {
        // 获取 bpm 审批机构信息
            foreach ($conf['steps'] as $code => $step) {
            if ($code === 'school') continue;
                $list[] = [
                    'id'   => $conf['name'].'-'.$code,
                    'name' => $step
                ];
            }
        }

        // 获取组织机构信息
        $cacher = \Gini\Cache::of('gateway');
        $key = 'schools';
        $organization = $cacher->get($key);
        if (!is_array($organization)) {
            $rpc = \Gini\Module\AppBase::getGatewayRPC();
            $organization = (array)$rpc->Gateway->Organization->getSchools();
            if (is_array($organization) && count($organization)) {
                $cacher->set($key, $organization, 86400);
            }
        }

        foreach ($organization as $key => $value) {
            $list[] = [
                'id'    => $conf['name'].'-school-'.$value['code'],
                'name'  => $value['name']
            ];
        }

        return $list;
    }

    // 获取组信息
    private function _getGroups()
    {
        try {
            // 获取所有分组
            $process_engine = $this->_getProcessEngine();
            if ($process_engine === false) return false;
            list($process, $engine) =  $process_engine;
            $params['type'] = $process->id;
            $o = $engine->searchGroups($params);
            $groups = $engine->getGroups($o->token, 0, $o->total);
            return $groups;
        } catch (\Gini\BPM\Exception $e) {
            return [];
        }
    }

    
}
