<?php
//use PhpParser;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassConst;
use Psalm\Aliases;
use Psalm\Checker;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\FileManipulation\FileManipulation;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Union;

/**
 * This is an example plugin to make union types of API methods in an API class depend on a constant within the same class.
 */
class APIFilterPlugin extends \Psalm\Plugin
{
    const METHOD_FILTERS_CONST_NAME = 'METHOD_FILTERS';

    /** @var array<string, APIFilterRecord> maps fully qualified class name to storage and node */
    private $classes_to_check_later = [];

    /**
     * @return void
     */
    public function visitClassLike(
        ClassLike $class_node,
        ClassLikeStorage $storage,
        FileChecker $file_checker,
        Aliases $aliases,
        array &$file_replacements = []
    ) {
        if (isset($storage->public_class_constants[self::METHOD_FILTERS_CONST_NAME])) {
            $method_filters_node = $this->extractMethodFiltersNode($class_node);
            if (!$method_filters_node) {
                return;
            }
            if (!($method_filters_node instanceof PhpParser\Node\Expr\Array_)) {
                return;
            }
            $name = $storage->name;
            $record = new APIFilterRecord($method_filters_node, $storage);
            if (count($record->filters) === 0) {
                // The list of filters is empty
                return;
            }
            $this->classes_to_check_later[$name] = $record;
        }
        return;
    }

    /**
     * @return ?PhpParser\Node (Expected to be PhpParser\Expr\Node\Array_ by convention)
     */
    private function extractMethodFiltersNode(ClassLike $class_node) {
        foreach ($class_node->stmts as $stmt) {
            if (!($stmt instanceof ClassConst)) {
                continue;
            }
            foreach ($stmt->consts as $const_stmt) {
                if ($const_stmt->name === self::METHOD_FILTERS_CONST_NAME) {
                    return $const_stmt->value;
                }
            }
        }
        return;
    }

    /**
     * @return void
     */
    public function beforeAnalyzeFiles(
        ProjectChecker $project_checker
    ) {
        foreach ($this->classes_to_check_later as $record) {
            $this->finishAnalyzing($record->filters, $record->storage, $project_checker);
        }
        $this->classes_to_check_later = [];
    }

    /**
     * @param array<string, array<string,string>> $filters
     * @return void
     */
    private function finishAnalyzing(
        array $filters,
        ClassLikeStorage $storage,
        ProjectChecker $checker
    ) {
        $name = $storage->name;
        // var_export($storage);
        if (!\in_array('baseclass', $storage->parent_classes)) {
            return;
        }
        foreach ($filters as $method_name => $filters_for_method) {
            if (is_string($method_name) && is_array($filters_for_method)) {
                $this->addFiltersForMethod($method_name, $filters_for_method, $storage, $checker);
            }
        }
    }

    /**
     * @param string $method_name
     * @param array<string, string> $filters_for_method
     * @return void
     */
    private function addFiltersForMethod(
        $method_name,
        array $filters_for_method,
        ClassLikeStorage $storage,
        ProjectChecker $checker
    ) {
        $method_name_lc = strtolower($method_name);
        //printf("Looking up %s in %s\n", $method_name_lc, json_encode(array_keys($storage->methods)));
        if (!isset($storage->methods[$method_name_lc])) {
            //printf("Looking up %s not found\n", $method_name_lc);
            // TODO warn
            return;
        }
        $method_storage = $storage->methods[$method_name_lc];
        // Generate a union type based on filters_for_method and use that.
        // Use mixed for unrecognized union types.
        // TODO: Convert abstract filters to union types.
        // var_export($method_storage);
        if (count($method_storage->params) === 0) {
            // TODO: optionally warn if 1 or more parameters are expected
            return;
        }
        $param_storage = $method_storage->params[0];
        $new_union_type = $this->convertFiltersToUnionType($filters_for_method);  // TODO: pass in the projectChecker so that names of filtering methods can be converted to union types.
        // var_export($new_union_type);

        // TODO warn if variadic (unlikely)
        $old_type = $param_storage->type;
        if ($old_type) {
            foreach ($old_type->getTypes() as $type) {
                // If the phpdoc type explicitly contains @param {field:string} $param, don't run this plugin.
                if ($type instanceof ObjectLike) {
                    // TODO: Could warn if field name is inconsistent or if the new type is more permissive?
                    return;
                }
            }
        }
        $param_storage->type = $new_union_type;
        $method_storage->param_types[$param_storage->name] = $new_union_type;
        // TODO: if ($type === null || $type is 'array' or a combination of generic array (i.e. almost always)
        //       (Check if the Type with named array keys (ObjectLike) doesn't exist)
    }

    /**
     * @param array<string|int, string> $filters_for_method
     */
    public function convertFiltersToUnionType(array $filters_for_method) : Union {
        $union_type_properties = array_map(
            /**
             * @param string $union_type_string
             */
            function($union_type_string) : Union {
            if (!is_string($union_type_string)) {
                // TODO: warn
                return new Union([new TMixed()]);
            }
            return Type::parseString($union_type_string);
        }, $filters_for_method);
        return new Union([new ObjectLike($union_type_properties)]);
    }
}

class APIFilterRecord {
    /** @var Array_ parsed node with the API filters */
    public $node;

    /** @var ClassLikeStorage */
    public $storage;

    /**
     * @var array<string, array<string,string>> hopefully
     */
    public $filters;

    public function __construct(Array_ $node, ClassLikeStorage $storage) {
        $this->node = $node;
        $this->storage = $storage;
        $this->filters = $this->extractMethodFilterDefinitions();
    }

    /**
     * @return int|string|float|null
     */
    public function convertNodeToPHPScalar(Node $node) {
        if ($node instanceof Scalar) {
            if ($node instanceof String_) {
                return $node->value;
            }
            if ($node instanceof LNumber) {
                return $node->value;
            }
            if ($node instanceof DNumber) {
                return $node->value;
            }
        }
        return;
    }

    /**
     * @return int|string|float|array|null
     */
    public function convertNodeToPHPLiteral(Node $node) {
        if ($node instanceof Array_) {
            $result = [];
            foreach ($node->items as $item) {
                if (!$item) {
                    // add dummy entry of null? (only applies for list(...), so don't?)
                    return;
                }
                // TODO: Constant lookup
                $key = $item->key;
                $resolvedKey = $key !== null ? $this->convertNodeToPHPScalar($key) : null;

                $correspondingValue = $this->convertNodeToPHPLiteral($item->value);
                // printf("result of convertNode: %s\n", json_encode([$resolvedKey, $correspondingValue]));
                if ($resolvedKey !== null) {
                    if (is_scalar($resolvedKey)) {
                        $result[$resolvedKey] = $correspondingValue;
                    }
                } else {
                    $result[] = $correspondingValue;
                }
            }
            return $result;
        }
        return $this->convertNodeToPHPScalar($node);

        // TODO: Account for constant lookup, concatenations (Not necessary in this plugin)
    }

    /**
     * @return array<string, array<string,string>> hopefully
     */
    public function extractMethodFilterDefinitions() {
        $result = $this->convertNodeToPHPLiteral($this->node);
        if (!is_array($result)) {
            return [];
        }
        return $result;
    }
}


return new APIFilterPlugin;
