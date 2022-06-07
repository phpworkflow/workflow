<?php
namespace Workflow;

use Exception;
use Workflow\Node\Base;
use Workflow\Node\Validator;
use Workflow\Node\INode;
use Workflow\Storage\IStorage;
use Workflow\Logger\WorkflowLogger;

abstract class Workflow {
    use WaitEvents;

    const CONTEXT_CALL_STACK = 'call_stack';
    const CONTEXT_CURRENT_NODE = 'node';
    const CONTEXT_CURRENT_NODE_NAME = 'node_name';
    const CONTEXT_START_TIME = 'start_time';
    const CONTEXT_WAIT_FOR = 'wait_for';

    const EVENT_ON="event_on";
    const EVENT_TARGET="event_target";
    const EVENT_FILTER="event_filter";

    // Endless cycles protection
    const MAX_ITERATIONS = 100;

    const DEFAULT_ERROR_LIMIT = 3;

    protected $workflow_id=0;
    /* @var WorkflowLogger $logger */
    protected $logger;

    /* @var Context $context */
    protected $workflow_context=null;
    protected $is_subscription_updated=false;

    protected $call_stack=[];    // Current execution node
    protected $current_node;    // Current execution node
    protected $start_time=0;  // Time of the next command execution
    protected $wait_for=[];
    /**
     * @var Event
     */
    protected $last_event=null;

    /** @var callable $sync_callback */
    private $sync_callback=null;

    static protected $events_map=[];
    static protected $compiled_nodes=[];
    static protected $nodes_map=[];

    protected $error_limit=self::DEFAULT_ERROR_LIMIT;   // Maximum number of errors for workflow

    public $error_info = '';         // Used for debugging and error handling

    protected $is_error = false;
    /**
     * Basic initialization. parent::__construct should be executed in sub classes constructor
     *
     * @param array $process_nodes
     */
    public function __construct(array $process_nodes = [], array $events_map=[]) {

        $class=get_class($this);

        if(!isset(self::$compiled_nodes[$class])) {
            $validator=new Validator($process_nodes, $this);
            self::$compiled_nodes[$class]=$validator->get_prepared_nodes();
            static::prepare_nodes_map($class);
        }

        if(!isset(self::$events_map[$class])) {
            self::$events_map[$class]=$events_map;
        }

        $this->current_node=array_values(self::$compiled_nodes[$class])[0]->get_id();
        $this->workflow_context=new Context();

        // Set the default state. It can be overwritten by context from storage
        foreach($events_map as $event_type => $params) {
            if($params[self::EVENT_ON]) {
                $this->start_wait_for($event_type);
            }
        }

        $this->logger=WorkflowLogger::create($this->workflow_id);
    }

    static private function prepare_nodes_map($class) {
        $map=[];
        /* @var INode $node */
        foreach(self::$compiled_nodes[$class] as $node) {
            $map[$node->get_name()]=$node->get_id();
        }
        self::$nodes_map[$class]=$map;
    }

    public function get_logger() {
        return $this->logger;
    }

    public function get_id() {
        return $this->workflow_id;
    }


    public function set_id($workflowId) {
        $this->workflow_id=$workflowId;
        $this->logger->setWorkflowId($workflowId);
    }
    /**
     *
     * @param int $error_count
     * @return bool
     */
    public function many_errors($error_count) {
        if($this->error_limit === 0) {
            return false;
        }

        return $this->error_limit < $error_count;
    }
    /**
     * Set the process state
     * @param string $serialized_state
     */
    public function set_state($serialized_state) {
        $this->workflow_context->unserialize($serialized_state);
        // Counters are inside workflow_context, we don't need to assign them to something
        // TODO replace member variables with getters and remove members
        $this->start_time=$this->workflow_context->get(self::CONTEXT_START_TIME);
        $this->current_node=$this->workflow_context->get(self::CONTEXT_CURRENT_NODE);
        $this->call_stack=$this->workflow_context->get(self::CONTEXT_CALL_STACK);
        $this->wait_for=$this->workflow_context->get(self::CONTEXT_WAIT_FOR);
    }

    /** Returns the serialized context of the workflow
     * @return string
     */
    public function get_state() {
        $this->workflow_context
            ->set(self::CONTEXT_START_TIME, $this->start_time)
            ->set(self::CONTEXT_CURRENT_NODE, $this->current_node)
            ->set(self::CONTEXT_CURRENT_NODE_NAME, $this->get_current_node_name())
            ->set(self::CONTEXT_CALL_STACK, $this->call_stack)
            ->set(self::CONTEXT_WAIT_FOR, $this->wait_for);

        return $this->workflow_context->serialize();
    }


    protected function get_context($key) {
        return $this->workflow_context->get($key, Context::NAMESPACE_USER);
    }

    public function set_context($key, $value) {
        $this->workflow_context->set($key, $value, Context::NAMESPACE_USER);

        foreach(self::$events_map[get_class($this)] as $event_type => $params) {
            if(!isset($params[self::EVENT_FILTER])) {
                continue;
            }

            foreach($params[self::EVENT_FILTER] as $filter_key) {
                // We need to update subscription in case key data was set
                if($filter_key === $key) {
                    $this->is_subscription_updated=true;
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
    public function get_subscription($no_update_check=false) {

        $result=[];

        if( !( $this->is_subscription_updated || $no_update_check) ) {
            return $result; // Subscription was not changed
        }

        foreach(self::$events_map[get_class($this)] as $event_type => $params) {
            $s=new Subscription($event_type);

            if(empty($params[self::EVENT_FILTER])) {
                $result[]=$s;
                continue;
            }

            foreach($params[self::EVENT_FILTER] as $filter_key) {
                $s->context_value=$this->get_context($filter_key);
                // If we don't have concrete value for filter we skip this subscription
                if(empty($s->context_value)) {
                    continue;
                }
                $s->context_key=$filter_key;
                $result[]=$s;
            }
        }

        return $result;
    }


    public function get_counter($counter_name) {
        return $this->workflow_context->get($counter_name, Context::NAMESPACE_COUNTER);
    }

    public function set_counter($counter_name, $value) {
        $this->workflow_context->set($counter_name, $value, Context::NAMESPACE_COUNTER);
    }

    /**
     * Process incoming events
     *
     * @param Event $event
     * @return Event
     */
    private function handle_event(Event $event=null) {
        if(!$event) {
            return null;
        }

        $event_type=$event->get_type();
        $this->logger->debug("Event $event_type arrived.");

        $class=get_class($this);
        if(!isset(self::$events_map[$class][$event_type])) {
            $this->logger->debug(" !!! Event '$event_type' not presents in event map.");
            return null;
        }

        if(!$this->is_waiting_for($event->get_type())) {
            $this->logger->debug(" !!! Event $event_type skipped. Workflow is not waiting for it.");
            return null;
        }

        $this->set_exec_time();

        $event_target=self::$events_map[$class][$event_type][self::EVENT_TARGET];
        $this->goto_node($event_target);

        return $event;
    }

    private function _run() {
        /* @var INode $current_node */
        // $next_node_id=INode::LAST_NODE;
        do {
            $current_node = $this->get_current_node();

            if($current_node === null) {
                $this->logger->debug("Workflow finished");
                return;
            }

            // $this->logger->debug(print_r($current_node,true));

            $time_to_run=$this->start_time-$this->now();

            if($time_to_run > 0) {
                $this->logger->debug("$time_to_run seconds left for execution of ".$current_node->get_name());
                return;
            }

            $this->logger->debug("--- ".get_class($current_node).": ".$current_node->get_name());

            $next_node_id=$current_node->execute($this);

            $this->set_current_node($next_node_id);

        } while(!( empty($next_node_id) || $this->is_finished()) );
    }

    private function set_current_node($node_id) {
        // TODO remove this after tests
        if(!is_numeric($node_id)) {
            throw new Exception("Node ID $node_id is wrong should be number.");
        }
        $this->current_node=$node_id;
    }

    public function get_node_id_by_name($node_name) {
        if(isset(self::$nodes_map[get_class($this)][$node_name])) {
            return self::$nodes_map[get_class($this)][$node_name];
        }

        throw new Exception("Node $node_name does not exists");
    }

    /*
     * @return INode $node
     */
    private function get_current_node() {
        if(isset(self::$compiled_nodes[get_class($this)][$this->current_node])) {
            return self::$compiled_nodes[get_class($this)][$this->current_node];
        }

        if($this->current_node === INode::LAST_NODE) {
            return null;
        }

        throw new Exception("Node $this->current_node does not exists");
    }


    /**
     * @return int
     */
    public function now() {
        return time();
    }

    /**
     * @param Event[] $events array of Event objects
     */
    public function run(array $events=[]) {

        if(!$this->on_start()) {
            $this->logger->info("on_start returns false. Skip workflow execution.");
            return;
        }

        $iteration_counter = 0;

        try {
            do {
                $this->last_event=$this->handle_event(array_shift($events));

                // Endless cycles protection
                if(++$iteration_counter > self::MAX_ITERATIONS) {
                    throw new Exception("Exceeded the maximum number of iterations: ".self::MAX_ITERATIONS);
                }

                $this->_run();

                $this->sync();

                $this->last_event=null;

            } while ( $this->current_node != INode::LAST_NODE && (count($events) > 0));

            $this->on_finish();
        } catch (Exception $e) {
            $this->logger->warn("run Exception: " . $e->getMessage());
            $this->logger->warn($e->getTraceAsString());
            $this->error_info = "Exception: " . $e->getMessage();
            $this->is_error = true;
        }
    }

    /**
     * @return bool
     */
    public function is_error() {
        return $this->is_error;
    }

    /**
     * Switch current process node

     * @param $node_name
     * @return bool
     *
     * @throws Exception
     */
    public function goto_node($node_name) {

        if($this->node_exists($node_name)) {
            $node_id=$this->get_node_id_by_name($node_name);
            $this->logger->debug("Switch to $node_name (id: $node_id) by GOTO_NODE call.");
            /** @var Base $current_node */
            $current_node = $this->get_current_node();
            if($current_node === null) {
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
    public function node_exists($node_name) {
        return isset(self::$nodes_map[get_class($this)][$node_name]);
    }

    /**
     * Return current node for execution, will be used for testing proposal
     * @return string
     */
    public function get_current_node_name() {
        return self::$compiled_nodes[get_class($this)][$this->current_node]->get_name();
    }

    /**
     * Return current node for execution, will be used for testing proposal
     * @return string
     */
    public function get_node_name_by_id($node_id) {
        return self::$compiled_nodes[get_class($this)][$node_id]->get_name();
    }


    /**
     * Return current node for execution, will be used for testing proposal
     * @return string
     */
    public function get_current_node_id() {
        return $this->current_node;
    }


    /**
     * Return current process status
     * @return boolean TRUE - process finished
     */
    public function is_finished() {
        return ($this->current_node == INode::LAST_NODE);
    }

    /**
     * Force finish workflow
     */
    public function finish() {
        $this->current_node=INode::LAST_NODE;
    }

    /**
     * Reset wait timer, process can flow
     * @param mixed $time
     */
    public function set_exec_time($time=0) {
        $type = gettype($time);
        switch ($type) {
            case 'string':
            case 'integer': {
                $this->start_time = (int)$time;
                break;
            }
            case 'object': {
                if(method_exists($time, 'getTimestamp')) {
                    $this->start_time = (int) $time->getTimestamp();
                    break;
                }
            }
            default: {
                $this->logger->warn("Argument type $type for __METHOD__ not supported");
            }
        }
    }

    /**
     * @return integer
     */
    public function get_start_time() {
        return $this->start_time;
    }

    /**
     *
     */
    public function get_type() {
        return get_class($this);
    }

    /**
     * Allow to assign function to save workflow and event state to storage
     * @param $sync_callback
     */
    public function set_sync_callback($sync_callback) {
        $this->sync_callback=$sync_callback;
    }

    /**
     * Save state of the workflow after processing of the event
     */
    protected function sync() {
        if(!is_callable($this->sync_callback)) {
            return;
        }

        call_user_func($this->sync_callback, $this, $this->last_event);
    }

    /**
     * Put process to execution queue
     */
    public function put_to_storage(IStorage $storage, $unique = false) {
        $storage->create_workflow($this, $unique);
        return $this->workflow_id;
    }

    /**
     * @param string $node_id
     */
    public function add_to_call_stack($node_id) {
        $this->call_stack[] = $node_id;
    }

    /**
     * Return workflow execution to the previous saved node
     */
    public function return_to_saved_node() {
        if (count($this->call_stack) == 0) {
            throw new Exception("Can't return the call stack is empty");
        }
        $saved_node=array_pop($this->call_stack);
        $this->logger->debug("Return from procedure to ".$this->get_node_name_by_id($saved_node));
        $this->set_current_node($saved_node);
    }

    /*
     * on_start is executed before execution of main flow
     * it can be used for initialization of some workflow objects
     * @return boolean $result - the workflow is executed in case on_start returns TRUE
     */
    public function on_start() {
        return true;
    }


    /*
     * Is executed after workflow main flow
     */
    public function on_finish() {
    }

    /**
     * @param $logChannel
     * TODO camelCase, type
     */
    public function set_log_channel($logChannel)
    {
        $this->logger->set_log_channel($logChannel);
    }

}