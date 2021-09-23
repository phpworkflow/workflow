<?php

namespace Workflow\Storage;

use PDO;
use PHPUnit\Framework\TestCase;

use Workflow\Event;
use Workflow\Example\RegularAction;
use Workflow\Workflow;

class TPostgres extends Postgres {
    const TEST_CLEANUP_TIME = 2;
    const TEST_WAIT_TIME = 3;

    public function __construct(PDO $connection)
    {
        parent::__construct($connection);
    }

    protected function get_execution_time_limit()
    {
        return self::TEST_CLEANUP_TIME;
    }

    public function get_host_pid_from_lock_string($lock) {
        return parent::get_host_pid_from_lock_string($lock);
    }
}

class PostgresTest extends TestCase
{
    private $connection;

    public function setup()
    {
        // pgsql:user=<user>;password=<password>;host=localhost;port=5432;dbname=workflow
        $this->connection = new PDO($_ENV['WORKFLOW_DB_DSN']);
    }

    public function testCleanup() {

        $storage = new TPostgres($this->connection);

        self::assertNotEmpty($storage);

        $workflowId = $this->createWorkflow($storage);

        self::assertGreaterThan(0,$workflowId);

        $workflow=$storage->get_workflow($workflowId);

        self::assertNotEmpty($workflow);

        $storage->save_workflow($workflow);

        $workflow2=$storage->get_workflow($workflowId);
        $workflow=$storage->get_workflow($workflowId);
        self::assertNotEmpty($workflow2);
        self::assertEmpty($workflow);

        sleep(TPostgres::TEST_WAIT_TIME);

        $storage->cleanup();

        $workflow2=$storage->get_workflow($workflowId);
        // Can't cleanup due to same pid
        self::assertEmpty($workflow2);

    }

    public function testCreateEvent() {
        $storage = new TPostgres($this->connection);

        self::assertNotEmpty($storage);
        $workflowId = $this->createWorkflow($storage);

        self::assertGreaterThan(0,$workflowId);

        $count = $storage->create_event(new Event(RegularAction::TEST_EVENT));

        self::assertGreaterThan(0,$count);

        $workflow = $storage->get_workflow($workflowId);

        self::assertNotEmpty($workflow);

        $events = $storage->get_events($workflowId);

        self::assertNotEmpty($events);
        $workflow->set_sync_callback(function(Workflow $workflow, Event $event) use ($storage) {
            $storage->close_event($event);
            $storage->save_workflow($workflow, false);
        });

        $workflow->run($events);

        $storage->save_workflow($workflow);

        $events = $storage->get_events($workflowId);
        self::assertEmpty($events);
    }

    private function createWorkflow($storage) {
        $workflow = new RegularAction();
        return $workflow->put_to_storage($storage);
    }

    public function testGetPidFromLock() {
        $storage = new TPostgres($this->connection);
        $lock = 'a51fad2e4cef+37+733511867';

        list($host, $pid) = $storage->get_host_pid_from_lock_string($lock);
        self::assertEquals(37, $pid);
        self::assertEquals('a51fad2e4cef', $host);
    }
}
