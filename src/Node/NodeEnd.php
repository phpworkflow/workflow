<?php
namespace Workflow\Node;
use Workflow\Workflow;

class NodeEnd extends Base {
    public const PRIORITY=1;
    public const NODE_PREFIX='end';

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

    /**
     * @param Workflow $workflow
     * @return int
     */
    public function execute(Workflow $workflow): int {
        return self::LAST_NODE;
    }

    /**
     * @param $node_name
     * @return string|null
     */
    public static function get_type_by_name($node_name): ?string {

        if(strcmp(self::NODE_PREFIX,$node_name) === 0) {
            return static::class;
        }

        return null;
    }

}