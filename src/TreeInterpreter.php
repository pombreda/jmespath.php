<?php
namespace JmesPath;

/**
 * Tree visitor used to evaluates JMESPath AST expressions.
 */
class TreeInterpreter
{
    /** @var callable */
    private $fnDispatcher;

    /**
     * @param callable $fnDispatcher Function dispatching function that accepts
     *                               a function name argument and an array of
     *                               function arguments and returns the result.
     */
    public function __construct(callable $fnDispatcher = null)
    {
        $this->fnDispatcher = $fnDispatcher ?: FnDispatcher::getInstance();
    }

    /**
     * Visits each node in a JMESPath AST and returns the evaluated result.
     *
     * @param array $node JMESPath AST node
     * @param mixed $data Data to evaluate
     *
     * @return mixed
     */
    public function visit(array $node, $data)
    {
        return $this->dispatch($node, $data);
    }

    /**
     * Recursively traverses an AST using depth-first, pre-order traversal.
     * The evaluation logic for each node type is embedded into a large switch
     * statement to avoid the cost of "double dispatch".
     */
    private function dispatch(array $node, $value)
    {
        $dispatcher = $this->fnDispatcher;

        switch ($node['type']) {

            case 'field':
                if (is_array($value) || $value instanceof \ArrayAccess) {
                    return isset($value[$node['value']]) ? $value[$node['value']] : null;
                } elseif ($value instanceof \stdClass) {
                    return isset($value->{$node['value']}) ? $value->{$node['value']} : null;
                }
                return null;

            case 'subexpression':
                return $this->dispatch(
                    $node['children'][1],
                    $this->dispatch($node['children'][0], $value)
                );

            case 'index':
                if (!Utils::isArray($value)) {
                    return null;
                }
                $idx = $node['value'] >= 0
                    ? $node['value']
                    : $node['value'] + count($value);
                return isset($value[$idx]) ? $value[$idx] : null;

            case 'projection':
                $left = $this->dispatch($node['children'][0], $value);
                switch ($node['from']) {
                    case 'object':
                        if (!Utils::isObject($left)) {
                            return null;
                        }
                        break;
                    case 'array':
                        if (!Utils::isArray($left)) {
                            return null;
                        }
                        break;
                    default:
                        if (!is_array($left) || !($left instanceof \stdClass)) {
                            return null;
                        }
                }

                $collected = [];
                foreach ((array) $left as $val) {
                    $result = $this->dispatch($node['children'][1], $val);
                    if ($result !== null) {
                        $collected[] = $result;
                    }
                }

                return $collected;

            case 'flatten':
                static $skipElement = [];
                $value = $this->dispatch($node['children'][0], $value);

                if (!Utils::isArray($value)) {
                    return null;
                }

                $merged = [];
                foreach ($value as $values) {
                    // Only merge up arrays lists and not hashes
                    if (is_array($values) && isset($values[0])) {
                        $merged = array_merge($merged, $values);
                    } elseif ($values !== $skipElement) {
                        $merged[] = $values;
                    }
                }

                return $merged;

            case 'literal':
                return $node['value'];

            case 'current':
                return $value;

            case 'or':
                $result = $this->dispatch($node['children'][0], $value);
                if (!$result && $result !== '0' && $result !== 0) {
                    $result = $this->dispatch($node['children'][1], $value);
                }

                return $result;

            case 'pipe':
                return $this->dispatch(
                    $node['children'][1],
                    $this->dispatch($node['children'][0], $value)
                );

            case 'multi_select_list':
                if ($value === null) {
                    return null;
                }

                $collected = [];
                foreach ($node['children'] as $node) {
                    $collected[] = $this->dispatch($node, $value);
                }

                return $collected;

            case 'multi_select_hash':
                if ($value === null) {
                    return null;
                }

                $collected = [];
                foreach ($node['children'] as $node) {
                    $collected[$node['value']] = $this->dispatch(
                        $node['children'][0],
                        $value
                    );
                }

                return $collected;

            case 'comparator':
                $left = $this->dispatch($node['children'][0], $value);
                $right = $this->dispatch($node['children'][1], $value);
                if ($node['value'] == '==') {
                    return Utils::valueCmp($left, $right);
                } elseif ($node['value'] == '!=') {
                    return !Utils::valueCmp($left, $right);
                } else {
                    return self::relativeCmp($left, $right, $node['value']);
                }

            case 'condition':
                return true === $this->dispatch($node['children'][0], $value)
                    ? $this->dispatch($node['children'][1], $value)
                    : null;

            case 'function':
                $args = [];
                foreach ($node['children'] as $arg) {
                    $args[] = $this->dispatch($arg, $value);
                }
                return $dispatcher($node['value'], $args);

            case 'slice':
                return $dispatcher('slice', [
                    $value,
                    $node['value'][0],
                    $node['value'][1],
                    $node['value'][2],
                ]);

            case 'expref':
                $apply = $node['children'][0];
                return function ($value) use ($apply) {
                    return $this->visit($apply, $value);
                };

            default:
                throw new \RuntimeException("Unknown node type: {$node['type']}");
        }
    }

    private static function relativeCmp($left, $right, $cmp)
    {
        if (!is_int($left) || !is_int($right)) {
            return false;
        }

        switch ($cmp) {
            case '>': return $left > $right;
            case '>=': return $left >= $right;
            case '<': return $left < $right;
            case '<=': return $left <= $right;
            default: throw new \RuntimeException("Invalid comparison: $cmp");
        }
    }
}