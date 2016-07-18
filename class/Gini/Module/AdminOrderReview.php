<?php

namespace Gini\Module;

class AdminOrderReview {
    public static function setup(){
    }

    private static function _userHasPermission($user, $perm)
    {
        static $_PERM_CACHE = [];
        $group = _G('GROUP');
        if (!isset($_PERM_CACHE[$perm])) {
            $permission = a('user/permission', ['group' => $group, 'name' => $perm]);
            foreach ($permission->users as $u) {
                $_PERM_CACHE[$perm][$u->id] = true;
            }
        }

        return (bool) $_PERM_CACHE[$perm][$user->id];
    }

    public static function commonACL($e, $user, $action, $project, $when, $where)
    {
        if (!$user->id) {
            return false;
        }

        $group = _G('GROUP');
        if ($user->isAdminOf($group)) {
            return true;
        }

        if (self::_userHasPermission($user, 'admin')) {
            return true;
        }

        if ($action == '查看采购审核') {
            if (self::_userHasPermission($user, 'order_admin')) {
                return true;
            }
        } elseif($action == '权限管理'){
            if (self::_userHasPermission($user, 'order_admin')) {
                return true;
            }
        }

        return false;
    }
}
