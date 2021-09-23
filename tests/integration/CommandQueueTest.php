<?php
namespace Workflow\Integration;

use PHPUnit\Framework\TestCase;
use Workflow\Node\INode;
use Workflow\Example\CommandsQueue;

class CommandsQueueTest extends TestCase {

    function test_simple() {
        $wf=new CommandsQueue();
        $wf->run();
        self::assertEquals('goto_action3', $wf->get_current_node_name());
        $wf->set_exec_time(0);
        $wf->run();
        self::assertEquals('goto_action2', $wf->get_current_node_name());
        $wf->set_exec_time(0);
        $wf->run();
        self::assertEquals(INode::LAST_NODE, $wf->get_current_node_name());
        $wf->run();
        self::assertEquals(INode::LAST_NODE, $wf->get_current_node_name());
    }

}
