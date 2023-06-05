<?php

namespace Workflow;
use Exception;
use JsonException;
use LogicException;

use Workflow\Node\Base;
use Workflow\Node\Validator;
use Workflow\Node\INode;
use Workflow\Storage\IStorage;
use Workflow\Logger\WorkflowLogger;
use Workflow\Logger\ILogger;

abstract class Workflow
{
//    extends Process {
    use WaitEvents;

    public const CONTEXT_CALL_STACK = 'call_stack';
    public const CONTEXT_CURRENT_NODE = 'node';
    public const CONTEXT_CURRENT_NODE_NAME = 'node_name';
    public const CONTEXT_START_TIME = 'start_time';
    public const CONTEXT_WAIT_FOR = 'wait_for';

    public const EVENT_ON = "event_on";
    public const EVENT_TARGET = "event_target";
    public const EVENT_FILTER = "event_filter";

    // Endless cycles protection
    public const MAX_ITERATIONS = 150;

    public const DEFAULT_ERROR_LIMIT = 3;

    // Workflow pause after exception, seconds
    public const PAUSE_AFTER_EXCEPTION = 60;

    protected int $workflow_id = 0;

    /* @var WorkflowLogger $logger */
    protected ILogger $logger;

    /* @var Context $context */
    protected Context $workflow_context;
    protected bool $is_subscription_updated = false;

    protected array $call_stack = [];    // Current execution node
    protected int $current_node;    // Current execution node
    protected int $start_time = 0;  // Time of the next command execution
    protected $wait_for = [];

    protected ?Event $last_event = null;

    /** @var callable $sync_callback */
    private $sync_callback = null;

    protected array $events_map;

    static protected array $compiled_nodes = [];
    static protected array $nodes_map = [];

    protected int $error_limit = self::DEFAULT_ERROR_LIMIT;   // Maximum number of errors for workflow

    /**
     * @var string
     */
    protected string $error_info = '';         // Used for debugging and error handling

    protected array $unique_properties;

    /**
     * Basic initialization. parent::__construct should be executed in subclasses constructor
     *
     * @param array $process_nodes
     * @param array $events_map
     * @param array $unique_properties
     * @throws Exception
     */
    public function __construct(array $process_nodes = [], array $events_map = [], array $unique_properties = [])
    {

        $class = get_class($this);

        if (!isset(self::$compiled_nodes[$class])) {
            $validator = new Validator($process_nodes, $this);
            self::$compiled_nodes[$class] = $validator->get_prepared_nodes();
            static::prepare_nodes_map($class);
        }

        $this->current_node = array_values(self::$compiled_nodes[$class])[0]->get_id();
        $this->workflow_context = new Context();

        // Set the default state. It can be overwritten by context from storage
        foreach ($events_map as $event_type => $params) {
            if ($params[self::EVENT_ON]) {
                $this->start_wait_for($event_type);
            }
        }
        $this->events_map = $events_map;
        $this->unique_properties = $unique_properties;
        $this->logger = WorkflowLogger::create($this->workflow_id);
    }

    static private function prepare_nodes_map($class): void
    {
        $map = [];
        /* @var INode $node */
        foreach (self::$compiled_nodes[$class] as $node) {
            $map[$node->get_name()] = $node->get_id();
        }
        self::$nodes_map[$class] = $map;
    }

    public function get_logger(): ILogger
    {
        return $this->logger;
    }

    public function get_id(): int
    {
        return $this->workflow_id;
    }

    /**
     * @return array
     * @throws JsonException
     */
    public function get_uniqueness(): array {
        if(empty($this->unique_properties)) {
            throw new LogicException('Please specify $unique_properties parameter for workflow');
        }

        $keys = [];

        foreach ($this->unique_properties as $property) {
            $value = $this->get_context($property);
            if(!is_scalar($value)) {
		        throw new LogicException("Content of $property should be scalar value. Property type is ".gettype($value));
            }
            $keys[$property] = $value;
        }

        ksort($keys);

        return [
            json_encode(array_keys($keys), JSON_THROW_ON_ERROR),
            json_encode(array_values($keys), JSON_THROW_ON_ERROR)
        ];
    }

    public function set_id($workflowId): void
    {
        $this->workflow_id = $workflowId;
        $this->logger->setWorkflowId($workflowId);
    }

    /**
     *
     * @param int $error_count
     * @return bool
     */
    public function many_errors(int $error_count): bool
    {
        if ($this->error_limit === 0) {
            return false;
        }

        return $this->error_limit < $error_count;
    }

    /**
     * Set the process state
     * @param string $serialized_state
     * @throws Exception
     */
    public function set_state(string $serialized_state): void
    {
        $this->workflow_context->unserialize($serialized_state);
        // Counters are inside workflow_context, we don't need to assign them to something
        // TODO replace member variables with getters and remove members
        $this->start_time = $this->workflow_context->get(self::CONTEXT_START_TIME) ?: 0;
        $this->current_node = $this->workflow_context->get(self::CONTEXT_CURRENT_NODE);
        $this->call_stack = $this->workflow_context->get(self::CONTEXT_CALL_STACK);
        $this->wait_for = $this->workflow_context->get(self::CONTEXT_WAIT_FOR);
    }

    /** Returns the serialized context of the workflow
     * @return string
     * @throws JsonException
     */
    public function get_state():string
    {
        $this->workflow_context
            ->set(self::CONTEXT_START_TIME, $this->start_time)
            ->set(self::CONTEXT_CURRENT_NODE, $this->current_node)
            ->set(self::CONTEXT_CURRENT_NODE_NAME, $this->get_current_node_name())
            ->set(self::CONTEXT_CALL_STACK, $this->call_stack)
            ->set(self::CONTEXT_WAIT_FOR, $this->wait_for);

        return $this->workflow_context->serialize();
    }


    protected function get_context($key)
    {
        return $this->workflow_context->get($key, Context::NAMESPACE_USER);
    }

    public function set_context($key, $value): void
    {
        $this->workflow_context->set($key, $value, Context::NAMESPACE_USER);

        foreach ($this->events_map as $event_type => $params) {
            if (!isset($params[self::EVENT_FILTER])) {
                continue;
            }

            foreach ($params[self::EVENT_FILTER] as $filter_key) {
                // We need to update subscription in case key data was set
                if ($filter_key === $key) {
                    $this->is_subscription_updated = true;
                    return;
                }
            }
        }
    }


    /**
     * Returns array of Subscription objects or empty array in case subscription parameters
     * was not changed
     * @param $no_update_check boolean - no check of subscription update
     * @return array $result
     */
    public function get_subscription(bool $no_update_check = false): array
    {

        $result = [];

        if (!($this->is_subscription_updated || $no_update_check)) {
            return $result; // Subscription was not changed
        }

        foreach ($this->events_map as $event_type => $params) {
            $s = new Subscription($event_type);

            if (empty($params[self::EVENT_FILTER])) {
                $result[] = $s;
                continue;
            }

            foreach ($params[self::EVENT_FILTER] as $filter_key) {
                $s->context_value = $this->get_context($filter_key);
                // If we don't have concrete value for filter we skip this subscription
                if (empty($s->context_value)) {
                    continue;
                }
                $s->context_key = $filter_key;
                $result[] = $s;
            }
        }

        return $result;
    }


    public function get_counter($counter_name)
    {
        return $this->workflow_context->get($counter_name, Context::NAMESPACE_COUNTER);
    }

    public function set_counter($counter_name, $value): void
    {
        $this->workflow_context->set($counter_name, $value, Context::NAMESPACE_COUNTER);
    }

    /**
     * Process incoming events
     *
     * @param Event $event
     * @return Event
     * @throws Exception
     */
    private function handle_event(Event $event): ?Event
    {
        $event_type = $event->get_type();
        $event_id = $event->get_id();
        $this->logger->debug("Event($event_id) $event_type arrived.");

        if (!isset($this->events_map[$event_type])) {
            $this->logger->debug(" !!! Event '$event_type' not presents in event map.");
            return null;
        }

        if (!$this->is_waiting_for($event->get_type())) {
            $this->logger->debug(" !!! Event $event_type skipped. Workflow is not waiting for it.");
            return null;
        }

        $this->set_exec_time();

        $event_target = $this->events_map[$event_type][self::EVENT_TARGET];
        if (is_callable($event_target)) {
            call_user_func($event_target, $event);
        } else {
            $this->goto_node($event_target);
        }

        return $event;
    }

    /**
     * @return void
     * @throws Exception
     */
    private function _run(): void
    {
        /* @var INode $current_node */
        // $next_node_id=INode::LAST_NODE;
        do {
            $current_node = $this->get_current_node();

            if ($current_node === null) {
                $this->logger->debug("Workflow finished");
                return;
            }

            // $this->logger->debug(print_r($current_node,true));

            $time_to_run = $this->start_time - $this->now();

            if ($time_to_run > 0) {
                $this->logger->debug("$time_to_run seconds left for execution of " . $current_node->get_name());
                return;
            }

            $this->logger->debug("--- " . get_class($current_node) . "($current_node): " . $current_node->get_name());

            $next_node_id = $current_node->execute($this);

            $this->set_current_node($next_node_id);

        } while (!(empty($next_node_id) || $this->is_finished()));
    }

    /**
     * @param $node_id
     * @return void
     * @throws Exception
     */
    private function set_current_node($node_id)
    {
        // TODO remove this after tests
        if (!is_numeric($node_id)) {
            throw new Exception("Node ID $node_id is wrong should be number.");
        }
        $this->current_node = $node_id;
    }

    /**
     * @param $node_name
     * @return int
     * @throws Exception
     */
    public function get_node_id_by_name($node_name): int
    {
        if (isset(self::$nodes_map[get_class($this)][$node_name])) {
            return self::$nodes_map[get_class($this)][$node_name];
        }

        throw new Exception("Node $node_name does not exists");
    }

    /**
     * @return INode|null
     * @throws Exception
     */
    private function get_current_node(): ?INode
    {
        if (isset(self::$compiled_nodes[get_class($this)][$this->current_node])) {
            return self::$compiled_nodes[get_class($this)][$this->current_node];
        }

        if ($this->current_node === INode::LAST_NODE) {
            return null;
        }

        throw new Exception("Node $this->current_node does not exists");
    }


    /**
     * @return int
     */
    public function now(): int
    {
        return time();
    }

    /**
     * @param Event[] $events array of Event objects
     * @return bool
     */
    public function run(array $events = []): bool
    {
        $this->error_info = '';

        if (!$this->on_start()) {
            $this->logger->info("on_start returns false. Skip workflow execution.");
            return true;
        }

        $iteration_counter = 0;

        try {
            do {
                $event_arrived = false;

                if (count($events) > 0) {
                    $this->last_event = $this->handle_event(array_shift($events));
                    $event_arrived = true;
                    $iteration_counter=0;
                }

                // Endless cycles protection
                if (++$iteration_counter > self::MAX_ITERATIONS) {
                    throw new Exception("Exceeded the maximum number of iterations (" . self::MAX_ITERATIONS . ")");
                }

                $this->_run();

                $this->sync();

                $this->last_event = null;

            } while ($this->current_node != INode::LAST_NODE && $event_arrived);

            $this->on_finish();
            return true;
        } catch (Exception $e) {
            $this->logger->warn("run Exception: " . $e->getMessage());
            $this->logger->warn($e->getTraceAsString());
            $this->error_info = "Exception: " . $e->getMessage();

            $this->set_exec_time(time() + $this->get_pause_after_exception());

            return false;
        }
    }


    /**
     * Switch current process node
     * @param $node_name
     * @return bool
     *
     * @throws Exception
     */
    public function goto_node($node_name): bool
    {

        if ($this->node_exists($node_name)) {
            $node_id = $this->get_node_id_by_name($node_name);
            $this->logger->debug("Switch to $node_name (id: $node_id) by GOTO_NODE call.");
            /** @var Base $current_node */
            $current_node = $this->get_current_node();
            if ($current_node === null) {
                $this->logger->debug("Workflow finished");
                return false;
            }
            $current_node->set_node_id_to_go($node_id);
            // TODO check if we need line below
            $this->current_node = $node_id;
            return true;
        }
        return false;
    }

    /**
     * Check the existence of the node
     *
     * @param $node_name
     *
     * @return bool
     */
    public function node_exists($node_name): bool
    {
        return isset(self::$nodes_map[get_class($this)][$node_name]);
    }

    /**
     * Return current node for execution, will be used for testing proposal
     * @return string
     */
    public function get_current_node_name(): string
    {
        return self::$compiled_nodes[get_class($this)][$this->current_node]->get_name();
    }

    /**
     * Return current node for execution, will be used for testing proposal
     * @param int $node_id
     * @return string
     */
    public function get_node_name_by_id(int $node_id): string
    {
        return self::$compiled_nodes[get_class($this)][$node_id]->get_name();
    }


    /**
     * Return current node for execution, will be used for testing proposal
     * @return int
     */
    public function get_current_node_id(): int
    {
        return $this->current_node;
    }


    /**
     * Return current process status
     * @return boolean TRUE - process finished
     */
    public function is_finished(): bool
    {
        return ($this->current_node == INode::LAST_NODE);
    }

    /**
     * Force finish workflow
     */
    public function finish(): void
    {
        $this->current_node = INode::LAST_NODE;
    }

    /**
     * Reset wait timer, process can flow
     * @param mixed $time
     */
    public function set_exec_time($time = 0): void
    {
        $type = gettype($time);
        switch ($type) {
            case 'string':
            case 'integer':
            {
                $this->start_time = (int)$time;
                break;
            }
            case 'object':
            {
                if (method_exists($time, 'getTimestamp')) {
                    $this->start_time = (int)$time->getTimestamp();
                    break;
                }
            }
            default:
            {
                $this->logger->warn("Argument type $type for __METHOD__ not supported");
            }
        }
    }

    /**
     * @return int
     */
    public function get_start_time(): int
    {
        return $this->start_time;
    }

    public function get_type(): string
    {
        return get_class($this);
    }

    /**
     * Allow to assign function to save workflow and event state to storage
     * @param $sync_callback
     */
    public function set_sync_callback($sync_callback): void
    {
        $this->sync_callback = $sync_callback;
    }

    /**
     * Save state of the workflow after processing of the event
     */
    protected function sync()
    {
        if (!is_callable($this->sync_callback)) {
            return;
        }

        call_user_func($this->sync_callback, $this, $this->last_event);
    }

    /**
     * Put process to execution queue
     */
    public function put_to_storage(IStorage $storage, $unique = false): int
    {
        return $storage->create_workflow($this, $unique)
            ? $this->workflow_id
            : 0;
    }

    /**
     * @param int $node_id
     */
    public function add_to_call_stack(int $node_id): void
    {
        $this->call_stack[] = $node_id;
    }

    /**
     * Return workflow execution to the previous saved node
     * @throws Exception
     */
    public function return_to_saved_node()
    {
        if (count($this->call_stack) == 0) {
            throw new Exception("Can't return the call stack is empty");
        }
        $saved_node = array_pop($this->call_stack);
        $this->logger->debug("Return from procedure to " . $this->get_node_name_by_id((int)$saved_node));
        $this->set_current_node($saved_node);
    }

    /*
     * on_start is executed before execution of main flow
     * it can be used for initialization of some workflow objects
     * @return boolean $result - the workflow is executed in case on_start returns TRUE
     */
    public function on_start(): bool
    {
        return true;
    }

    /*
     * Is executed after workflow main flow
     */
    public function on_finish(): void
    {
    }

    /**
     * @return int
     */
    protected function get_pause_after_exception(): int
    {
        return self::PAUSE_AFTER_EXCEPTION;
    }

    /**
     * @return bool
     */
    public function is_error(): bool
    {
        return $this->error_info !== '';
    }

    /**
     * @return string
     */
    public function get_error_info(): string
    {
        return $this->error_info;
    }

    /**
     * @param $logChannel
     * TODO camelCase, type
     */
    public function set_log_channel($logChannel): void
    {
        $this->logger->set_log_channel($logChannel);
    }
}