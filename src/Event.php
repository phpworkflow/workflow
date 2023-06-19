<?php
namespace Workflow;

use Workflow\Storage\IStorage;
use Exception;
use JsonException;

class Event {

    /* @var int $workflow_id */
    protected int $workflow_id=0;
    protected int $event_id=0;

    protected string $type;
    protected string $status;
    /* @var Context $context */
    protected Context $context;

    protected ?string $started_at = null;
    /**
     * @param string $type
     * @param $context
     * @param string $status
     *
     * @throws Exception
     */
    public function __construct(string $type, $context=null, string $status=IStorage::STATUS_ACTIVE) {

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
        }

    }

    /**
     * @return int
     */
    public function get_id(): int {
        return $this->event_id;
    }


    /**
     * @return string
     */
    public function get_type(): string {
        return $this->type;
    }

    /**
     * @return string
     */
    public function get_status(): string {
        return $this->status;
    }

    /**
     * @param string $status of the event
     */
    public function set_status(string $status): void {
        $this->status = $status;
    }

    public function set_key_data($key, $value): void {
        $this->context->set($key, $value, Context::NAMESPACE_SUBSCRIPTION);
    }

    public function set_additional_data($key, $value): void {
        $this->context->set($key, $value);
    }

    public function get_key_data(): array {
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
     * @return string
     * @throws JsonException
     */
    public function getContext(): string {
        return $this->context->serialize();
    }

    /**
     * Set the event state
     * @param $serializedState
     * @return void
     * @throws Exception
     */
    public function setContext($serializedState): void {
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

    /**
     * @return string|null
     */
    public function getStartedAt(): ?string
    {
        return $this->started_at;
    }

    /**
     * @param string|null $started_at
     */
    public function setStartedAt(?string $started_at): void
    {
        $this->started_at = $started_at;
    }

}
