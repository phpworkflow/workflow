<?php
namespace Workflow\Node;

use DateTime;
use DateInterval;
use Exception;
use Workflow\Workflow;

class NodeWait extends Base {
    const PRIORITY=1;

    const NODE_PREFIX="wait_";

    const TIME_PATTERN='/(\d{1,2})\:(\d{1,2})/';

    /**
     * @var int
     */
    protected $timeout=0;

    /**
     * NodeWait constructor.
     * @param array $parameters
     */
    public function __construct(array &$parameters) {
        parent::__construct($parameters);

        $this->timeout=$parameters[INode::P_TIMEOUT];
    }

    /**
     * @param Workflow $wf
     * @return int
     */
    public function execute(Workflow $wf) {
        $wf->set_exec_time($wf->now() + $this->timeout);
        return $this->next_node_id;
    }

    /**
     * @param Workflow $workflow
     * @param array $node
     *
     * @throws Exception
     */
    public static function fix_node(Workflow $workflow, array &$node) {

        $node[self::P_TIMEOUT] = isset($node[self::P_TIME])
            ? self::getTimeoutByTime($node[self::P_TIME])
            : ($node[self::P_TIMEOUT] ?? 0);

        if($node[self::P_TIMEOUT] === 0) {
            $node[self::P_TIMEOUT]=self::get_timeout_by_name($node[self::P_NAME]);
        }

        if($node[self::P_TIMEOUT] === 0) {
            throw new Exception("Wrong timeout value for node: " . print_r($node, true));
        }
    }

    /**
     * @param string $time
     * @return int
     */
    private static function getTimeoutByTime(string $time):int {

        if(!preg_match(self::TIME_PATTERN, $time, $match)) {
            return 0;
        }

        $dt=new DateTime();
        $currentTS = $dt->getTimestamp();
        $h=$match[1];
        $m=$match[2];
        $dt->setTime($h,$m,0);

        if($dt->getTimestamp() > $currentTS) {
            return $dt->getTimestamp() - $currentTS;
        }

        $dt->add(new DateInterval('P1D'));

        return $dt->getTimestamp() - $currentTS;
    }

    /**
     * @param $node_name
     * @return int
     */
    private static function get_timeout_by_name($node_name) {
        // TODO implement this
        return 1;
    }

}