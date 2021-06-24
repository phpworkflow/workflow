<?php
namespace Workflow\Example;

use Workflow\Workflow;

class EventListener extends Workflow {
    const LISTENER_EVENT = 'LISTENER_EVENT';

    function __construct() {
        $process_nodes = [
            ["action1"],
            ["wait_1", "timeout" => 100],
            ["goto_action1"],
            ["event_handler"],
            ["goto_wait_1"],
            ["end"]
        ];

        $events_map = [
            self::LISTENER_EVENT => [
                self::EVENT_ON => true,
                self::EVENT_TARGET => "event_handler",
                self::EVENT_FILTER => ["listener_id"]
            ]
        ];

        parent::__construct($process_nodes, $events_map);
    }

    public function event_handler() {
        if(!$this->last_event) {
            return;
        }
        $ec = $this->get_context('eventCount') ?: 0;
        $ec++;
        $this->set_context('eventCount', $ec);
        $this->logger->info("Event: ".$this->last_event->get_id(). "Total: $ec events arrived");
    }

    public function action1() {
        $this->logger->info("ACTION1");
    }
}
