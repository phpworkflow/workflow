<?php

namespace Workflow;
use Exception;
use PHPUnit\Framework\TestCase;
use Workflow\Node\INode;
use Workflow\Example\GoodsSaleWorkflow;

class SuccessFlowTest extends TestCase {
    public const CUSTOMER_ID = 25;

    public const ORDER_ID = 592;

    protected Workflow $workflow;

    public function setUp(): void {
        $this->workflow=new GoodsSaleWorkflow();
        $this->workflow->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER, self::CUSTOMER_ID);
    }

    /**
     * @return void
     * @throws Exception
     */
    function test_success(): void {
        $wf=$this->workflow;
        $wf->run();
        self::assertEquals('goto_select_goods', $wf->get_current_node_name());

        $event=new Event(GoodsSaleWorkflow::EVENT_GOODS_SELECTED,[
            $wf::WF_KEY_CUSTOMER => self::CUSTOMER_ID,
            $wf::WF_KEY_ORDER => self::ORDER_ID
        ]);

        $wf->run([$event]);
        $node_name=$wf->get_current_node_name();
        self::assertContains($node_name, ['goto_if_customer_pay_for_goods', 'report_no_goods']);

        if($wf->get_current_node_name() === 'report_no_goods') {
            $event=new Event(GoodsSaleWorkflow::EVENT_SUPPLIER_SENT_GOODS);
            $wf->run([$event]);
        }

        self::assertEquals('goto_if_customer_pay_for_goods', $wf->get_current_node_name());
        self::assertEmpty( $wf->get_value(GoodsSaleWorkflow::CTX_SOME_EVENT));

        $wf->set_exec_time();
        $wf->run();
        $event=new Event(GoodsSaleWorkflow::EVENT_CONTEXT_MODIFIER, [GoodsSaleWorkflow::CONTEXT_VALUE_NAME => 135]);
        $wf->run([$event]);
        self::assertEquals(135, $wf->get_value(GoodsSaleWorkflow::CTX_SOME_EVENT));

        $wf->run();
        self::assertEquals('goto_if_customer_pay_for_goods', $wf->get_current_node_name());

        $event=new Event(GoodsSaleWorkflow::EVENT_CUSTOMER_PAY_FOR_GOODS);
        $wf->run([$event]);

        self::assertEquals(INode::LAST_NODE, $wf->get_current_node_name());
    }

}
