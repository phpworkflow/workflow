<?php

namespace Workflow;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExceptionWorkflow extends Workflow {
    function __construct()
    {
        $process_nodes = [
            ["action1"],
            ["end"]
        ];
        parent::__construct($process_nodes);
    }

    public function action1() {
        throw new RuntimeException();
    }
}


class WorkflowExceptionTest extends  TestCase
{
    public function testWaitToday()
    {
        $workflow = new ExceptionWorkflow();
        self::assertEmpty($workflow->get_error_info());
        $workflow->run();

        self::assertNotEmpty($workflow->get_error_info());
        self::assertGreaterThan($workflow::PAUSE_AFTER_EXCEPTION - 2, $workflow->get_start_time()-time());
    }
}