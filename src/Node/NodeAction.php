<?php
namespace Workflow\Node;

use Exception;
use Workflow\Workflow;

class NodeAction extends Base {
    public const PRIORITY=0;

    protected $method;
    protected $params=[];

    public function __construct(array &$parameters) {
        parent::__construct($parameters);
        // Optional
        if(isset($parameters[INode::P_PARAMS])) {
            $this->params=$parameters[INode::P_PARAMS];
        }

        $this->method=$parameters[INode::P_METHOD];
    }

    /**
     * @param Workflow $workflow
     * @return int
     */
    public function execute(Workflow $workflow): int {
        $method = $this->method;
        $workflow->$method($this->params);

        return parent::execute($workflow);
    }

    /**
     * @param string $node_name
     * @return string
     */
    public static function get_type_by_name(string $node_name): ?string {
        return static::class;
    }

    /**
     * @param Workflow $workflow
     * @param array $node
     * @return void
     * @throws Exception
     */
    public static function fix_node(Workflow $workflow, array &$node): void {

        if (!isset($node[self::P_METHOD]) ) {
            $node[self::P_METHOD] = $node[self::P_NAME];
        }

        if(!method_exists($workflow, $node[INode::P_METHOD])) {
            throw new Exception("Method does not exists: " . print_r($node, true));
        }

    }
}