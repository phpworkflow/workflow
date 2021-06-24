<?php
namespace Workflow;

use Workflow\Storage\IStorage;

class Event {

    /* @var int $workflow_id */
    protected $workflow_id=0;
    protected $event_id=0;
    protected $type;
    protected $status=0;
    /* @var Context $context */
    protected $context=null;

    public function __construct($type, $context=null, $status=IStorage::STATUS_ACTIVE) {

        $this->type=$type;
        $this->status=$status;

        if($context instanceof Context) {
            $this->context=$context;
            return;
        }

        $this->context=new Context();

        if(is_array($context)) {
            $this->context->set_all($context);
            return;
        }

        if(is_string($context)) {
            $this->context->unserialize($context);
            return;
        }

    }

    /**
     * @return int
     */
    public function get_id() {
        return $this->event_id;
    }


    /**
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * @return int
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * @param int $status of the event
     */
    public function set_status($status) {
        $this->status = $status;
    }

    public function set_key_data($key, $value) {
        $this->context->set($key, $value, Context::NAMESPACE_SUBSCRIPTION);
    }

    public function set_additional_data($key, $value) {
        $this->context->set($key, $value);
    }

    public function get_key_data() {
        return $this->context->get_all();
    }

    public function get_data($key) {
        $result=$this->context->get($key, Context::NAMESPACE_SUBSCRIPTION);
        if($result === null) {
            $result=$this->context->get($key);
        }
        return $result;
    }

    /**
     * Returns the serialized context of the event
     *
     * @return string
     */
    public function getContext() {
        return $this->context->serialize();
    }

    /**
     * Set the event state
     *
     * @param string $serialized_state
     */
    public function setContext($serializedState) {
        $this->context->unserialize($serializedState);
    }

    /**
     * @param int $workflow_id
     */
    public function setWorkflowId(int $workflow_id): void
    {
        $this->workflow_id = $workflow_id;
    }

    /**
     * @param int $event_id
     */
    public function setEventId(int $event_id): void
    {
        $this->event_id = $event_id;
    }

}
