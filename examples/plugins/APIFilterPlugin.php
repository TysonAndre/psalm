<?php
namespace Psalm\Example\Plugin;
use PhpParser;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassConst;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\FileManipulation;
use Psalm\FileSource;
use Psalm\Example\Plugin\lib\APIFilterRecord;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\Internal\Scanner\FileScanner;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\Plugin\Hook\BeforeAnalyzeFilesInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Union;

require_once __DIR__ . '/lib/APIFilterRecord.php';

/**
 * This is an example plugin to make union types of API methods in an API class depend on a constant within the same class.
 *
 * TODO: This does not work as expected after refactoring class like storage cache for psalm 3.0.0
 * (integration test fails)
 */
class APIFilterPlugin implements
    AfterClassLikeVisitInterface,
    BeforeAnalyzeFilesInterface
{
    const METHOD_FILTERS_CONST_NAME = 'METHOD_FILTERS';

    /** @var array<string, APIFilterRecord> maps fully qualified class name to storage and node */
    private static $classes_to_check_later = [];

    /**
     * @return void
     * @param  FileManipulation[] $file_replacements
     * @override
     */
    public static function afterClassLikeVisit(
        ClassLike $stmt,
        ClassLikeStorage $storage,
        FileSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = []
    ) {
        // var_export($storage);
        if (isset($storage->public_class_constants[self::METHOD_FILTERS_CONST_NAME])) {
            $method_filters_node = self::extractMethodFiltersNode($stmt);
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
            self::$classes_to_check_later[$name] = $record;
        }
        return;
    }

    /**
     * @return ?PhpParser\Node (Expected to be PhpParser\Expr\Node\Array_ by convention)
     */
    private static function extractMethodFiltersNode(ClassLike $stmt) {
        foreach ($stmt->stmts as $stmt) {
            if (!($stmt instanceof ClassConst)) {
                continue;
            }
            foreach ($stmt->consts as $const_stmt) {
                if ($const_stmt->name->name === self::METHOD_FILTERS_CONST_NAME) {
                    return $const_stmt->value;
                }
            }
        }
        return null;
    }

    /**
     * @return void
     * @override
     */
    public static function beforeAnalyzeFiles(
        ProjectAnalyzer $project_analyzer
    ) {
        foreach (self::$classes_to_check_later as $record) {
            self::finishAnalyzing($record->filters, $record->storage, $project_analyzer);
        }
        self::$classes_to_check_later = [];
    }

    /**
     * @param array<string, array<string,string>> $filters
     * @return void
     */
    private static function finishAnalyzing(
        array $filters,
        ClassLikeStorage $storage,
        ProjectAnalyzer $analyzer
    ) {
        if (!\in_array('baseclass', $storage->parent_classes)) {
            return;
        }
        foreach ($filters as $method_name => $filters_for_method) {
            if (is_array($filters_for_method)) {
                self::addFiltersForMethod($method_name, $filters_for_method, $storage, $analyzer);
            }
        }
    }

    /**
     * @param string $method_name
     * @param array<string, string> $filters_for_method
     * @return void
     */
    private static function addFiltersForMethod(
        $method_name,
        array $filters_for_method,
        ClassLikeStorage $storage,
        ProjectAnalyzer $analyzer
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
        $new_union_type = self::convertFiltersToUnionType($filters_for_method);  // TODO: pass in the projectChecker so that names of filtering methods can be converted to union types.
        // var_export($new_union_type);

        // TODO warn if variadic (unlikely)
        $old_type = $param_storage->type;
        if ($old_type) {
            foreach ($old_type->getAtomicTypes() as $type) {
                // If the phpdoc type explicitly contains @param {field:string} $param, don't run this plugin.
                if ($type instanceof TKeyedArray) {
                    // TODO: Could warn if field name is inconsistent or if the new type is more permissive?
                    return;
                }
            }
        }
        $param_storage->type = $new_union_type;
        $method_storage->param_types[$param_storage->name] = $new_union_type;
        // TODO: if ($type === null || $type is 'array' or a combination of generic array (i.e. almost always)
        //       (Check if the Type with named array keys (TKeyedArray) doesn't exist)
    }

    /**
     * @param array<string|int, string> $filters_for_method
     */
    public static function convertFiltersToUnionType(array $filters_for_method) : Union {
        $union_type_properties = array_map(
            /**
             * @param string $union_type_string
             * @psalm-suppress RedundantConditionGivenDocblockType
             * @psalm-suppress DocblockTypeContradiction
             */
            function($union_type_string) : Union {
                if (!is_string($union_type_string)) {
                    // TODO: warn
                    return new Union([new TMixed()]);
                }
                return Type::parseString($union_type_string);
            },
            $filters_for_method
        );
        return new Union([new TKeyedArray($union_type_properties)]);
    }
}

return new APIFilterPlugin;
