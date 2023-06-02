<?php
namespace Workflow\Node;

use Exception;
use Workflow\Workflow;

class NodeSelector extends NodeAction {
    public const PRIORITY=1;
    public const NODE_PREFIX="if_";

    protected $then_id;
    protected $else_id;
    protected bool $has_not=false;

    public function __construct(array &$parameters) {
        parent::__construct($parameters);

        if(count($parameters[INode::P_THEN] ?? [])>0) {
            $first_sub_node=$parameters[INode::P_THEN][0];
            $this->then_id=$first_sub_node[INode::P_ID];
        }
        else {
            $this->then_id=$this->next_node_id;
        }

        if(count($parameters[INode::P_ELSE] ?? [])>0) {
            $first_sub_node=$parameters[INode::P_ELSE][0];
            $this->else_id=$first_sub_node[INode::P_ID];
        }
        else {
            $this->else_id=$this->next_node_id;
        }

        if(isset($parameters[self::OP_NOT])) {
            $this->has_not=true;
        }
    }

    /**
     * @param Workflow $workflow
     * @return int
     */
    public function execute(Workflow $workflow): int {
        $method = $this->method;
        $result = empty($method) ? null : $workflow->$method($this->params);

        $next_id=$result ? $this->then_id : $this->else_id;
        // We invert result in case if "!" is used. We need this for right logging
        if($this->has_not) {
            $result=!$result;
        }
        $workflow->get_logger()->debug("Selector result ".
            ($result ? "TRUE":"FALSE")." -> ".($result ? "THEN":"ELSE").
        " next node: ".$workflow->get_node_name_by_id($next_id));

        return $next_id;
    }

    /**
     * @param string $node_name
     * @return null|string
     */
    public static function get_type_by_name(string $node_name): ?string {

        $pattern='/^!*'.static::NODE_PREFIX.'.+/i';

        if(preg_match($pattern, $node_name)) {
            return static::class;
        }

        return null;
    }


    public static function fix_node(Workflow $workflow, array &$node): void {

        // Set right THEN, ELSE attributes for NOT operator in selector

        if ( !isset($node[INode::P_THEN])) {
            throw new Exception("Selector does not have 'then' attribute: " . print_r($node, true));
        }

        $has_not=substr($node[self::P_NAME], 0, 1) == self::OP_NOT;

        if($has_not) {
            $node[self::OP_NOT]=true;
            $node[self::P_NAME]=substr($node[self::P_NAME], 1);
        }

        if (!isset($node[self::P_ELSE])) {
            $node[self::P_ELSE] = [];
        }

        if($has_not) {
            $tmp = $node[self::P_ELSE];
            $node[self::P_ELSE] = $node[self::P_THEN];
            $node[self::P_THEN] = $tmp;
        }

        parent::fix_node($workflow, $node);
    }

}