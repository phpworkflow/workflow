<?php

namespace Workflow\Node;

use PHPUnit\Framework\TestCase;
use Workflow\Workflow;
use DateInterval;
use DateTime;

class WaitWorkflow extends Workflow {
    function __construct($startTime)
    {
        $process_nodes = [
            ["wait_until_time", "time" => $startTime],
            ["action1"],
            ["end"]
        ];
        parent::__construct($process_nodes, []);
    }

    protected function action1() {
    }
}

// Due to caching we need another class name
class WaitWorkflow2 extends WaitWorkflow {
}

class WaitTest extends  TestCase
{
    public function testWaitToday()
    {
        $startTime=(new DateTime());
        $startTime->add(new DateInterval('PT5M'));
        $startTime->setTime(
            $startTime->format('H'),
            $startTime->format('i'),
            0);

        $timeParam = $startTime->format("H:i");
        $workflow = new WaitWorkflow($timeParam);
        $workflow->run();

        self::assertEquals("action1", $workflow->get_current_node_name());
        self::assertTrue(abs($startTime->getTimestamp() - $workflow->get_start_time()) <= 1);
    }

    public function testWaitTomorrow()
    {
        $startTime=(new DateTime());

        $startTime->sub(new DateInterval('PT5M'));
        $startTime->setTime(
            $startTime->format('H'),
            $startTime->format('i'),
            0);

        $workflow = new WaitWorkflow2( $startTime->format("H:i"));
        $workflow->run();

        $startTime->add(new DateInterval('P1D'));

        self::assertEquals("action1", $workflow->get_current_node_name());
        $t=new DateTime();
        $t->setTimestamp($workflow->get_start_time());
        self::assertTrue(abs($startTime->getTimestamp() - $workflow->get_start_time()) <= 1);
    }
}