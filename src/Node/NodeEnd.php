<?php
namespace Workflow\Node;
use Workflow\Workflow;

class NodeEnd extends Base {
    const PRIORITY=1;
    const NODE_PREFIX='end';

    public function __construct(array $parameters=[]) {

        if(empty($parameters)) {
            $parameters=[
                INode::P_ID => self::LAST_NODE,
                INode::P_NAME => self::LAST_NODE,
                INode::P_NEXT_NODE => self::LAST_NODE
            ];
        }

        parent::__construct($parameters);
    }

    public function execute(Workflow $wf) {
        return self::LAST_NODE;
    }

    public static function get_type_by_name($node_name) {

        if(strcmp(self::NODE_PREFIX,$node_name) === 0) {
            return static::class;
        }

        return false;
    }

}