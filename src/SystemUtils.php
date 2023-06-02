<?php
/**
 * Created by PhpStorm.
 * User: Rus
 * Description: PhpWorkflow TODO: add description
 */

namespace Workflow;
use Exception;

trait SystemUtils {
    static private string $LOCK_DELIMITER='+';

    // Data cache for windows
    private static array $win_process_list=[];
    private static int $win_list_time=0;

    /**
     * @param $hostname
     * @param $pid
     * @param array $active_hosts
     *
     * @return bool
     */
    public static function process_exists($hostname, $pid, array $active_hosts = []): bool {

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

    private static function linux_process_exists($pid): bool {
        clearstatcache();
        return file_exists( "/proc/$pid" );
    }

    private static function windows_process_exists($pid): bool {

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


    /**
     * @param string $host
     * @param int $pid
     * @return string
     *
     * @throws Exception
     */
    protected function get_lock_string(string $host='', int $pid=0): string {
        $host=$host ?: gethostname();
        $pid=$pid ?: getmypid();
        $rnd=random_int(0, mt_getrandmax());
        return implode(self::$LOCK_DELIMITER, [$host,$pid,$rnd]);
    }

    protected function get_host_pid_from_lock_string($lock): array {
        $lockArr=explode(self::$LOCK_DELIMITER, $lock);

        return count($lockArr) < 2
            ? ['', '']
            : array_splice($lockArr, 0, 2);
    }

}