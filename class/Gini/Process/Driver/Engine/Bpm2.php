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
    public function searchInstances($criteria = [])
    {
        $db = $this->_db;
        $token = uniqid();
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

        // 按 审批组 和 状态查询
        if (isset($criteria['candidate_group']) && isset($criteria['status'])){
            $params['candidate_group'] = $criteria['candidate_group'];
            $params['status']          = $criteria['status'];
            $sql = self::_getSearchByGroupAndStatusSql($params);
            $data = @$db->query($sql)->rows();
            $this->_cacheQuery[$token] = $sql;

            return [
                'token' => $token,
                'total' => count($data)
            ];
        }

        // 按 审批组 查询
        if (isset($criteria['candidate_group'])) {
            $params['name']  = 'candidate_group';
            $params['value'] = $criteria['candidate_group'];
            $sql = self::_getSql($params);
            $data = @$db->query($sql)->rows();
            $this->_cacheQuery[$token] = $sql;

            return [
                'token' => $token,
                'total' => count($data)
            ];
        }

        // 按 状态 查询
        if (isset($criteria['status'])) {
            $params['name']  = 'status';
            $params['value'] = $criteria['status'];

            $sql = self::_getSql($params);
            $data = @$db->query($sql)->rows();
            $this->_cacheQuery[$token] = $sql;

            return [
                'token' => $token,
                'total' => count($data)
            ];
        }

        // 没有查询条件
        $sql = "SELECT `ID_` as id FROM `ACT_HI_PROCINST` WHERE `PROC_DEF_KEY_` = '{$criteria['key']}'";
        if ($order && $by) {
            $sql .= " ORDER BY `{$order}` {$by}";
        }

        $data = @$db->query($sql)->rows();
        $this->_cacheQuery[$token] = $sql;

        return [
            'token' => $token,
            'total' => count($data)
        ];
    }

    public function getInstances($token, $start = 0, $limit = 20)
    {
        $sql = $this->_cacheQuery[$token]." LIMIT {$start}, {$limit}";
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

        $sql = "SELECT a.`ID_` as id FROM `ACT_HI_PROCINST` as a left join `ACT_HI_VARINST` as b on a.`ID_`=b.`PROC_INST_ID_` WHERE a.`PROC_DEF_KEY_` = '{$key}' AND b.`NAME_` = '{$name}' AND b.`TEXT_`= '{$value}'";

        if ($order && $by) {
            $sql .= " ORDER BY a.`{$order}` {$by}";
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

        $sql = "SELECT `ID_` as id FROM `ACT_HI_PROCINST` WHERE `PROC_DEF_KEY_` = '{$key}' AND `ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `NAME_` = '{$statusName}' AND `TEXT_`= '{$statusValue}' AND `PROC_INST_ID_` IN (SELECT `PROC_INST_ID_` FROM `ACT_HI_VARINST` WHERE `NAME_` = '{$groupName}' AND `TEXT_`= '{$groupValue}'))";
        if ($order && $by) {
            $sql .= " ORDER BY {$order} {$by}";
        }

        return $sql;
    }
}
