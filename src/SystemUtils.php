<?php
/**
 * Created by PhpStorm.
 * User: Rus
 * Description: PhpWorkflow TODO: add description
 */

namespace Workflow;


trait SystemUtils {
    static private $LOCK_DELIMITER='+';

    // Data cache for windows
    private static $win_process_list=[];
    private static $win_list_time=0;

    /**
     * @param $hostname
     * @param $pid
     * @param array $active_hosts
     *
     * @return bool
     */
    public static function process_exists($hostname, $pid, $active_hosts = []) {

        if(!in_array($hostname, $active_hosts, true)) {
            return false;
        }

        // Check processes only on current host
        if ($pid > 0 && $hostname != gethostname()) {
            return true;
        }

        if (strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN') {
            return self::windows_process_exists($pid);
        }

        return self::linux_process_exists($pid);
    }

    private static function linux_process_exists($pid) {
        clearstatcache();
        return file_exists( "/proc/$pid" );
    }

    private static function windows_process_exists($pid) {

        $task_list=[];
        if(time() > self::$win_list_time) {
            exec("tasklist 2>NUL", $task_list);
        }

        if(!empty($task_list)) {

            self::$win_list_time = time();
            self::$win_process_list = [];

            foreach ($task_list as $task) {
                if (preg_match('/.+?\s+(\d+)\s+/', $task, $match)) {
                    self::$win_process_list[] = $match[1];
                }
            }
        }

        return in_array($pid, self::$win_process_list);
    }


    protected function get_lock_string($host='', $pid=0) {
        $host=$host ?: gethostname();
        $pid=$pid ?: getmypid();
        $rnd=mt_rand();
        return implode(self::$LOCK_DELIMITER, [$host,$pid,$rnd]);
    }

    protected function get_host_pid_from_lock_string($lock) {
        $lockArr=explode(self::$LOCK_DELIMITER, $lock);

        return count($lockArr) < 2
            ? ['', '']
            : array_splice($lockArr, 0, 2);
    }

}