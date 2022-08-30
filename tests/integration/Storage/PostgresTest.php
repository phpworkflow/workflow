<?php

namespace Workflow\Storage;

use PDO;
use PHPUnit\Framework\TestCase;

use Workflow\Event;
use Workflow\Example\GoodsSaleWorkflow;
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

    private $storage;

    public function setup()
    {
        // pgsql:user=<user>;password=<password>;host=localhost;port=5432;dbname=workflow
        $this->connection = new PDO($_ENV['WORKFLOW_DB_DSN']);
        $this->storage = new TPostgres($this->connection);
    }

    public function testCleanup() {

        $storage = $this->storage; //new TPostgres($this->connection);

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

    public function testCreateUniqueWorkflow() {
        $workflow1=new GoodsSaleWorkflow();
        $workflow1->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER, 3456);
        $result = $this->storage->create_workflow($workflow1, true);
        self::assertTrue($result);

        $workflow2=new GoodsSaleWorkflow();
        $workflow2->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER, 3456);
        $result = $this->storage->create_workflow($workflow2, true);
        self::assertFalse($result);

        $this->storage->finish_workflow($workflow1->get_id());

        $result = $this->storage->create_workflow($workflow2, true);
        self::assertTrue($result);
        $this->storage->finish_workflow($workflow2->get_id());
    }

    public function testCreateEvent() {
        $storage = $this->storage; //new TPostgres($this->connection);

        self::assertNotEmpty($storage);
        $workflowId = $this->createWorkflow($storage);

        self::assertGreaterThan(0,$workflowId);

        $count = $storage->create_event(new Event(RegularAction::TEST_EVENT));

        self::assertGreaterThan(0,$count);

        $workflow = $storage->get_workflow($workflowId);

        self::assertNotEmpty($workflow);

        $events = $storage->get_events($workflowId);

        self::assertNotEmpty($events);
        $workflow->set_sync_callback(function(Workflow $workflow, ?Event $event=null) use ($storage) {
            if($event !== null) {
                $storage->close_event($event);
            }

            $storage->save_workflow($workflow, false);
        });

        $workflow->run($events);

        $storage->save_workflow($workflow);

        $events = $storage->get_events($workflowId);
        self::assertEmpty($events);
    }

    public function testGetPidFromLock() {
        $storage = $this->storage; //new TPostgres($this->connection);
        $lock = 'a51fad2e4cef+37+733511867';

        list($host, $pid) = $storage->get_host_pid_from_lock_string($lock);
        self::assertEquals(37, $pid);
        self::assertEquals('a51fad2e4cef', $host);
    }

    public function testFinishWorkflow() {
        $active_ids = $this->storage->get_active_workflow_ids();
        $workflow_id = array_shift($active_ids);

        $workflow = $this->storage->get_workflow($workflow_id);
        $workflow->goto_node('end');
        $workflow->run();

        $result = $this->storage->save_workflow($workflow);

        self::assertTrue($result, 'Workflow wasn`t finished');

        $sql = "
select status from event where workflow_id = :workflow_id
union 
select status from subscription where workflow_id = :workflow_id
union
select status from workflow where workflow_id = :workflow_id
";
        $stm = $this->doSql($sql, ['workflow_id' => $workflow_id]);
        $rows = $stm->fetchAll();
        $statuses = array_map(function ($row) {return $row['status'];}, $rows);

        self::assertFalse(in_array(IStorage::STATUS_ACTIVE, $statuses));
    }

    private function doSql($sql, $params)
    {
        $statement = $this->connection->prepare($sql);
        $result = $statement->execute($params);
        self::assertTrue($result, $sql);
        return $statement;
    }

    private function createWorkflow($storage) {
        $workflow = new RegularAction();
        return $workflow->put_to_storage($storage);
    }

}
