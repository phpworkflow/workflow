<?php
namespace Workflow\Node;

use Exception;
use Workflow\Workflow;

abstract class Base implements INode
{
    const PRIORITY = -1;
    const NODE_PREFIX = "-----";

    protected $id;
    protected $name;
    protected $next_node_id = self::LAST_NODE;
    protected $node_id_to_go;

    public function __construct(array $parameters)
    {
        $this->id = $parameters[INode::P_ID];
        $this->name = $parameters[INode::P_NAME];
        $this->next_node_id = $parameters[INode::P_NEXT_NODE];
    }

    public function set_node_id_to_go($node_id) {
        $this->node_id_to_go = $node_id;
    }

    public function execute(Workflow $wf)
    {
        if($this->node_id_to_go) {
            $node_id = $this->node_id_to_go;
            $this->node_id_to_go = null;
            return $node_id;
        }
        return $this->next_node_id;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function get_id()
    {
        return $this->id;
    }

    public static function get_priority()
    {
        return static::PRIORITY;
    }

    public static function get_type_by_name($node_name)
    {

        $pattern = '/^' . static::NODE_PREFIX . '.+/i';

        if (preg_match($pattern, $node_name)) {
            return static::class;
        }

        return false;
    }

    /**
     * Create node attributes by node name if don't exist
     *
     * @param array $node
     */
    public static function fix_node(Workflow $workflow, array &$node)
    {
    }

    // TODO make dependence between types of nodes, classes and move validation to the node class
    /*
     * @method _node_factory
     * @return Node\INode $result
     */

    public static function node_factory(array $node)
    {

        $class_name = $node[INode::P_TYPE];

        if (!class_exists($class_name)) {
            throw new Exception("Unknown type of node: " . $node[INode::P_TYPE] . " class $class_name");
        }

        return new $class_name($node);
    }

    public function __toString()
    {
        return "$this->id $this->name";
    }
}