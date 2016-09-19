<?php

namespace App\Http\Controllers\web;

use Illuminate\Http\Request;
use App\Models\Worktime;
use App\Http\Requests;
use App\Http\Controllers\Controller;

class PushController extends Controller
{

    /**
     * ios Message push
     * 查询需要发送的客户端
     */
    public function iosMessage()
    {
        $end = date('H:i:s', time());//当前时间
        $date = date('Y-m-d', time());
        $worktimeId = Worktime::where('end', '<=', $end)->value('id');
        if (isset($worktimeId)) {
            $departmentId = Department::where('worktime_id', $worktimeId)->lists('id');
            $employees = Staff::whereIn('department_id', $departmentId)->get();
            $tokens = array();
            $emp = array();
            foreach ($employees as $employee) {
                $workend = date('H:i:s', strtotime(Worktime::findByStart($employee->code, 'end')));
                $overtime = Daily::where('employee_id', $employee->id)->where('date', date('Y-m-d', time()))->value('over_time');
                $token = Beacon::where('employee_id', $employee->id)->where('timestamp', 'like', $date . '%')->value('token');
                $overtimes = $overtime - round((strtotime(date('H:i:s', time())) - strtotime($workend)) % 86400 / 3600, 2);
                if (!isset($overtime) || $overtimes > 0) {
                    $emp['token'] = $token;
                }
                $tokens[] = $emp;
            }
            $this->iosOfftime($tokens);
        }
        return $this->json();
    }

    /**
     * ios Off time
     * $tokens 需要发送的ios客户端
     * $passphrase 密钥
     * ck.pem
     */
    private function iosOfftime($tokens)
    {
        foreach ($tokens as $tokeno) {
            $message = '[' . $tokeno['code'] . ']' . $tokeno['name'] . ' 该下班了！'; //X160919 张三 该下班了！
            if (isset($tokeno['token']) && !isset($tokeno['beacon_down_time'])) {    //只有下班的员工可以收到消息
                $passphrase = '111111';     //密钥
                $ctx = stream_context_create();
                stream_context_set_option($ctx, 'ssl', 'local_cert', base_path() . '/ck.pem');
                stream_context_set_option($ctx, 'ssl', 'passphrase', $passphrase);
                $fp = stream_socket_client('ssl://gateway.sandbox.push.apple.com:2195', $err, $errstr, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);
                if (!$fp) {
                    print "Failed to connect $err $errstr\n";
                    return;
                }
                $body['aps'] = array(
                    'alert' => $message,
                    'sound' => 'default'
                );
                $payload = json_encode($body);
                $msg = chr(0) . pack('n', 32) . pack('H*', $tokeno['token']) . pack('n', strlen($payload)) . $payload;
                $result1 = fwrite($fp, $msg, strlen($msg));
                if ($result1) {
                    echo 'Success ' . $tokeno['code'] . PHP_EOL;
                }
                fclose($fp);
            }
        }
    }
}
