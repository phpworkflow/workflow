<?php

namespace Workflow\Storage;

use PDO;
use PDOStatement as Statement;

use Exception;
use LogicException;
use RuntimeException;
use JsonException;
use Throwable;

use Workflow\Factory;
use Workflow\Logger\Logger;
use Workflow\Logger\ILogger;
use Workflow\Subscription;
use Workflow\Workflow;
use Workflow\Event;
use Workflow\SystemUtils;
use Workflow\Storage\Redis\Queue as RedisQueue;
use Workflow\Storage\Redis\Config as RedisConfig;
use Workflow\Storage\Redis\Event as RedisEvent;


class Postgres implements IStorage
{
    use SystemUtils;

    public const ENV_DEBUG_WF_SQL = 'DEBUG_WF_SQL';

    public const TASK_LIST_SIZE_LIMIT = 100;

    public const SCHEDULED_TASK_LIST_SIZE_LIMIT = 1000;

    public const HOST_DELETE_DELAY = 300;

    protected static ?IStorage $_storage = null;

    protected static string $dsn = '';

    /**
     * @var string
     */
    protected string $db_structure;

    /* @var ILogger $logger */
    protected ILogger $logger;

    /* @var PDO $db */
    protected PDO $db;

    protected bool $isDebug;

    protected RedisQueue $eventsQueue;

    /**
     * @param string $dsn
     * @param ILogger|null $logger
     * @return IStorage
     */
    public static function instance(string $dsn, ILogger $logger = null): IStorage
    {
        if (self::$_storage === null) {
            self::$dsn = $dsn;
            self::$_storage = self::createInstance($dsn);
        }

        return self::$_storage;
    }

    /**
     * @return bool
     */
    public static function reconnect(): bool {
        self::$_storage = null;
        $db = self::instance(self::$dsn);
        return $db->ping();
    }

    /**
     * @return bool
     */
    public function ping(): bool {
        try {
            return $this->doSql('select 1', []) !== false;
        }
        catch (Exception $e) {
            $this->logToStderr($e);
        }
        return false;
    }

    protected static function createInstance(string $dsn): IStorage
    {
        $connection = new PDO($dsn, null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return new static($connection);
    }

    public function clone(): IStorage
    {
        if (self::$dsn === '') {
            throw new LogicException("Absent connection parameters");
        }

        return self::createInstance(self::$dsn);
    }

    /**
     * Postgres constructor.
     * @param PDO $connection
     */
    protected function __construct(PDO $connection)
    {
        $this->logger = Logger::instance();
        $this->db = $connection;
        $this->isDebug = (getenv(self::ENV_DEBUG_WF_SQL) !== false);
        $cfg = new RedisConfig();
        $this->eventsQueue = new RedisQueue([$cfg->eventsQueue()], $cfg->queueLength());
    }

    /**
     * @param Workflow $workflow
     * @param bool $is_new
     * @return void
     * @throws Exception
     */
    protected function createSubscription(Workflow $workflow, bool $is_new = true): void
    {
        /**
         * @var Subscription $s
         */
        foreach ($workflow->get_subscription($is_new) as $s) {
            // Workflow can subscribe to several values of some event filter
            $values = is_array($s->context_value)
                ? $s->context_value
                : [$s->context_value];

            foreach ($values as $v) {
                $sql = 'SELECT workflow_id from subscription
                      WHERE workflow_id = :workflow_id AND
                       event_type = :event_type AND
                       context_key = :context_key AND
                       context_value = :context_value';

                $statement = $this->doSql($sql, [
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

                $sql = <<<SQL
INSERT INTO subscription (status, event_type, context_key, context_value, workflow_id)
VALUES ('ACTIVE', :event_type, :context_key, :context_value, :workflow_id)
SQL;

                $this->doSql($sql, [
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
     * @return bool
     */
    public function create_workflow(Workflow $workflow, $unique = false): bool
    {
        try {
            $this->db->beginTransaction();

            $sql = <<<SQL
INSERT INTO workflow (type, context, scheduled_at, finished_at, status)
VALUES (:type, :context, to_timestamp(:scheduled_at_ts), null, 'ACTIVE')
SQL;

            $this->doSql($sql, [
                'type' => $workflow->get_type(),
                'context' => $workflow->get_state(),
                'scheduled_at_ts' => $workflow->get_start_time()
            ]);

            $workflow_id = $this->db->lastInsertId('workflow_workflow_id_seq');
            $workflow->set_id($workflow_id);

            if ($unique && !$this->createUniqueness($workflow)) {
                $this->db->rollBack();
                return false;
            }

            $this->createSubscription($workflow);
            $this->db->commit();

            $this->eventsQueue->push(new RedisEvent($workflow_id, $workflow->get_type(), $workflow->get_start_time()));
        } catch (Throwable $e) {
            $this->logToStderr($e);
            $this->db->rollBack();
            $this->logger->error($e->getMessage());
            return false;
        }



        return true;
    }

    /**
     * @param $workflow_id
     * @return bool
     * @throws Exception
     */
    public function finish_workflow($workflow_id): bool
    {
        $workflow = $this->get_workflow($workflow_id);

        if($workflow === null) {
            return false;
        }

        try {

            $this->db->beginTransaction();

            $sql = <<<SQL
UPDATE event SET finished_at = current_timestamp,
     status = 'PROCESSED',
     started_at = current_timestamp
WHERE workflow_id = :workflow_id AND status = 'ACTIVE'
SQL;

            $this->doSql($sql, [
                'workflow_id' => $workflow_id
            ]);

            $sql = <<<SQL
UPDATE subscription SET status = 'FINISHED'
WHERE workflow_id = :workflow_id
SQL;

            $this->doSql($sql, [
                'workflow_id' => $workflow_id
            ]);

            $sql = <<<SQL
UPDATE uniqueness SET status = NULL
WHERE workflow_id = :workflow_id
SQL;

            $this->doSql($sql, [
                'workflow_id' => $workflow_id,
            ]);

            $sql = <<<SQL
UPDATE workflow SET
    finished_at = current_timestamp,
    status = 'FINISHED',
    "lock" = ''
WHERE workflow_id = :workflow_id
SQL;

            $this->doSql($sql, [
                'workflow_id' => $workflow_id
            ]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->logToStderr($e);
            $this->db->rollBack();
            $this->logger->error($e->getMessage());
            return false;
        }

        return true;
    }

    public function set_scheduled_at_for_top_priority(string $type, string $key, string $value, int $ts = 0): bool {
$sql = <<<SQL
UPDATE workflow SET scheduled_at = to_timestamp(:ts) WHERE workflow_id IN (
    SELECT workflow_id FROM subscription WHERE
        context_key = :key
        AND context_value = :value
        AND status = 'ACTIVE'
    ) AND type = :type
SQL;

        $stm = $this->doSql($sql, [
            'type' => $type,
            'key' => $key,
            'value' => $value,
            'ts' => $ts
        ]);

        $is_updated = $stm && $stm->rowCount() > 0;
        $this->logger->warn("set_scheduled_at_for_top_priority: $type, $key, $value: ".
            ($is_updated ? 'UPDATED' : 'NOT UPDATED')
        );

        return $is_updated;
    }

    /**
     * @param Event $event
     * @return null|int
     */
    public function create_event(Event $event): ?int
    {

        $sql = <<<SQL
SELECT DISTINCT workflow_id
FROM subscription
WHERE event_type = :type
    AND status = 'ACTIVE'
    AND (context_key = :context_key AND context_value = :context_value)
LIMIT 1000
SQL;

        $insertSql = <<<SQL
INSERT INTO event (type, context, status, workflow_id) VALUES (:type, :context, 'ACTIVE', :workflow_id)
SQL;

        // empty key => value for case "where event_type = :type and context_key is null and context_value is null"
        // if $keyData is empty
        $keyData = array_merge([Subscription::EMPTY => Subscription::EMPTY], $event->get_key_data());

        try {
            $countEvents = 0;
            foreach ($keyData as $context_key => $context_value) {
                $statement = $this->doSql($sql, [
                    'type' => $event->get_type(),
                    'context_key' => $context_key,
                    'context_value' => $context_value,
                ]);

                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $workflow_id = $r['workflow_id'];
                    $stm = $this->doSql($insertSql, [
                        'type' => $event->get_type(),
                        'context' => $event->getContext(),
                        'workflow_id' => $workflow_id
                    ]);

                    if($stm->rowCount() > 0) {
                        $this->eventsQueue->push(new RedisEvent($workflow_id));
                    }
                }

                $countEvents += $statement->rowCount();
            }

            if ($countEvents === 0) {
                $sql = <<<SQL
INSERT INTO event (type, status, context, workflow_id) VALUES (:type, 'NOSUBSCR', :context, 0)
SQL;

                $this->doSql($sql, [
                    'type' => $event->get_type(),
                    'context' => $event->getContext()
                ]);
            }
        } catch (Exception $e) {
            $this->logToStderr($e);
            $this->logger->error($e->getMessage());
            return null;
        }

        return $countEvents;

    }

    /**
     * Returns the array with arrays of workflow IDs grouped by type
     * @return array<int|string, mixed>
     * @throws JsonException
     */
    public function get_active_workflow_by_type(int $limit = 10): array
    {
        /** @noinspection SqlConstantCondition */
        $sql = <<<SQL
SELECT type, array_to_json(wf_list[1: :limit]) wf_list FROM (
    SELECT aa.type, c.priority, array_agg(workflow_id) wf_list
    FROM (
        SELECT workflow_id, type
        FROM (
            SELECT DISTINCT wf.workflow_id, wf.type, wf.scheduled_at
            FROM workflow wf
            LEFT JOIN event e ON wf.workflow_id = e.workflow_id
            WHERE ((e.status = 'ACTIVE' AND e.created_at <= current_timestamp)
                OR (wf.status = 'ACTIVE' AND wf.scheduled_at <= current_timestamp))
        ) wf ORDER BY scheduled_at
    ) aa LEFT JOIN config c ON aa.type = c.type
    GROUP BY aa.type, priority
    ORDER BY coalesce(priority, :default_priority)
) bb
SQL;

        $statement = $this->doSql($sql, [
            'limit' => $limit,
            'default_priority' => IStorage::DEFAULT_PRIORITY
        ]);

        $result = [];
        while ($row = $statement->fetch()) {
            try {
                $result[$row['type']] = json_decode($row['wf_list'], null, 512, JSON_THROW_ON_ERROR);
            }
            catch (JsonException $e) {
                $this->logger->error($e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Returns the array with IDs of workflows for execution
     * @return int[]|string[]
     * @throws RuntimeException
     */
    public function get_active_workflow_ids($limit = self::TASK_LIST_SIZE_LIMIT): array
    {
        /** @noinspection SqlConstantCondition */
        $sql = <<<SQL
SELECT DISTINCT workflow_id FROM (
    SELECT wf.workflow_id, wf.scheduled_at, random() rnd
    FROM workflow wf
    LEFT JOIN event e ON wf.workflow_id = e.workflow_id
    WHERE ((e.status = 'ACTIVE' AND e.created_at <= current_timestamp)
        OR (wf.status = 'ACTIVE' AND wf.scheduled_at <= current_timestamp))
    ORDER BY wf.scheduled_at, rnd
) wf
LIMIT :limit
SQL;

        $statement = $this->doSql($sql, [
            'limit' => $limit
        ]);

        $column = [];
        while (($workflow_id = $statement->fetchColumn()) > 0) {
            $column[] = $workflow_id;
        }

        return $column;
    }

    /**
     * @return RedisEvent[]
     */
    public function get_scheduled_workflows(int $limit = self::SCHEDULED_TASK_LIST_SIZE_LIMIT): array
    {
        $sql = <<<SQL
-- First part: Select 1000 records per each type with smallest scheduled_at
WITH RankedWorkflows AS (
    SELECT
        workflow_id,
        type,
        scheduled_at,
        ROW_NUMBER() OVER (PARTITION BY type ORDER BY scheduled_at) as rn
    FROM
        workflow
    WHERE
        status = 'ACTIVE'
        AND scheduled_at < current_timestamp + interval '1 minute'
)
SELECT
    workflow_id,
    type,
    EXTRACT(EPOCH FROM scheduled_at) as scheduled_at
FROM
    RankedWorkflows
WHERE
    rn <= :limit
UNION
-- Second part: Select workflows with unhandled events
SELECT
    workflow_id,
    type,
    EXTRACT(EPOCH FROM NOW()) as scheduled_at
FROM
    workflow
WHERE
    workflow_id IN (
        SELECT
            workflow_id
        FROM
            event
        WHERE
            status = 'ACTIVE'
        ORDER BY
            created_at
        LIMIT :limit
    )
SQL;

        $statement = $this->doSql($sql, [
            'limit' => $limit
        ]);

        $result = [];
        while ($row = $statement->fetch()) {
            $result[$row['workflow_id']] = new RedisEvent($row['workflow_id'], $row['type'], (int)$row['scheduled_at']);
        }

        return $result;
    }


    /**
     * @param int $id
     * @param bool $doLock
     *
     * @return Workflow|null
     * @throws Exception
     */
    public function get_workflow(int $id, bool $doLock = true): ?Workflow
    {

        $lockId = $this->get_lock_string();

        $selectSql = 'SELECT type, context, error_count FROM workflow WHERE workflow_id = :id';
        $params = [
            'id' => $id
        ];

        if ($doLock) {
            $sql = <<<SQL
UPDATE workflow SET
    "lock" = :lock_id,
    status = 'INPROGRESS',
    started_at = current_timestamp,
    error_count = error_count + 1
WHERE workflow_id = :workflow_id AND "lock" = ''
SQL;

            $this->doSql($sql, [
                'lock_id' => $lockId,
                'workflow_id' => $id
            ]);

            $selectSql .= ' AND "lock"=:lock_id';
            $params['lock_id'] = $lockId;
        }

        $statement = $this->doSql($selectSql, $params);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!isset($row['type'])) {
            return null;
        }

        $workflow = (new Factory())->new_workflow($row['type']);
        if (!$workflow) {
            return null;
        }

        $workflow->set_state($row['context']);
        $workflow->set_id($id);

        // We finish workflow in case of error_limit reached
        if ($workflow->many_errors((int)$row['error_count'])) {
            $workflow->finish();
        }

        return $workflow;
    }

    /**
     * @return bool
     */
    protected function is_created(): bool
    {
        $structure = file_get_contents($this->db_structure);
        if (!preg_match_all('/CREATE TABLE (\w+)/im', $structure, $match)) {
            throw new LogicException('Database structure not exists');
        }

        try {
            foreach ($match[1] as $tableName) {
                $this->db->query("SELECT 1 FROM $tableName LIMIT 1");
            }
        } catch (Exception $e) {
            $this->logToStderr($e);
            return false;
        }

        return true;
    }

    /**
     * @param string $sql
     * @param array $params
     *
     * @return false|Statement
     * @throws RuntimeException
     */
    protected function doSql(string $sql, array $params, $throwOnError = true)
    {
        $statement = $this->db->prepare($sql);
        $result = $statement->execute($params);

        if($this->isDebug) {
            error_log($sql);
            error_log(json_encode($params));
            if(!$result) {
                error_log('ERROR: '.$statement->errorCode().' '.json_encode($statement->errorInfo()));
            }
        }

        if (!$result && $throwOnError) {
            $error = $statement->errorCode().' '.json_encode($statement->errorInfo());
            throw new RuntimeException("Error: $error\n $sql params:\n" . var_export($params, true));
        }
        return $statement;
    }

    public function save_workflow(Workflow $workflow, $unlock = true): bool
    {
        try {

            $workflow_id = $workflow->get_id();

            $this->db->beginTransaction();

            $sql = 'update workflow set
                context = :context,
                scheduled_at = to_timestamp(:scheduled_at_ts),
                finished_at = current_timestamp,
                "lock" = coalesce(:lock, "lock"),
                status = coalesce(:status, status),
                error_count = error_count - coalesce(:error_count, 0)
                    where workflow_id = :workflow_id
        ';

            /** @noinspection NestedTernaryOperatorInspection */
            $status = $workflow->is_finished()
                ? IStorage::STATUS_FINISHED
                : ($unlock ? IStorage::STATUS_ACTIVE : null);

            $error_decrement = ($unlock && (!$workflow->is_error())) ? 1 : 0;

            $params = [
                'workflow_id' => $workflow_id,
                'context' => $workflow->get_state(),
                'scheduled_at_ts' => $workflow->get_start_time(),
                'lock' => $unlock ? '' : null,
                'status' => $status,
                'error_count' => $error_decrement
            ];

            $this->doSql($sql, $params);

            if ($status === IStorage::STATUS_FINISHED) {

                $sql = <<<SQL
UPDATE event SET finished_at = current_timestamp,
    started_at = current_timestamp,
    status = 'PROCESSED'
WHERE workflow_id = :workflow_id AND status = 'ACTIVE'
SQL;
                $this->doSql($sql, [
                    'workflow_id' => $workflow_id
                ]);

                $sql = <<<SQL
UPDATE subscription SET status = 'FINISHED'
WHERE workflow_id = :workflow_id
SQL;
                $this->doSql($sql, [
                    'workflow_id' => $workflow_id
                ]);

                $sql = <<<SQL
UPDATE uniqueness SET status = NULL
WHERE workflow_id = :workflow_id
SQL;
                $this->doSql($sql, [
                    'workflow_id' => $workflow_id
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->logToStderr($e);
            $this->db->rollBack();
            $this->logger->error($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @param Event $event
     * @return bool
     * @throws RuntimeException
     */
    public function close_event(Event $event): bool
    {
        $sql = <<<SQL
UPDATE event SET status = 'PROCESSED',
    finished_at = current_timestamp,
    started_at = coalesce(:started_at, created_at)
WHERE event_id = :event_id
SQL;

        return (bool)($this->doSql($sql, [
            'event_id' => $event->get_id(),
            'started_at' => $event->getStartedAt()
        ]));
    }

    /**
     * @return void
     * @throws RuntimeException
     */
    protected function update_hosts(): void
    {
        $sql = 'INSERT INTO host ( hostname ) VALUES (:hostname)
                    ON CONFLICT (hostname)
                        DO UPDATE SET updated_at = now()';

        $this->doSql($sql, [
            'hostname' => gethostname()
        ]);

        $this->doSql(
            sprintf("delete from host where updated_at < now() - interval '%d seconds'",
                self::HOST_DELETE_DELAY), []
        );
    }

    /**
     * @return string[]
     * @throws RuntimeException
     */
    protected function get_active_hosts(): array
    {
        $result = $this->doSql("select hostname from host", []);

        $hosts = [];
        while ([$hostname] = $result->fetch(PDO::FETCH_NUM)) {
            $hosts[] = $hostname;
        }
        return $hosts;
    }

    /**
     * Restore workflows with errors during execution
     * @return void
     */
    public function cleanup(): void
    {
        $this->update_hosts();

        $active_hosts = $this->get_active_hosts();

        $sql = <<<SQL
SELECT workflow_id, "lock", context, started_at FROM workflow w LEFT JOIN config c on w.type = c.type WHERE 1=1
    AND status = 'INPROGRESS'
    AND "lock" <> ''
    AND w.started_at <= now() - (coalesce(c.recovery_time, :time_limit) * interval '1 second')
    limit :limit;
SQL;

        $result = $this->doSql($sql, [
            'status' => IStorage::STATUS_IN_PROGRESS,
            'time_limit' => $this->get_execution_time_limit(),
            'limit' => self::TASK_LIST_SIZE_LIMIT
        ]);

        $rows = $result->rowCount();
        if($rows > 0) {
            $this->logger->warn("CLEANUP: $rows workflows stuck");
        }

        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            [$workflow_id, $lock, $context, $started_at] = $row;
            [$host, $pid] = $this->get_host_pid_from_lock_string($lock);
            if (self::process_exists($host, $pid, $active_hosts)) {
                $this->logger->warn("CLEANUP: Workflow $workflow_id - is running for long time");
                continue;
            }

            $sql = <<<SQL
UPDATE workflow SET "lock" = '', status = 'ACTIVE' WHERE workflow_id = :workflow_id
SQL;
            $updRes = $this->doSql($sql, [
                'workflow_id' => $workflow_id
            ]);

            if ($updRes->rowCount() > 0) {
                $this->logRestoredWorkflow($workflow_id, $context, $started_at);
                $this->logger->info("CLEANUP: Workflow $workflow_id restarted");
            } else {
                $this->logger->warn("CLEANUP: Workflow $workflow_id restart failed");
            }
        }
    }

    protected function logRestoredWorkflow(int $workflow_id, string $context, string $started_at): bool
    {
        $sql = <<<SQL
insert into restored_workflow (workflow_id, context, started_at) VALUES (:workflow_id, :context, :started_at)
SQL;

        $insertRes = $this->doSql($sql, [
            'workflow_id' => $workflow_id,
            'context' => $context,
            'started_at' => $started_at
        ], false);

        return $insertRes->rowCount() > 0;
    }

    protected function get_execution_time_limit(): int
    {
        return self::CLEANUP_TIME;
    }

    /**
     * @param int $workflow_id
     * @return Event[]
     * @throws Exception
     */
    public function get_events(int $workflow_id): array
    {
        $sql = <<<SQL
SELECT event_id, type, context, current_timestamp ts
FROM event
WHERE status = 'ACTIVE' AND workflow_id = :workflow_id
ORDER BY created_at
LIMIT 100
SQL;

        $result = $this->doSql($sql, [
            'workflow_id' => $workflow_id
        ]);

        $events = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $e = new Event($row['type'], $row['context']);
            $e->setEventId($row['event_id']);
            $e->setWorkflowId($workflow_id);
            $e->setStartedAt($row['ts']);
            $events[] = $e;
        }

        return $events;
    }

    /**
     * @param string $log_message
     * @param int $workflow_id
     * @return void
     */
    public function store_log(string $log_message, int $workflow_id = 0): void
    {
        $this->doSql('insert into log (workflow_id, log_text, pid, host) values (:workflow_id, :log_text, :pid, :host)', [
            'workflow_id' => $workflow_id,
            'log_text' => $log_message,
            'pid' => getmypid() ?: 0,
            'host' => md5(gethostname())
        ]);
    }


    public function store_log_array(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        $sql = "INSERT INTO log (workflow_id, created_at, log_text, pid, host) VALUES ";

        $params = [];

        foreach ($messages as $index => $message) {
            $sql .= "(:workflow_id{$index}, :created_at{$index}, :log_text{$index}, :pid{$index}, :host{$index}), ";
            $params["workflow_id{$index}"] = $message->workflow_id;
            $params["created_at{$index}"] = $message->created_at;
            $params["log_text{$index}"] = $message->log_text;
            $params["pid{$index}"] = $message->pid;
            $params["host{$index}"] = $message->host;
        }

        $sql = rtrim($sql, ", "); // Remove the trailing comma and space

        $this->doSql($sql, $params);
    }


    /**
     * @param Exception $e
     */
    protected function logToStderr(Exception $e): void
    {
        $error = [
            "category" => "WFPSQL",
            "error" => $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ];
        error_log(json_encode($error));
    }

    /**
     * @param Workflow $workflow
     * @throws RuntimeException
     */
    protected function createUniqueness(Workflow $workflow): bool
    {
        $workflow_id = $workflow->get_id();
        [$key, $value] = $workflow->get_uniqueness();
        $workflowType = $workflow->get_type();
        // Shrink type to 62 symbols
        $workflowType = md5($workflowType) . '_' . substr($workflowType, -30);

        $sql = <<<SQL
insert into uniqueness (workflow_id, type, uni_key, value)
    values (:workflow_id, :type, :key, :value);
SQL;

        try {
            $this->doSql($sql,
                [
                    'workflow_id' => $workflow_id,
                    'type' => $workflowType,
                    'key' => $key,
                    'value' => $value
                ]);
            return true;
        }
        catch (Throwable $e) {
            return false;
        }
    }

}