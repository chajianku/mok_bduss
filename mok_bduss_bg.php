<?php
require '../../init.php';
global $i, $m;

function getMiddle($text, $left, $right)
{
    $loc1 = stripos($text, $left);
    if (is_bool($loc1)) {
        return "";
    }
    $loc1 += strlen($left);
    $loc2 = stripos($text, $right, $loc1);
    if (is_bool($loc2)) {
        return "";
    }

    return substr($text, $loc1, $loc2 - $loc1);
}

function curl_get($url, $header)
{
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    $data = curl_exec($curl);
    curl_close($curl);

    return $data;
}

function getId($bduss, $fast)
{
    global $m;
    $header[] = 'Content-Type:application/x-www-form-urlencoded; charset=UTF-8';
    $header[] = 'Cookie: BDUSS=' . $bduss;
    if ($fast) {
        $data = curl_get("http://tieba.baidu.com/dc/common/tbs", $header);
        $data = getMiddle($data, '"is_login":', '}');
        $ret = Array();
        $ret['valid'] = $data == '1' ? '1' : '0';//bduss是否有效
        $res = $m->query('Select name From ' . DB_PREFIX . 'baiduid Where bduss=\'' . $bduss . "'");
        if ($res->num_rows != 0) {//如果该bduss在数据库里有记录
            $row = $m->fetch_array($res);
            $ret['name'] = $row['name'];
        } else {
            $ret['name'] = $data == '1' ? '有效' : '无效';
        }
    } else {
        $data = curl_get("http://wapp.baidu.com/", $header);
        $name = urldecode(getMiddle($data, 'i?un=', '">'));
        $ret = Array();
        $ret['valid'] = $name == '' ? '0' : '1';
        if ($ret['valid']) {
            $ret['name'] = $name;
        } else {
            $res = $m->query('Select name From ' . DB_PREFIX . "baiduid Where bduss='" . $bduss . "'");
            if ($res->num_rows == 0) {//如果在数据库中没有该bduss的相关记录
                $ret['name'] = '无效';
            } else {
                $row = $m->fetch_array($res);
                $ret['name'] = $row['name'];
            }
        }
    }

    return json_encode($ret);
}

if (isset($_GET["do"])) {
    switch ($_GET["do"]) {
        case 'tr':
            if (isset($_GET["bduss"]) && isset($_GET["eq"])) {
                $id = json_decode(getId($_GET["bduss"], 0), true);
                $ary['valid'] = $id['valid'];
                $ary['name'] = $id['name'];
                $ary['eq'] = $_GET["eq"];
                echo json_encode($ary);
            }
            break;
        case 'table':
            if (isset($_GET["uid"]) && isset($_GET["fast"])) {
                //做个检测，防止恶意用户获取其他UID的BDUSS
                if ($i['user']['role'] != "admin") {
                    $_GET["uid"] = UID;
                }
                $q = $m->query("Select * from " . DB_PREFIX . "baiduid where uid=" . $_GET["uid"]);
                while ($row = $q->fetch_row()) {
                    $ary[$row[0]] = Array(getId($row[2], $_GET["fast"]), $row[2]);
                }
                if (isset($ary)) {
                    echo json_encode($ary);
                } else {
                    echo json_encode(Array("Empty" => "Empty"));
                }
            }
            break;
        case 'save':
            if (isset($_GET["id"]) && isset($_GET["bduss"])) {
                //做个检测，防止恶意用户修改其他ID的BDUSS
                $m->query("Select * From " . DB_PREFIX . "baiduid Where uid=" . UID . " and id=" . $_GET["id"]);
                //判断当前用户ID（UID）下是否有将要修改的这个账号ID，如果没有（就是说这id并没有绑定在这个UID下）并且当前用户不是管理员的话
                if ($m->affected_rows() == 0 && $i['user']['role'] != "admin") {
                    echo json_encode(Array("valid" => "0", "msg" => "请不要作死，这个账号不属于你"));
                    break;
                }
                $id = json_decode(getId($_GET["bduss"], $_GET["fast"]), true);
                if ($id['valid'] == '0') {
                    $id['msg'] = "该BDUSS无效！请检查后重新保存";
                } else {
                    if ($m->query('Update ' . DB_PREFIX . 'baiduid Set bduss="' . $_GET["bduss"] . '",name="' . $id['name'] . '" Where id=' . $_GET["id"]) === false) {
                        $id['valid'] = '0';
                        $id['msg'] = "数据库错误，保存失败";
                    } else {
                        doAction('baiduid_set');
                    }
                    echo json_encode($id);
                }
            }
            break;
        case 'del':
            if (isset($_GET["id"])) {
                //做个检测，防止恶意用户删除其他ID的账号
                $m->query("Select * From " . DB_PREFIX . "baiduid Where uid=" . UID . " and id=" . $_GET["id"]);
                //判断当前用户ID（UID）下是否有将要修改的这个账号ID，如果没有（就是说这id并没有绑定在这个UID下）并且当前用户不是管理员的话
                if ($m->affected_rows() == 0 && $i['user']['role'] != "admin") {
                    echo json_encode(Array("status" => "false", "msg" => "请不要作死，这个账号不属于你"));
                    break;
                }
                //查询该用户所在分表
                $res = $m->query('SELECT t FROM ' . DB_PREFIX . 'users,' . DB_PREFIX . 'baiduid WHERE ' . DB_PREFIX . 'baiduid.id=' . $_GET["id"]);
                if ($m->affected_rows() != 0) {
                    $res = $res->fetch_array();
                    if ($m->query('Delete From ' . DB_PREFIX . 'baiduid Where id=' . $_GET["id"]) &&
                        $m->query('Delete From ' . DB_PREFIX . $res['t'] . ' Where pid=' . $_GET["id"])
                    ) {
                        $ary["status"] = "true";
                    } else {
                        $ary["status"] = "false";
                        $ary["msg"] = "数据库错误，删除失败";
                    }
                } else {
                    $ary["status"] = "false";
                    $ary["msg"] = "数据库错误，无法获取分表信息";
                }
                echo json_encode($ary);
            }
            break;
        case 'delUser':
            if (isset($_GET["uid"])) {
                if ($i['user']['role'] != "admin") {
                    echo json_encode(Array("status" => "false", "msg" => "请不要作死，这个账号不属于你"));
                    break;
                }
                if ($m->query('Delete From ' . DB_PREFIX . 'users Where id=' . $_GET["uid"])) {
                    if (SYSTEM_VER >= 3.4) {
                        $m->query('Delete From ' . DB_PREFIX . 'users_options Where uid=' . $_GET["uid"]);
                    }
                    $ary["status"] = "true";
                } else {
                    $ary["status"] = "false";
                    $ary["msg"] = "数据库错误，删除失败";
                }
                echo json_encode($ary);
            }
            break;
        case 'mail':
            if (isset($_GET["id"])) {
                $ret = $m->once_fetch_array('Select email From ' . DB_PREFIX . 'users Where id=' . $_GET["id"]);
                if (isset($ret['email']) && $ret['email'] != '') {
                    $x = misc::mail($ret['email'], '你在云签中绑定的百度帐号过期了 - 来自BDUSS有效性检测插件', '你在' . SYSTEM_URL . '云签中绑定的百度帐号过期了，这将导致无法继续签到，请登录并重新绑定');
                    if ($x === true) {
                        echo json_encode(Array('status' => 'true'));
                    } else {
                        echo json_encode(Array('status' => 'false', 'msg' => '邮件发送失败，请检查邮件综合设置 '));
                    }
                } else {
                    echo json_encode(Array('status' => 'false', 'msg' => '没有找到该用户的E-mail'));
                }
            }
            break;
    }
}
?>