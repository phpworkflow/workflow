<?php

namespace Workflow\Storage;

use Workflow\Factory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Exception;
use LogicException;
use RuntimeException;

use Workflow\IFactory;
use Workflow\Logger\Logger;
use Workflow\Logger\ILogger;
use Workflow\Subscription;
use Workflow\Workflow;
use Workflow\Event;
use Workflow\SystemUtils;

class Postgres implements IStorage
{
    use SystemUtils;

    /* @var IStorage $_storage */
    private static $_storage = null;

    /**
     * @var string
     */
    private string $db_structure;

    /* @var ILogger $logger */
    private $logger;

    /* @var Connection $db */
    private $db;

    /**
     * @param Connection $connection
     * @param ILogger|null $logger
     *
     * @return IStorage
     */
    public static function instance(Connection $connection, ILogger $logger = null): IStorage
    {
        if (self::$_storage === null) {
            self::$_storage = new Postgres($connection);
        }

        return self::$_storage;
    }

    /**
     * @return IStorage
     * @throws \Doctrine\DBAL\Exception
     */
    public function clone():IStorage {
        $connection = new Connection(
            $this->db->getParams(),
            $this->db->getDriver()
        );
        return new Postgres($connection);
    }

    /**
     * Postgres constructor.
     * @param $databaseDSN - pgsql:host=localhost;port=5432;dbname=testdb;user=bruce;password=mypass
     * @param IFactory|null $factory
     * @param ILogger|null $logger
     */
    protected function __construct(Connection $connection)
    {
        $this->logger = Logger::instance();
        $this->db = $connection;
    }

    private function createSubscription(Workflow $workflow, $is_new = true)
    {
        /**
         * @var Subscription $s
         */
        foreach ($workflow->get_subscription($is_new) as $s) {
            // Workflow can be subscribe to several values of some event filter
            $values = is_array($s->context_value) ? $s->context_value : [$s->context_value];

            foreach ($values as $v) {
                $statement = $this->db->prepare("SELECT workflow_id from subscription
                      WHERE workflow_id = :workflow_id AND
                       event_type = :event_type AND
                       context_key = :context_key AND
                       context_value = :context_value");

                $statement->execute([
                    'workflow_id' => $workflow->get_id(),
                    'event_type' => $s->event_type,
                    'context_key' => $s->context_key,
                    'context_value' => $v
                ]);

                $row = $statement->fetch();
                $statement = null;

                if (($row['workflow_id'] ?? -1) == $workflow->get_id()) {
                    continue;
                }

                $statement = $this->db->prepare("INSERT INTO subscription (
                          status, event_type, context_key, context_value, workflow_id)
                    VALUES (:status, :event_type, :context_key, :context_value, :workflow_id)");

                $statement->execute([
                    'status' => IStorage::STATUS_ACTIVE,
                    'event_type' => $s->event_type,
                    'context_key' => $s->context_key,
                    'context_value' => $v,
                    'workflow_id' => $workflow->get_id()
                ]);
            }
        }
    }

    /**
     * @param Workflow $workflow
     * @param false $unique
     *
     * @return bool
     * TODO implement usage of $unique - workflow of such type should be only one
     */
    public function create_workflow(Workflow $workflow, $unique = false)
    {

        try {

            $this->db->beginTransaction();
            $statement = $this->db->prepare("INSERT INTO workflow (type, context, scheduled_at, finished_at, status)
                VALUES (:type, :context, to_timestamp(:scheduled_at_ts), null, :status)");

            $statement->execute([
                'type' => $workflow->get_type(),
                'context' => $workflow->get_state(),
                'scheduled_at_ts' => $workflow->get_start_time(),
                'status' => IStorage::STATUS_ACTIVE
            ]);

            $workflow_id = $this->db->lastInsertId('workflow_workflow_id_seq');
            $workflow->set_id($workflow_id);

            $this->createSubscription($workflow);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param Event $event
     * @return bool|int
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function create_event(Event $event)
    {

        $sql = "insert into event (type, context, status, workflow_id)
                select cast(:type as text), :context, :status, workflow_id
                    from subscription
                        where event_type = :type
                            and ((context_key = :context_key and context_value = :context_value)
                                or (context_key = :empty_value and context_value  = :empty_value))
                limit 1000
        ";

        // empty key => value for case "where event_type = :type and context_key is null and context_value is null"
        // if $keyData is empty
        $keyData = array_merge(['' => ''], $event->get_key_data());

        try {
            $countEvents = 0;
            foreach ($keyData as $context_key => $context_value) {
                $statement = $this->db->prepare($sql);
                $result = $statement->execute([
                    'type' => $event->get_type(),
                    'context' => $event->getContext(),
                    'status' => IStorage::STATUS_ACTIVE,
                    'context_key' => $context_key,
                    'context_value' => $context_value,
                    'empty_value' => Subscription::EMPTY
                ]);

                if (!$result) {
                    $this->logger->error(json_encode($statement->errorInfo()));
                }
                $countEvents += $statement->rowCount();
            }

            if ($countEvents === 0) {
                $sql = 'insert into event (type, status, context, workflow_id) values (:type, :status, :context, 0)';
                $statement = $this->db->prepare($sql);
                $statement->execute([
                    'type' => $event->get_type(),
                    'status' => IStorage::STATUS_NO_SUBSCRIBERS,
                    'context' => $event->getContext()
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        return $countEvents;

    }

    /**
     * Returns the array with IDs of workflows for execution
     * @return array
     */
    public function get_active_workflow_ids($type = '')
    {
        $sql = 'select distinct wf.workflow_id, wf.scheduled_at 
                    from workflow wf left join 
                        event e on wf.workflow_id = e.workflow_id
            where 1=1
                and (e.status = :status or wf.status = :status)
                and (wf.scheduled_at <= current_timestamp or e.started_at <= current_timestamp )
            order by wf.scheduled_at
                limit 100';

        $statement = $this->db->prepare($sql);
        $statement->execute([
            'status' => IStorage::STATUS_ACTIVE
        ]);
        $column = $statement->fetchFirstColumn();
        return $column ?: [];
    }

    /**
     * @param int $id
     * @param bool $doLock
     *
     * @return Workflow|null
     */
    public function get_workflow($id, $doLock = true)
    {

        $lockId = $this->get_lock_string();

        $selectSql = 'SELECT type, context, error_count FROM workflow WHERE workflow_id = :id';
        $params = [
            'id' => $id
        ];

        if ($doLock) {
            $sql = "UPDATE workflow SET 
                lock = :lock_id,
                status = :status,
                started_at = current_timestamp,
                error_count=error_count+1
            WHERE workflow_id = :workflow_id AND lock = ''";

            $this->doSql($sql, [
                'lock_id' => $lockId,
                'status' => IStorage::STATUS_IN_PROGRESS,
                'workflow_id' => $id
            ]);

            $selectSql .= ' AND lock=:lock_id';
            $params['lock_id'] = $lockId;
        }

        $statement = $this->db->prepare($selectSql);
        $statement->execute($params);
        $row = $statement->fetchAssociative();

        if (!isset($row['type'])) {
            return null;
        }

        $workflow = (new Factory())->new_workflow($row['type']);
        $workflow->set_state($row['context']);
        $workflow->set_id($id);

        // We finish workflow in case of error_limit reached
        if ($workflow->many_errors($row['error_count'])) {
            $workflow->finish();
        }

        return $workflow;
    }

    /**
     * @return bool
     */
    protected function is_created()
    {
        $structure = file_get_contents($this->db_structure);
        if (!preg_match_all('/CREATE TABLE (\w+)/sim', $structure, $match)) {
            throw new LogicException('Database structure not exists');
        }

        try {
            foreach ($match[1] as $tableName) {
                $this->db->query("SELECT 1 FROM $tableName LIMIT 1");
            }
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param $sql
     * @param $params
     *
     * @return Statement
     */
    private function doSql($sql, $params)
    {
        $statement = $this->db->prepare($sql);
        $result = $statement->execute($params);
        if (!$result) {
            throw new RuntimeException("Error execute: $sql with params: " . var_export($params, true));
        }
        return $statement;
    }

    public function save_workflow(Workflow $workflow, $unlock = true)
    {

        $sql = 'update workflow set
            context = :context,
            scheduled_at = to_timestamp(:scheduled_at_ts),
            finished_at = current_timestamp,
            lock = coalesce(:lock, lock),        
            status = coalesce(:status, status),
            error_count = error_count - coalesce(:error_count, 0)
                where workflow_id = :workflow_id
        ';

        $status = $workflow->is_finished()
            ? IStorage::STATUS_FINISHED
            : ($unlock ? IStorage::STATUS_ACTIVE : null);

        $params = [
            'workflow_id' => $workflow->get_id(),
            'context' => $workflow->get_state(),
            'scheduled_at_ts' => $workflow->get_start_time(),
            'lock' => $unlock ? '' : null,
            'status' => $status,
            'error_count' => $unlock ? 1 : null
        ];

        return $this->doSql($sql, $params);
    }

    public function close_event(Event $event)
    {
        $sql = 'UPDATE event set status = :status, finished_at = current_timestamp
              WHERE event_id = :event_id';

        return $this->doSql($sql, [
            'event_id' => $event->get_id(),
            'status' => self::STATUS_PROCESSED
        ]);
    }

    /**
     * Restore workflows with errors during execution
     * @return void
     */
    public function cleanup()
    {
        $sql = 'select workflow_id, "lock" from workflow where
                    status = :status
                    and "lock" <> \'\'
                    and EXTRACT(epoch FROM (current_timestamp - started_at)) > :time_limit
                    limit 100';

        $result = $this->doSql($sql, [
            'status' => IStorage::STATUS_IN_PROGRESS,
            'time_limit' => $this->get_execution_time_limit()
        ]);

        while ($row = $result->fetchNumeric()) {
            list($workflow_id, $lock) = $row;
            list($host, $pid) = $this->get_host_pid_from_lock_string($lock);
            if (self::process_exists($host, $pid)) {
                $this->logger->warn("Workflow $workflow_id - is running for long time");
                continue;
            }

            $this->doSql('update workflow set lock = :lock, status=:status WHERE workflow_id = :workflow_id', [
                'lock' => '',
                'status' => IStorage::STATUS_ACTIVE,
                'workflow_id' => $workflow_id
            ]);
        }
    }

    protected function get_execution_time_limit()
    {
        return self::CLEANUP_TIME;
    }

    /**
     * @param $workflow_id
     * @return Event[] $events array of Event objects
     */
    public function get_events($workflow_id)
    {
        $sql = "select event_id, type, context from event where 
                status = :status 
                and workflow_id = :workflow_id 
                    limit 100;
                ";

        $result = $this->doSql($sql, [
            'status' => IStorage::STATUS_ACTIVE,
            'workflow_id' => $workflow_id
        ]);

        $events = [];
        while ($row = $result->fetchAssociative()) {
            $e = new Event($row['type'], $row['context']);
            $e->setEventId($row['event_id']);
            $e->setWorkflowId($workflow_id);
            $events[] = $e;
        }

        return $events;
    }

    /**
     * Store $log_message to log
     * @param $log_message
     * @return void
     */
    public function store_log($log_message, $workflow_id = 0)
    {
        $this->doSql('insert into log (workflow_id, log_text) values (:workflow_id, :log_text)', [
            'workflow_id' => $workflow_id,
            'log_text' => $log_message
        ]);
    }

}