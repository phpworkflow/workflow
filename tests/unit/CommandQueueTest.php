<?php
namespace Workflow;

use PHPUnit\Framework\TestCase;
use Workflow\Node\INode;
use Workflow\Example\CommandsQueue;

class CommandQueueTest extends TestCase {

    function test_simple(): void {
        $wf=new CommandsQueue();
        $wf->run();
        self::assertEquals('goto_action3', $wf->get_current_node_name());
        $wf->set_exec_time();
        $wf->run();
        self::assertEquals('goto_action2', $wf->get_current_node_name());
        $wf->set_exec_time();
        $wf->run();
        self::assertEquals(INode::LAST_NODE, $wf->get_current_node_name());
        $wf->run();
        self::assertEquals(INode::LAST_NODE, $wf->get_current_node_name());
    }

}
