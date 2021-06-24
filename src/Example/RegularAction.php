<?php
namespace Workflow\Example;

use Workflow\Workflow;

class RegularAction extends Workflow {
    const TEST_EVENT = 'TEST_EVENT';

    function __construct() {
        $process_nodes = [
            ["action1"],
            ["goto_action1", "counter" => 10],
            ["end"]
        ];

        $events_map = [
            self::TEST_EVENT => [
                self::EVENT_ON => true,
                self::EVENT_TARGET => "action1",
                self::EVENT_FILTER => []
            ]
        ];

        parent::__construct($process_nodes, $events_map);
    }

    public function action1() {
        $cnt = $this->get_context('cnt') ?: 0;
        $cnt++;

        $this->logger->info("START ACTION1: $cnt");
        sleep(10);
        $this->set_exec_time(time()+10);
        $this->set_context('cnt',$cnt);
        $this->logger->info("FINISH ACTION1: $cnt");
    }
}
