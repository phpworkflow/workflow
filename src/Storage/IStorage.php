<?php

namespace Workflow\Storage;

use Workflow\Logger\ILogger;
use Workflow\Logger\Message;
use Workflow\Workflow;
use Workflow\Event;

interface IStorage
{
    public const STATUS_FINISHED = 'FINISHED';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_IN_PROGRESS = 'INPROGRESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_PROCESSED = 'PROCESSED';
    public const STATUS_NO_SUBSCRIBERS = 'NOSUBSCR';

    public const CLEANUP_TIME = 3600;

    /**
     * Returns the instance of Storage (IStorage interface)
     * @param string $dsn
     * @param ILogger|null $logger
     *
     * @return IStorage
     */
    public static function instance(string $dsn, ILogger $logger = null): self;

    /**
     * Creates new workflow in the storage
     * @param Workflow $workflow
     * @param bool $unique
     * @return boolean
     */
    public function create_workflow(Workflow $workflow, bool $unique = false): bool;

    /**
     * Returns the list of workflows in status STATUS_ACTIVE
     * @return int[]
     */
    public function get_active_workflow_ids(): array;

    /**
     * Returns the array with arrays of workflow IDs grouped by type
     * @param int $limit
     * @return array
     */
    public function get_active_workflow_by_type(int $limit = 10): array;

    /**
     * Returns Workflow object. Lock this workflow in storage by default
     * @param int $id workflow id
     * @param boolean $doLock
     *
     * @return Workflow $workflow
     */
    public function get_workflow(int $id, bool $doLock = true): ?Workflow;

    /**
     * Save workflow object to storage. Unlock by default
     * @param Workflow $workflow
     * @param bool $unlock
     *
     * @return boolean
     */
    public function save_workflow(Workflow $workflow, bool $unlock = true): bool;

    /**
     * Restore workflows with errors during execution
     */
    public function cleanup(): void;

    /**
     * Creates new event in the storage
     * @param Event $event
     * @return null | int
     */
    public function create_event(Event $event): ?int;

    /**
     * Update event data after event was processed
     * @param Event $event
     *
     * @return boolean
     */
    public function close_event(Event $event): bool;

    /**
     *
     * @param int $workflow_id
     * @return Event[] $events array of Event objects
     */
    public function get_events(int $workflow_id): array;

    /**
     * Store $log_message to log
     * @param string $log_message
     * @param int $workflow_id
     * @return void
     */
    public function store_log(string $log_message, int $workflow_id = 0): void;

    /**
     * @param Message[] $messages
     * @return void
     */
    public function store_log_array(array $messages): void;
}