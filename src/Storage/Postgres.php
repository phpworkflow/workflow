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

    public const HOST_DELETE_DELAY = 300;

    private static ?IStorage $_storage = null;

    private static string $dsn = '';

    /**
     * @var string
     */
    private string $db_structure;

    /* @var ILogger $logger */
    private ILogger $logger;

    /* @var PDO $db */
    private PDO $db;

    private bool $isDebug;

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

    private static function createInstance(string $dsn): IStorage
    {
        $connection = new PDO($dsn, null, null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return new Postgres($connection);
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
    private function createSubscription(Workflow $workflow, bool $is_new = true): void
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

                $sql =  'INSERT INTO subscription (
                          status, event_type, context_key, context_value, workflow_id)
                    VALUES (:status, :event_type, :context_key, :context_value, :workflow_id)';

                $this->doSql($sql, [
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
     * @return bool
     */
    public function create_workflow(Workflow $workflow, $unique = false): bool
    {
        try {
            $this->db->beginTransaction();

            $sql = 'INSERT INTO workflow (type, context, scheduled_at, finished_at, status)
                VALUES (:type, :context, to_timestamp(:scheduled_at_ts), null, :status)';

            $this->doSql($sql, [
                'type' => $workflow->get_type(),
                'context' => $workflow->get_state(),
                'scheduled_at_ts' => $workflow->get_start_time(),
                'status' => IStorage::STATUS_ACTIVE
            ]);

            $workflow_id = $this->db->lastInsertId('workflow_workflow_id_seq');
            $workflow->set_id($workflow_id);

            if ($unique && !$this->createUniqueness($workflow)) {
                $this->db->rollBack();
                return false;
            }

            $this->createSubscription($workflow);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->logToStderr($e);
            $this->db->rollBack();
            $this->logger->error($e->getMessage());
            return false;
        }

        $this->eventsQueue->push(new RedisEvent($workflow_id));

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

            $sql = 'update event set finished_at = current_timestamp,
                 status = :status,
                 started_at = current_timestamp
                where workflow_id = :workflow_id and status = :status_active';

            $this->doSql($sql, [
                'workflow_id' => $workflow_id,
                'status' => IStorage::STATUS_PROCESSED,
                'status_active' => IStorage::STATUS_ACTIVE
            ]);

            $sql = 'update subscription set status = :status
                where workflow_id = :workflow_id';

            $this->doSql($sql, [
                'workflow_id' => $workflow_id,
                'status' => IStorage::STATUS_FINISHED
            ]);

            $sql = 'update workflow set
                    finished_at = current_timestamp,
                    status = :status,
                    "lock" = :lock
                where workflow_id = :workflow_id';

            $this->doSql($sql, [
                'workflow_id' => $workflow_id,
                'status' => IStorage::STATUS_FINISHED,
                'lock' => ''
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

update workflow set scheduled_at = to_timestamp(:ts) where workflow_id in (
    select workflow_id from subscription where
                                             context_key = :key
                                           and context_value = :value
                                           and status = :status
    ) and type = :type;
SQL;

        $stm = $this->doSql($sql, [
            'type' => $type,
            'status' => IStorage::STATUS_ACTIVE,
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

        // cast(:type as text) type, :context, :event_status,
        $sql = "select distinct workflow_id
                    from subscription
                        where event_type = :type
                            and status = :status
                            and (context_key = :context_key and context_value = :context_value)
                limit 1000
        ";

        $insertSql = 'insert into event (type, context, status, workflow_id) values (:type, :context, :status, :workflow_id)';

        // empty key => value for case "where event_type = :type and context_key is null and context_value is null"
        // if $keyData is empty
        $keyData = array_merge([Subscription::EMPTY => Subscription::EMPTY], $event->get_key_data());

        try {
            $countEvents = 0;
            foreach ($keyData as $context_key => $context_value) {
                $statement = $this->doSql($sql, [
                    'type' => $event->get_type(),
                    'status' => IStorage::STATUS_ACTIVE,
                    'context_key' => $context_key,
                    'context_value' => $context_value,
                ]);

                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $workflow_id = $r['workflow_id'];
                    $stm = $this->doSql($insertSql, [
                        'type' => $event->get_type(),
                        'context' => $event->getContext(),
                        'status' => IStorage::STATUS_ACTIVE,
                        'workflow_id' => $workflow_id
                    ]);

                    if($stm->rowCount() > 0) {
                        $this->eventsQueue->push(new RedisEvent($workflow_id));
                    }
                }

                $countEvents += $statement->rowCount();
            }

            if ($countEvents === 0) {
                $sql = 'insert into event (type, status, context, workflow_id) values (:type, :status, :context, 0)';

                $this->doSql($sql, [
                    'type' => $event->get_type(),
                    'status' => IStorage::STATUS_NO_SUBSCRIBERS,
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
select type, array_to_json(wf_list[1: :limit]) wf_list from (
       select type, array_agg(workflow_id) wf_list
       from (
                select workflow_id,
                       type
                from (select distinct wf.workflow_id, wf.type, wf.scheduled_at
                      from workflow wf
                               left join
                           event e on wf.workflow_id = e.workflow_id
                      where ((e.status = :status and e.created_at <= current_timestamp)
                          or
                             (wf.status = :status and wf.scheduled_at <= current_timestamp))
                     ) wf order by scheduled_at
            ) aa
       group by type
   ) bb;
SQL;

        $statement = $this->doSql($sql, [
            'status' => IStorage::STATUS_ACTIVE,
            'limit' => $limit
        ]);

        $result = [];
        while ($row = $statement->fetch()) {
            $result[$row['type']] = json_decode($row['wf_list'], null, 512, JSON_THROW_ON_ERROR);
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
        $sql = 'select distinct workflow_id from ( select wf.workflow_id, wf.scheduled_at, random() rnd
            from workflow wf left join
                event e on wf.workflow_id = e.workflow_id
            where ((e.status = :status and e.created_at <= current_timestamp)
                or (wf.status = :status and wf.scheduled_at <= current_timestamp))
            order by wf.scheduled_at, rnd ) wf
                limit :limit';

        $statement = $this->doSql($sql, [
            'status' => IStorage::STATUS_ACTIVE,
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
    public function get_scheduled_workflows(): array
    {
        $sql = <<<SQL
select * from (
select workflow_id, type, now() scheduled_at from workflow where workflow_id in (
            select workflow_id from event where status = :event_status order by created_at limit 1000
)
union
select workflow_id, type, scheduled_at from workflow
    where status = :status and scheduled_at < current_timestamp + interval '5 minute' order by scheduled_at
    limit 10000
) a;
SQL;

        $statement = $this->doSql($sql, [
            'event_status' => IStorage::STATUS_ACTIVE,
            'status' => IStorage::STATUS_ACTIVE
        ]);

        $result = [];
        while ($row = $statement->fetch()) {
            $result[$row['workflow_id']] = new RedisEvent($row['workflow_id'], $row['type'], $row['scheduled_at']);
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
            $sql = 'UPDATE workflow SET
                "lock" = :lock_id,
                status = :status,
                started_at = current_timestamp,
                error_count=error_count+1
            WHERE workflow_id = :workflow_id AND "lock" = :lock';

            $this->doSql($sql, [
                'lock_id' => $lockId,
                'status' => IStorage::STATUS_IN_PROGRESS,
                'workflow_id' => $id,
                'lock' => ''
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
    private function doSql(string $sql, array $params, $throwOnError = true)
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

                $this->doSql(
                    "update event set finished_at = current_timestamp,
                            started_at = current_timestamp,
                            status = :status
                                where workflow_id = :workflow_id
                                and status = :status_active",
                    [
                        'workflow_id' => $workflow_id,
                        'status' => IStorage::STATUS_PROCESSED,
                        'status_active' => IStorage::STATUS_ACTIVE
                    ]);

                $this->doSql('update subscription set status = :status
                    where workflow_id = :workflow_id', [
                        'workflow_id' => $workflow_id,
                        'status' => IStorage::STATUS_FINISHED
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
        $sql = 'UPDATE event set status = :status,
                 finished_at = current_timestamp,
                 started_at = coalesce(:started_at, created_at)
              WHERE event_id = :event_id';

        return (bool)($this->doSql($sql, [
            'event_id' => $event->get_id(),
            'status' => self::STATUS_PROCESSED,
            'started_at' => $event->getStartedAt()
        ]));
    }

    /**
     * @return void
     * @throws RuntimeException
     */
    private function update_hosts(): void
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
    private function get_active_hosts(): array
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

        $sql = 'select workflow_id, "lock", context, started_at from workflow where
                    status = :status
                    and "lock" <> \'\'
                    and EXTRACT(epoch FROM (current_timestamp - started_at)) > :time_limit
                    limit :limit';

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

            $updRes = $this->doSql('update workflow set "lock" = :lock, status=:status WHERE workflow_id = :workflow_id', [
                'lock' => '',
                'status' => IStorage::STATUS_ACTIVE,
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
        $sql = "select event_id, type, context, current_timestamp ts from event where
                status = :status
                and workflow_id = :workflow_id
                    order by created_at
                    limit 100;
                ";

        $result = $this->doSql($sql, [
            'status' => IStorage::STATUS_ACTIVE,
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
        // event_type - length 64 char
        $workflowType = md5($workflowType) . '_' . substr($workflowType, -30);

        $sql = <<<SQL
insert into subscription (workflow_id, status, event_type, context_key, context_value)
    select coalesce(s.workflow_id, :workflow_id),
           df.status,
           coalesce(s.event_type, df.event_type),
           coalesce(s.context_key, df.context_key),
           coalesce(s.context_value, df.context_value)
        from (select :event_type event_type,
                     :context_key context_key,
                     :context_value context_value,
                     :status_active status
                     ) df
            left join subscription s on
                df.status = s.status
                and df.event_type = s.event_type
                and df.context_key = s.context_key
                and df.context_value = s.context_value;
SQL;

        try {
            $this->doSql($sql,
                [
                    'workflow_id' => $workflow_id,
                    'status_active' => IStorage::STATUS_ACTIVE,
                    'event_type' => $workflowType,
                    'context_key' => $key,
                    'context_value' => $value
                ]);
            return true;
        }
        catch (Throwable $e) {
            return false;
        }
    }

}