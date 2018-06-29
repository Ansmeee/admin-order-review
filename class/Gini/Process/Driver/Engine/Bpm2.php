<?php

namespace Gini\Process\Driver\Engine;

class Bpm2
{
    private $_db;
    public function __construct()
    {
        $this->_db = \Gini\Database::db('camunda');
    }

    private $_cacheQuery = [];
    public function getInstancesTotal($criteria = []) {
        $db = $this->_db;

        $params['key']       = $criteria['key'];
        $params['getTotal']  = true;

        // 按照订单编号搜索
        if (isset($criteria['voucher'])) {
            $params['voucher'] = $criteria['voucher'];

            $cacheKey = sha1(json_encode($params));
            $total    = self::cache($cacheKey);
            if (!is_numeric($total)) {
                $sql = self::_getSearchByVoucherSql($params);
                $total = @$db->value($sql);
                if (is_numeric($total)) {
                    self::cache($cacheKey, $total);
                }
            }

            return $total ?: 0;
        }

        // 按 审批组 和 状态查询
        if (isset($criteria['candidate_group']) && isset($criteria['status'])){
            $params['candidate_group'] = $criteria['candidate_group'];
            $params['status']          = $criteria['status'];

            $cacheKey = sha1(json_encode($params));
            $total    = self::cache($cacheKey);

            if (!is_numeric($total)) {
                $sql = self::_getSearchByGroupAndStatusSql($params);
                $total = @$db->value($sql);
                if (is_numeric($total)) {
                    self::cache($cacheKey, $total);
                }
            }

            return $total ?: 0;
        }

        // 按 审批组 查询
        if (isset($criteria['candidate_group'])) {
            $params['name']  = 'candidate_group';
            $params['value'] = $criteria['candidate_group'];

            $cacheKey = sha1(json_encode($params));
            $total    = self::cache($cacheKey);

            if (!is_numeric($total)) {
                $sql = self::_getSql($params);
                $total = @$db->value($sql);
                if (is_numeric($total)) {
                    self::cache($cacheKey, $total);
                }
            }

            return $total ?: 0;
        }

        // 按 状态 查询
        if (isset($criteria['status'])) {
            $params['name']  = 'status';
            $params['value'] = $criteria['status'];

            $cacheKey = sha1(json_encode($params));
            $total    = self::cache($cacheKey);

            if (!is_numeric($total)) {
                $sql = self::_getSql($params);
                $total = @$db->value($sql);
                if (is_numeric($total)) {
                    self::cache($cacheKey, $total);
                }
            }

            return $total ?: 0;
        }

        // 没有查询条件
        $cacheKey = sha1(json_encode($params));
        $total    = self::cache($cacheKey);

        if (!is_numeric($total)) {
            $sql = "SELECT count(`ID_`) as id FROM `ACT_HI_PROCINST` WHERE `PROC_DEF_KEY_` = '{$criteria['key']}'";
            $total = @$db->value($sql);
            if (is_numeric($total)) {
                self::cache($cacheKey, $total);
            }
        }

        return $total ?: 0;
    }

    public function getInstances($criteria = [], $start = 0, $limit = 20)
    {
        $db = $this->_db;
        $params = [];
        // 处理排序方式
        if (isset($criteria['orderBy'])) {
            $sortOrder  = key($criteria['orderBy']);
            $by         = current($criteria['orderBy']);
            switch ($sortOrder) {
                case 'startTime':
                    $order = 'START_TIME_';
                    break;
            }
        }

        $params['key']       = $criteria['key'];
        $params['sortOrder'] = $order;
        $params['sortBy']    = $by;

        // 按照订单编号搜索
        if (isset($criteria['voucher'])) {
            $params['voucher'] = $criteria['voucher'];
            $sql = self::_getSearchByVoucherSql($params);
            $sql = $sql." LIMIT {$start}, {$limit}";
            $data = @$this->_db->query($sql)->rows();

            return $data;
        }

        // 按 审批组 和 状态查询
        if (isset($criteria['candidate_group']) && isset($criteria['status'])){
            $params['candidate_group'] = $criteria['candidate_group'];
            $params['status']          = $criteria['status'];
            $sql = self::_getSearchByGroupAndStatusSql($params);
            $sql = $sql." LIMIT {$start}, {$limit}";
            $data = @$this->_db->query($sql)->rows();

            return $data;
        }

        // 按 审批组 查询
        if (isset($criteria['candidate_group'])) {
            $params['name']  = 'candidate_group';
            $params['value'] = $criteria['candidate_group'];
            $sql = self::_getSql($params);
            $sql = $sql." LIMIT {$start}, {$limit}";
            $data = @$this->_db->query($sql)->rows();

            return $data;
        }

        // 按 状态 查询
        if (isset($criteria['status'])) {
            $params['name']  = 'status';
            $params['value'] = $criteria['status'];

            $sql = self::_getSql($params);
            $sql = $sql." LIMIT {$start}, {$limit}";
            $data = @$this->_db->query($sql)->rows();

            return $data;
        }

        // 没有查询条件
        $sql = "SELECT `ID_` as id FROM `ACT_HI_PROCINST` USE INDEX (ACT_HI_PRO_START_TIME) WHERE `PROC_DEF_KEY_` = '{$criteria['key']}'";
        if ($order && $by) {
            $sql .= " ORDER BY `{$order}` {$by}";
        }

        $sql = $sql." LIMIT {$start}, {$limit}";
        $data = @$this->_db->query($sql)->rows();

        return $data;
    }

    private static function _getSql($criteria = [])
    {
        $name  = $criteria['name'];
        $value = $criteria['value'];
        $key   = $criteria['key'];
        $order = $criteria['sortOrder'];
        $by    = $criteria['sortBy'];
        $getTotal = $criteria['getTotal'];

        if ($getTotal) {
            $sql = "SELECT count(a.`ID_`) FROM `ACT_HI_PROCINST` as a left join `ACT_HI_VARINST` as b on a.`ID_`=b.`PROC_INST_ID_` WHERE b.`PROC_DEF_KEY_` = '{$key}' AND b.`NAME_` = '{$name}' AND b.`TEXT_`= '{$value}'";

        } else {
            $sql = "SELECT a.`ID_` as id FROM `ACT_HI_PROCINST` as a USE INDEX (ACT_HI_PRO_START_TIME) left join `ACT_HI_VARINST` as b on a.`ID_`=b.`PROC_INST_ID_` WHERE b.`PROC_DEF_KEY_` = '{$key}' AND b.`NAME_` = '{$name}' AND b.`TEXT_`= '{$value}'";
            if ($order && $by) {
                $sql .= " ORDER BY a.`{$order}` {$by}";
            }
        }

        return $sql;
    }

    private static function _getSearchByVoucherSql($criteria = [])
    {
        $name  = 'voucher';
        $value = $criteria['voucher'];
        $key   = $criteria['key'];
        $order = $criteria['sortOrder'];
        $by    = $criteria['sortBy'];
        $getTotal = $criteria['getTotal'];

        if ($getTotal) {
            $sql = "SELECT count(a.`ID_`) FROM `ACT_HI_PROCINST` as a left join `ACT_HI_VARINST` as b on a.`ID_`=b.`PROC_INST_ID_` WHERE b.`PROC_DEF_KEY_` = '{$key}' AND b.`NAME_` = '{$name}' AND b.`TEXT_`= '{$value}'";
        } else {
            $sql = "SELECT a.`ID_` as id FROM `ACT_HI_PROCINST` as a USE INDEX (ACT_HI_PRO_START_TIME) left join `ACT_HI_VARINST` as b on a.`ID_`=b.`PROC_INST_ID_` WHERE b.`PROC_DEF_KEY_` = '{$key}' AND b.`NAME_` = '{$name}' AND b.`TEXT_`= '{$value}'";
            if ($order && $by) {
                $sql .= " ORDER BY a.`{$order}` {$by}";
            }
        }

        return $sql;
    }

    private static function _getSearchByGroupAndStatusSql($criteria = [])
    {
        $groupName   = 'candidate_group';
        $groupValue  = $criteria['candidate_group'];
        $statusName  = 'status';
        $statusValue = $criteria['status'];
        $key         = $criteria['key'];
        $order       = $criteria['sortOrder'];
        $by          = $criteria['sortBy'];
        $getTotal    = $criteria['getTotal'];

        if ($getTotal) {
            $sql = "SELECT count(`ID_`) FROM `ACT_HI_PROCINST` WHERE `ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `PROC_DEF_KEY_` = '{$key}' AND `NAME_` = '{$statusName}' AND `TEXT_`= '{$statusValue}' AND `PROC_INST_ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `PROC_DEF_KEY_` = '{$key}' AND `NAME_` = '{$groupName}' AND `TEXT_`= '{$groupValue}'))";
        } else {
             $sql = "SELECT `ID_` as id FROM `ACT_HI_PROCINST` USE INDEX (ACT_HI_PRO_START_TIME) WHERE `ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `PROC_DEF_KEY_` = '{$key}' AND `NAME_` = '{$statusName}' AND `TEXT_`= '{$statusValue}' AND `PROC_INST_ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `PROC_DEF_KEY_` = '{$key}' AND `NAME_` = '{$groupName}' AND `TEXT_`= '{$groupValue}'))";
            if ($order && $by) {
                $sql .= " ORDER BY {$order} {$by}";
            }
        }

        return $sql;
    }

    // 缓存
    public static function cache($key, $value = null) {
        $cacher = \Gini\Cache::of('default');
        if (is_null($value)) {
            return $cacher->get($key);
        }

        $conf = \Gini\Config::get('cache.default');
        $cacheTime = @$config['timeout'];
        $cacheTime = is_numeric($cacheTime) ? $cacheTime : 600;
        $cacher->set($key, $value, $cacheTime);
    }
}
