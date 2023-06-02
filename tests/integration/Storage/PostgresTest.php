<?php

namespace Workflow\Storage;

use PDO;
use PHPUnit\Framework\TestCase;
use Exception;

use Workflow\Event;
use Workflow\Example\GoodsSaleFlow2;
use Workflow\Example\GoodsSaleWorkflow;
use Workflow\Example\RegularAction;
use Workflow\Workflow;

class TPostgres extends Postgres {
    public const TEST_CLEANUP_TIME = 2;
    public const TEST_WAIT_TIME = 3;

    public function __construct(PDO $connection)
    {
        parent::__construct($connection);
    }

    protected function get_execution_time_limit(): int
    {
        return self::TEST_CLEANUP_TIME;
    }

    public function get_host_pid_from_lock_string($lock) {
        return parent::get_host_pid_from_lock_string($lock);
    }
}

class PostgresTest extends TestCase
{
    private const TEST_CUSTOMER_ID = 3456;

    private PDO $connection;

    private TPostgres $storage;

    public function setUp(): void
    {
        // pgsql:user=<user>;password=<password>;host=localhost;port=5432;dbname=workflow
        $this->connection = new PDO($_ENV['WORKFLOW_DB_DSN']);
        $this->storage = new TPostgres($this->connection);
    }

    public function testCleanup(): void {

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
        // Can't process cleanup due to same pid
        self::assertEmpty($workflow2);

    }

    public function testCreateUniqueWorkflow(): void {
        $workflow1=new GoodsSaleWorkflow();
        $workflow1->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER,  self::TEST_CUSTOMER_ID);
        $result = $this->storage->create_workflow($workflow1, true);
        self::assertTrue($result);

        $workflow2=new GoodsSaleWorkflow();
        $workflow2->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER,  self::TEST_CUSTOMER_ID);
        $result = $this->storage->create_workflow($workflow2, true);
        self::assertFalse($result);

        $this->storage->finish_workflow($workflow1->get_id());

        $result = $this->storage->create_workflow($workflow2, true);
        self::assertTrue($result);


        $this->storage->finish_workflow($workflow2->get_id());
    }

    public function testCreateUniqueWorkflowDifferentTypesSameKey(): void {
        $workflow1=new GoodsSaleWorkflow();
        $workflow1->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER, self::TEST_CUSTOMER_ID);
        $result = $this->storage->create_workflow($workflow1, true);
        self::assertTrue($result);

        // Different type same context -> created
        $workflowType2_1 = new GoodsSaleFlow2();
        $workflowType2_1->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER,  self::TEST_CUSTOMER_ID);
        $result = $this->storage->create_workflow($workflowType2_1, true);
        self::assertTrue($result);

        // Same type different context -> created
        $workflowType2_2 = new GoodsSaleFlow2();
        $workflowType2_2->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER,  self::TEST_CUSTOMER_ID+1);
        $result = $this->storage->create_workflow($workflowType2_2, true);
        self::assertTrue($result);

        // Same type same context -> DUPLICATION
        $workflowType2_3 = new GoodsSaleFlow2();
        $workflowType2_3->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER,  self::TEST_CUSTOMER_ID);
        $result = $this->storage->create_workflow($workflowType2_3, true);
        self::assertFalse($result);

        $this->storage->finish_workflow($workflow1->get_id());
        $this->storage->finish_workflow($workflowType2_1->get_id());
        $this->storage->finish_workflow($workflowType2_2->get_id());
    }


    public function testCreateEvent(): void {
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
        $workflow->set_sync_callback(function(Workflow $workflow, ?Event $event=null) use ($storage): void {
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

    public function testGetPidFromLock(): void {
        $storage = $this->storage; //new TPostgres($this->connection);
        $lock = 'a51fad2e4cef+37+733511867';

        [$host, $pid] = $storage->get_host_pid_from_lock_string($lock);
        self::assertEquals(37, $pid);
        self::assertEquals('a51fad2e4cef', $host);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testFinishWorkflow(): void {
        $active_ids = $this->storage->get_active_workflow_ids();
        $workflow_id = array_shift($active_ids);

        if(empty($active_ids)) {
            $workflow1=new GoodsSaleWorkflow();
            $workflow1->set_context(GoodsSaleWorkflow::WF_KEY_CUSTOMER, self::TEST_CUSTOMER_ID+10);
            $result = $this->storage->create_workflow($workflow1, true);
            self::assertTrue($result, 'Workflow not created');
            $workflow_id = $workflow1->get_id();
        }

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
        $statuses = array_map(fn($row) => $row['status'], $rows);

        self::assertFalse(in_array(IStorage::STATUS_ACTIVE, $statuses));
    }

    private function doSql(string $sql, array $params)
    {
        $statement = $this->connection->prepare($sql);
        $result = $statement->execute($params);
        self::assertTrue($result, $sql);
        return $statement;
    }

    private function createWorkflow(IStorage $storage) {
        $workflow = new RegularAction();
        return $workflow->put_to_storage($storage);
    }

}
