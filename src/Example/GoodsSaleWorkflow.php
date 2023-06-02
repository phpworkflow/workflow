<?php

namespace Workflow\Example;

use Workflow\Event;
use Workflow\Workflow;
use Workflow\Logger\ILogger;
use Exception;

class GoodsSaleWorkflow extends Workflow
{

    public const NUM_CYCLES = 9;
    public const EXEC_TIMEOUT = 1;
    public const CONTEXT_VALUE_NAME = 'test_execution_time';
    public const CTX_SOME_EVENT = 'some_event';

    // Workflow events
    public const EVENT_GOODS_SELECTED = 'GOODS_SELECTED';
    public const EVENT_SUPPLIER_SENT_GOODS = 'SUPPLIER_SENT_GOODS';
    public const EVENT_CUSTOMER_PAY_FOR_GOODS = 'CUSTOMER_PAY_FOR_GOODS';
    public const EVENT_CONTEXT_MODIFIER = 'CONTEXT_MODIFIER';

    public const WF_KEY_CUSTOMER = 'customer_id';

    public const WF_KEY_ORDER = 'order_id';

    public function __construct()
    {
        $process_nodes = [
            ["select_goods"],
            ["wait_for_event1", "timeout" => 2],
            ["goto_select_goods"],
            ["checkout"],
            ["if_selected_goods_on_stock",
                "then" => [
                    ["make_order"],
                ],
                "else" => [
                    ["send_request_to_supplier"],
                    ["wait_for_event2", "timeout" => 7], // If EVENT_SUPPLIER_SENT_GOODS arrived we go to make_order
                    ["report_no_goods"],
                    ["goto_show_result"],
                ]
            ],
            ["create_bill"],
            ["!if_customer_pay_for_goods",
                "then" => [
                    ["wait_for_payment", "timeout" => 2],
                    ["goto_if_customer_pay_for_goods", "counter" => 2],
                    ["report_no_payment"],
                    ["goto_show_result"],
                ],
                "else" => [
                    ["goto_send_goods"]
                ]
            ],
            ["send_goods"],
            ["show_result"],
            ["end"]
        ];

        $events_map = [
            self::EVENT_GOODS_SELECTED => [
                self::EVENT_ON => true,
                self::EVENT_TARGET => "checkout",
                self::EVENT_FILTER => ['customer_id']
            ],
            self::EVENT_SUPPLIER_SENT_GOODS => [
                self::EVENT_ON => false,
                self::EVENT_TARGET => "make_order",
                self::EVENT_FILTER => []
            ],
            self::EVENT_CUSTOMER_PAY_FOR_GOODS => [
                self::EVENT_ON => true,
                self::EVENT_TARGET => "send_goods"],
            self::EVENT_CONTEXT_MODIFIER => [
                self::EVENT_ON => true,
                self::EVENT_TARGET => function(Event $evt): void {
                    $this->set_context(self::CTX_SOME_EVENT, $evt->get_data(self::CONTEXT_VALUE_NAME));
                } ]
        ];

        parent::__construct($process_nodes, $events_map, [self::WF_KEY_CUSTOMER]);
        $this->logger->set_log_channel(ILogger::LOG_CONSOLE);
        $this->logger->set_log_level(ILogger::DEBUG);
    }

// This methods should be implemented by programmer BEGIN
    public function select_goods(): void
    {
        // Do something....
        sleep(1);
    }

    public function checkout(): void
    {
        // Do checkout action
        sleep(1);
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function if_selected_goods_on_stock(): bool
    {
        // Some logic that check the depot
        $rnd = random_int(0, 3);
        return ($rnd === 0);
    }

    public function make_order(): void
    {
        $this->stop_wait_for(self::EVENT_SUPPLIER_SENT_GOODS);
        // Make order and send it to some department
        sleep(1);
    }

    public function send_request_to_supplier(): void
    {
        // Send request to supplier
        error_log("This is request to supplier\n");
        $this->start_wait_for(self::EVENT_SUPPLIER_SENT_GOODS);
    }

    public function report_no_goods(): void
    {
        error_log("NO GOODS!!! Change the supplier!\n");
    }

    public function create_bill(): void
    {
        // Create bill and send it to customer
        error_log("Bill was sent to customer!\n");
    }

    public function send_goods(): void
    {
        // Create bill and send it to customer
        error_log("CUSTOMER GOT GOODS\n");
        $this->set_context("order_successful", "order_ok");
    }

    public function show_result(): void
    {
        if ($this->get_context("order_successful")) {
            error_log("THIS IS SUCCESSFUL FLOW!\n");
        } else {
            error_log("EPIC FAIL :-(\n");
        }

    }

    public function report_no_payment(): void
    {
        error_log("NO PAYMENT! Bad customer\n");
    }

    public function if_customer_pay_for_goods()
    {
        $payment = $this->get_context("customer_pay_for_goods");
        return !empty($payment);
    }

// This methods should be implemented by programmer BEGIN

    public function get_supported_business_objects()
    {
        return [];
    }

    public function get_value($key) {
        return $this->get_context($key);
    }
}
