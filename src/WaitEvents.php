<?php
/**
 * Created by PhpStorm.
 * User: Rus
 * Description: PhpWorkflow TODO: add description
 */

namespace Workflow;

trait WaitEvents {
    /**
     * @param string $event_type
     */
    public function start_wait_for($event_type) {
        if (!in_array($event_type, $this->wait_for)) {
            $this->wait_for[] = $event_type;
        }
    }


    /**
     * @param $event_type
     */
    public function stop_wait_for($event_type) {

        $p = array_search($event_type, $this->wait_for);
        if ($p !== false) {
            array_splice($this->wait_for, $p, 1);
        }
    }


    /**
     * Add, remove and check events
     * which process is waiting for
     *
     * @param $event_type
     *
     * @return bool
     */
    public function is_waiting_for($event_type) {
        return in_array($event_type, $this->wait_for);
    }

}