<?php
namespace Workflow\Example;

use Workflow\Workflow;
use Exception;

class ExceptionWorkflow extends Workflow {

    public const EXECEPTION_ACTION = 'exeception_action';

    public function __construct() {
        $process_nodes = [
            ["action1"],
            [self::EXECEPTION_ACTION],
            ["wait_to_goto", "timeout" => 2],
            ["goto_action1", "counter" => 7],
            ["end"]
        ];

        parent::__construct($process_nodes);
    }

    public function action1() {
        $cnt = $this->get_context('cnt') ?: 0;
        $cnt++;
        $this->set_context('cnt',$cnt);
        $this->sync();
        $this->logger->info("FINISH ACTION1: $cnt");
    }

    public function exeception_action() {
        $num = $this->get_context('ex_counter') ?: 0;
        $num++;
        $this->set_context('ex_counter',$num);
        $this->sync();

        if($num % 2 === 1) {
            $this->logger->info("ex_counter $num, exception");
            throw new Exception("Some error");
        }
    }
}
