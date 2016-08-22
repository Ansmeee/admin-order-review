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

        $per_page = 20;
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

        $engine = \Gini\Process\Engine::of('default');
        $processName = \Gini\Config::get('app.order_review_process');

        $process = $engine->getProcess($processName);

        $group  = $process->getGroup($pname);
        if (!$group->id) return false;

        $success = !!$group->addUser($user);

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

        $engine = \Gini\Process\Engine::of('default');
        $processName = \Gini\Config::get('app.order_review_process');

        $process = $engine->getProcess($processName);

        $group  = $process->getGroup($pname);
        if (!$group->id) return false;

        $success = !!$group->removeUser($user);

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', $success);
    }

}
