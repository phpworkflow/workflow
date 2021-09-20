<?php
namespace Workflow\Node;

use Exception;
use Workflow\Workflow;

class NodeAction extends Base {
    const PRIORITY=0;

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

    public function execute(Workflow $wf) {
        $method = $this->method;
        $wf->$method($this->params);

        return parent::execute($wf);
    }

    public static function get_type_by_name($node_name) {
        return static::class;
    }

    public static function fix_node(Workflow $workflow, array &$node) {

        if (!isset($node[self::P_METHOD]) ) {
            $node[self::P_METHOD] = $node[self::P_NAME];
        }

        if(!method_exists($workflow, $node[INode::P_METHOD])) {
            throw new Exception("Method does not exists: " . print_r($node, true));
        }

    }
}