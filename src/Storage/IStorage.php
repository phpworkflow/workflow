<?php

namespace Workflow\Storage;

use PDO;
use Workflow\IFactory;
use Workflow\Logger\ILogger;
use Workflow\Workflow;
use Workflow\Event;

interface IStorage
{
    const STATUS_FINISHED = 'FINISHED';
    const STATUS_ACTIVE = 'ACTIVE';
    const STATUS_IN_PROGRESS = 'INPROGRESS';
    const STATUS_FAILED = 'FAILED';
    const STATUS_PROCESSED = 'PROCESSED';
    const STATUS_NO_SUBSCRIBERS = 'NOSUBSCR';

    const CLEANUP_TIME = 3600;

    /**
     * Returns the instance of Storage (IStorage interface)
     * @param string $dsn
     * @param ILogger $logger
     *
     * @return IStorage
     */
    public static function instance(string $dsn, ILogger $logger = null);

    /**
     * Creates new workflow in the storage
     * @param Workflow $workflow
     * @param bool $unique
     * @return boolean
     */
    public function create_workflow(Workflow $workflow, $unique = false);

    /**
     * Returns the list of workflows in status STATUS_ACTIVE
     * @return array of int
     */
    public function get_active_workflow_ids();

    /**
     * Returns the array with arrays of workflow IDs grouped by type
     * @return array
     */
    public function get_active_workflow_by_type($limit = 10);

    /**
     * Returns Workflow object. Lock this workflow in storage by default
     * @param int $id workflow id
     * @param boolean $doLock
     *
     * @return Workflow $workflow
     */
    public function get_workflow($id, $doLock = true);

    /**
     * Save workflow object to storage. Unlock by default
     * @param Workflow $workflow
     * @param bool $unlock
     *
     * @return boolean
     */
    public function save_workflow(Workflow $workflow, $unlock = true);

    /**
     * Restore workflows with errors during execution
     */
    public function cleanup();

    /**
     * Creates new event in the storage
     * @param Event $event
     * @return boolean
     */
    public function create_event(Event $event);

    /**
     * Update event data after event was processed
     * @param Event $event
     *
     * @return boolean
     */
    public function close_event(Event $event);

    /**
     * @param $workflow_id
     *
     * @return Event[] $events array of Event objects
     */
    public function get_events($workflow_id);

    /**
     * Store $log_message to log
     * @param $log_message
     * @param $workflow_id
     * @return void
     */
    public function store_log($log_message, $workflow_id = 0);
}