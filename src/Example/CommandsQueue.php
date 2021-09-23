<?php
namespace Workflow\Example;

use Workflow\Workflow;
use Workflow\Logger\ILogger;

class CommandsQueue extends Workflow {

    function __construct() {
        $process_nodes = [
            ["action1"],
            ["wait_1", "timeout" => 1],
            ["goto_action3"],
            ["action2"],
            ["goto_end"],
            ["action3"],
            ["wait_2", "timeout" => 1],
            ["goto_action2"],
            ["end"]
        ];

        parent::__construct($process_nodes);
        $this->logger->set_log_channel(ILogger::LOG_STDOUT);
    }

// This methods should be implemented by programmer BEGIN

    public function end() {
        error_log("WF Finished");
    }

    public function action1() {
        error_log('Method: '.__METHOD__);
    }

    public function action2() {
        error_log('Method: '.__METHOD__);
    }

    public function action3() {
        error_log('Method: '.__METHOD__);
    }

// This methods should be implemented by programmer BEGIN
    public function get_supported_business_objects() {
        return [];
    }
}
