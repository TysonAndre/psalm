<?php
namespace Psalm\Checker;

use PhpParser;
use Psalm\Checker\Statements\Expression\AssertionFinder;
use Psalm\CodeLocation;
use Psalm\FunctionLikeParameter;
use Psalm\Issue\InvalidReturnType;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Reconciler;

class FunctionChecker extends FunctionLikeChecker
{
    /**
     * @var array<array<string,string>>|null
     */
    protected static $call_map = null;

    /**
     * @param StatementsSource              $source
     */
    public function __construct(PhpParser\Node\Stmt\Function_ $function, StatementsSource $source)
    {
        parent::__construct($function, $source);
    }

    /**
     * @param  string $function_id
     * @param  string $file_path
     *
     * @return bool
     */
    public static function isVariadic(ProjectChecker $project_checker, $function_id, $file_path)
    {
        $file_storage = $project_checker->file_storage_provider->get($file_path);

        return isset($file_storage->functions[$function_id]) && $file_storage->functions[$function_id]->variadic;
    }

    /**
     * @param  string $function_id
     *
     * @return array|null
     * @psalm-return array<int, array<int, FunctionLikeParameter>>|null
     */
    public static function getParamsFromCallMap($function_id)
    {
        $call_map = self::getCallMap();

        $call_map_key = strtolower($function_id);

        if (!isset($call_map[$call_map_key])) {
            return null;
        }

        $call_map_functions = [];
        $call_map_functions[] = $call_map[$call_map_key];

        for ($i = 1; $i < 10; ++$i) {
            if (!isset($call_map[$call_map_key . '\'' . $i])) {
                break;
            }

            $call_map_functions[] = $call_map[$call_map_key . '\'' . $i];
        }

        $function_type_options = [];

        foreach ($call_map_functions as $call_map_function_args) {
            array_shift($call_map_function_args);

            $function_types = [];

            /** @var string $arg_name - key type changed with above array_shift */
            foreach ($call_map_function_args as $arg_name => $arg_type) {
                $by_reference = false;
                $optional = false;
                $variadic = false;

                if ($arg_name[0] === '&') {
                    $arg_name = substr($arg_name, 1);
                    $by_reference = true;
                }

                if (substr($arg_name, -1) === '=') {
                    $arg_name = substr($arg_name, 0, -1);
                    $optional = true;
                }

                if (substr($arg_name, 0, 3) === '...') {
                    $arg_name = substr($arg_name, 3);
                    $variadic = true;
                }

                $param_type = $arg_type
                    ? Type::parseString($arg_type)
                    : Type::getMixed();

                if ($param_type->hasScalarType() || $param_type->hasObject()) {
                    $param_type->from_docblock = true;
                }

                $function_types[] = new FunctionLikeParameter(
                    $arg_name,
                    $by_reference,
                    $param_type,
                    null,
                    null,
                    $optional,
                    false,
                    $variadic
                );
            }

            $function_type_options[] = $function_types;
        }

        return $function_type_options;
    }

    /**
     * @param  string  $function_id
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMap($function_id)
    {
        $call_map_key = strtolower($function_id);

        $call_map = self::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        return Type::parseString($call_map[$call_map_key][0]);
    }

    /**
     * @param  string                      $function_id
     * @param  array<PhpParser\Node\Arg>   $call_args
     * @param  CodeLocation                $code_location
     * @param  array                       $suppressed_issues
     *
     * @return Type\Union
     */
    public static function getReturnTypeFromCallMapWithArgs(
        StatementsChecker $statements_checker,
        $function_id,
        array $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $call_map_key = strtolower($function_id);

        $call_map = self::getCallMap();

        if (!isset($call_map[$call_map_key])) {
            throw new \InvalidArgumentException('Function ' . $function_id . ' was not found in callmap');
        }

        if ($call_map_key === 'getenv') {
            if (!empty($call_args)) {
                return new Type\Union([new Type\Atomic\TString, new Type\Atomic\TFalse]);
            }

            return new Type\Union([new Type\Atomic\TArray([Type::getMixed(), Type::getString()])]);
        }

        if ($call_args) {
            if (in_array($call_map_key, ['str_replace', 'preg_replace', 'preg_replace_callback'], true)) {
                if (isset($call_args[2]->value->inferredType)) {

                    /** @var Type\Union */
                    $subject_type = $call_args[2]->value->inferredType;

                    if (!$subject_type->hasString() && $subject_type->hasArray()) {
                        return Type::getArray();
                    }

                    return Type::getString();
                }
            }

            if (in_array($call_map_key, ['pathinfo'], true)) {
                if (isset($call_args[1])) {
                    return Type::getString();
                }

                return Type::getArray();
            }

            if (substr($call_map_key, 0, 6) === 'array_') {
                $array_return_type = self::getArrayReturnType(
                    $statements_checker,
                    $call_map_key,
                    $call_args,
                    $code_location,
                    $suppressed_issues
                );

                if ($array_return_type) {
                    return $array_return_type;
                }
            }

            if ($call_map_key === 'explode' || $call_map_key === 'preg_split') {
                return Type::parseString('array<int, string>');
            }

            if ($call_map_key === 'min' || $call_map_key === 'max') {
                if (isset($call_args[0])) {
                    $first_arg = $call_args[0]->value;

                    if (isset($first_arg->inferredType)) {
                        if ($first_arg->inferredType->hasArray()) {
                            $array_type = $first_arg->inferredType->getTypes()['array'];
                            if ($array_type instanceof Type\Atomic\ObjectLike) {
                                return $array_type->getGenericValueType();
                            }

                            if ($array_type instanceof Type\Atomic\TArray) {
                                return clone $array_type->type_params[1];
                            }
                        } elseif ($first_arg->inferredType->hasScalarType() &&
                            ($second_arg = $call_args[1]->value) &&
                            isset($second_arg->inferredType) &&
                            $second_arg->inferredType->hasScalarType()
                        ) {
                            return Type::combineUnionTypes($first_arg->inferredType, $second_arg->inferredType);
                        }
                    }
                }
            }
        }

        if (!$call_map[$call_map_key][0]) {
            return Type::getMixed();
        }

        return Type::parseString($call_map[$call_map_key][0]);
    }

    /**
     * @param  string                       $call_map_key
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     *
     * @return Type\Union|null
     */
    protected static function getArrayReturnType(
        StatementsChecker $statements_checker,
        $call_map_key,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        if ($call_map_key === 'array_map') {
            return self::getArrayMapReturnType(
                $statements_checker,
                $call_args,
                $code_location,
                $suppressed_issues
            );
        }

        if ($call_map_key === 'array_filter') {
            return self::getArrayFilterReturnType(
                $statements_checker,
                $call_args,
                $code_location,
                $suppressed_issues
            );
        }

        $first_arg = isset($call_args[0]->value) ? $call_args[0]->value : null;
        $second_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

        if ($call_map_key === 'array_merge') {
            $inner_value_types = [];
            $inner_key_types = [];

            $generic_properties = [];

            foreach ($call_args as $call_arg) {
                if (!isset($call_arg->value->inferredType)) {
                    return Type::getArray();
                }

                foreach ($call_arg->value->inferredType->getTypes() as $type_part) {
                    if ($call_arg->unpack) {
                        if (!$type_part instanceof Type\Atomic\TArray) {
                            if ($type_part instanceof Type\Atomic\ObjectLike) {
                                $type_part_value_type = $type_part->getGenericValueType();
                            } else {
                                return Type::getArray();
                            }
                        } else {
                            $type_part_value_type = $type_part->type_params[0];
                        }

                        $unpacked_type_parts = [];

                        foreach ($type_part_value_type->getTypes() as $value_type_part) {
                            $unpacked_type_parts[] = $value_type_part;
                        }
                    } else {
                        $unpacked_type_parts = [$type_part];
                    }

                    foreach ($unpacked_type_parts as $unpacked_type_part) {
                        if (!$unpacked_type_part instanceof Type\Atomic\TArray) {
                            if ($unpacked_type_part instanceof Type\Atomic\ObjectLike) {
                                if ($generic_properties !== null) {
                                    $generic_properties = array_merge(
                                        $generic_properties,
                                        $unpacked_type_part->properties
                                    );
                                }

                                $unpacked_type_part = $unpacked_type_part->getGenericArrayType();
                            } else {
                                return Type::getArray();
                            }
                        } elseif (!$unpacked_type_part->type_params[0]->isEmpty()) {
                            $generic_properties = null;
                        }

                        if ($unpacked_type_part->type_params[1]->isEmpty()) {
                            continue;
                        }

                        $inner_key_types = array_merge(
                            $inner_key_types,
                            array_values($unpacked_type_part->type_params[0]->getTypes())
                        );
                        $inner_value_types = array_merge(
                            $inner_value_types,
                            array_values($unpacked_type_part->type_params[1]->getTypes())
                        );
                    }
                }
            }

            if ($generic_properties) {
                return new Type\Union([
                    new Type\Atomic\ObjectLike($generic_properties),
                ]);
            }

            if ($inner_value_types) {
                return new Type\Union([
                    new Type\Atomic\TArray([
                        Type::combineTypes($inner_key_types),
                        Type::combineTypes($inner_value_types),
                    ]),
                ]);
            }

            return Type::getArray();
        }

        if ($call_map_key === 'array_rand') {
            $first_arg_array = $first_arg
                && isset($first_arg->inferredType)
                && $first_arg->inferredType->hasType('array')
                && ($array_atomic_type = $first_arg->inferredType->getTypes()['array'])
                && ($array_atomic_type instanceof Type\Atomic\TArray ||
                    $array_atomic_type instanceof Type\Atomic\ObjectLike)
            ? $array_atomic_type
            : null;

            if (!$first_arg_array) {
                return Type::getMixed();
            }

            if ($first_arg_array instanceof Type\Atomic\TArray) {
                $key_type = clone $first_arg_array->type_params[0];
            } else {
                $key_type = $first_arg_array->getGenericKeyType();
            }

            if (!$second_arg
                || ($second_arg instanceof PhpParser\Node\Scalar\LNumber && $second_arg->value === 1)
            ) {
                return $key_type;
            }

            $arr_type = new Type\Union([
                new Type\Atomic\TArray([
                    Type::getInt(),
                    $key_type,
                ]),
            ]);

            if ($second_arg instanceof PhpParser\Node\Scalar\LNumber) {
                return $arr_type;
            }

            return Type::combineUnionTypes($key_type, $arr_type);
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     *
     * @return Type\Union
     */
    protected static function getArrayMapReturnType(
        StatementsChecker $statements_checker,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $array_arg = isset($call_args[1]->value) ? $call_args[1]->value : null;

        $array_arg_type = $array_arg
                && isset($array_arg->inferredType)
                && isset($array_arg->inferredType->getTypes()['array'])
                && ($array_atomic_type = $array_arg->inferredType->getTypes()['array'])
                && $array_atomic_type instanceof Type\Atomic\TArray
            ? $array_atomic_type
            : null;

        if (isset($call_args[0])) {
            $function_call_arg = $call_args[0];

            if ($function_call_arg->value instanceof PhpParser\Node\Expr\Closure
                && isset($function_call_arg->value->inferredType)
                && ($closure_atomic_type = $function_call_arg->value->inferredType->getTypes()['Closure'])
                && $closure_atomic_type instanceof Type\Atomic\Fn
            ) {
                $closure_return_type = $closure_atomic_type->return_type;

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_map',
                            $code_location
                        ),
                        $suppressed_issues
                    );

                    return Type::getArray();
                }

                $key_type = $array_arg_type ? clone $array_arg_type->type_params[0] : Type::getMixed();

                $inner_type = clone $closure_return_type;

                return new Type\Union([
                    new Type\Atomic\TArray([
                        $key_type,
                        $inner_type,
                    ]),
                ]);
            } elseif ($function_call_arg->value instanceof PhpParser\Node\Scalar\String_
                || $function_call_arg->value instanceof PhpParser\Node\Expr\Array_
            ) {
                $mapping_function_ids = Statements\Expression\CallChecker::getFunctionIdsFromCallableArg(
                    $statements_checker,
                    $function_call_arg->value
                );

                $call_map = self::getCallMap();

                $mapping_return_type = null;

                $project_checker = $statements_checker->getFileChecker()->project_checker;
                $codebase = $project_checker->codebase;

                foreach ($mapping_function_ids as $mapping_function_id) {
                    if (isset($call_map[$mapping_function_id][0])) {
                        if ($call_map[$mapping_function_id][0]) {
                            $mapped_function_return = Type::parseString($call_map[$mapping_function_id][0]);

                            if ($mapping_return_type) {
                                $mapping_return_type = Type::combineUnionTypes(
                                    $mapping_return_type,
                                    $mapped_function_return
                                );
                            } else {
                                $mapping_return_type = $mapped_function_return;
                            }
                        }
                    } else {
                        if (strpos($mapping_function_id, '::') !== false) {
                            list($callable_fq_class_name) = explode('::', $mapping_function_id);

                            if (in_array($callable_fq_class_name, ['self', 'static', 'parent'], true)) {
                                $mapping_return_type = Type::getMixed();
                                continue;
                            }

                            if (!MethodChecker::methodExists($project_checker, $mapping_function_id)) {
                                $mapping_return_type = Type::getMixed();
                                continue;
                            }

                            $return_type = MethodChecker::getMethodReturnType(
                                $project_checker,
                                $mapping_function_id
                            ) ?: Type::getMixed();

                            if ($mapping_return_type) {
                                $mapping_return_type = Type::combineUnionTypes(
                                    $mapping_return_type,
                                    $return_type
                                );
                            } else {
                                $mapping_return_type = $return_type;
                            }
                        } else {
                            if (!$codebase->functionExists($statements_checker, $mapping_function_id)) {
                                $mapping_return_type = Type::getMixed();
                                continue;
                            }

                            $function_storage = $codebase->getFunctionStorage(
                                $statements_checker,
                                $mapping_function_id
                            );

                            $return_type = $function_storage->return_type ?: Type::getMixed();

                            if ($mapping_return_type) {
                                $mapping_return_type = Type::combineUnionTypes(
                                    $mapping_return_type,
                                    $return_type
                                );
                            } else {
                                $mapping_return_type = $return_type;
                            }
                        }
                    }
                }

                if ($mapping_return_type) {
                    return new Type\Union([
                        new Type\Atomic\TArray([
                            Type::getInt(),
                            $mapping_return_type,
                        ]),
                    ]);
                }
            }
        }

        return Type::getArray();
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @param  CodeLocation                 $code_location
     * @param  array                        $suppressed_issues
     *
     * @return Type\Union
     */
    protected static function getArrayFilterReturnType(
        StatementsChecker $statements_checker,
        $call_args,
        CodeLocation $code_location,
        array $suppressed_issues
    ) {
        $array_arg = isset($call_args[0]->value) ? $call_args[0]->value : null;

        $first_arg_array = $array_arg
            && isset($array_arg->inferredType)
            && $array_arg->inferredType->hasType('array')
            && ($array_atomic_type = $array_arg->inferredType->getTypes()['array'])
            && ($array_atomic_type instanceof Type\Atomic\TArray ||
                $array_atomic_type instanceof Type\Atomic\ObjectLike)
            ? $array_atomic_type
            : null;

        if (!$first_arg_array) {
            return Type::getArray();
        }

        if ($first_arg_array instanceof Type\Atomic\TArray) {
            $inner_type = $first_arg_array->type_params[1];
            $key_type = clone $first_arg_array->type_params[0];
        } else {
            $inner_type = $first_arg_array->getGenericValueType();
            $key_type = $first_arg_array->getGenericKeyType();
        }

        if (!isset($call_args[1])) {
            $inner_type->removeType('null');
            $inner_type->removeType('false');
        } elseif (!isset($call_args[2])) {
            $function_call_arg = $call_args[1];

            if ($function_call_arg->value instanceof PhpParser\Node\Expr\Closure
                && isset($function_call_arg->value->inferredType)
                && ($closure_atomic_type = $function_call_arg->value->inferredType->getTypes()['Closure'])
                && $closure_atomic_type instanceof Type\Atomic\Fn
            ) {
                $closure_return_type = $closure_atomic_type->return_type;

                if ($closure_return_type->isVoid()) {
                    IssueBuffer::accepts(
                        new InvalidReturnType(
                            'No return type could be found in the closure passed to array_filter',
                            $code_location
                        ),
                        $suppressed_issues
                    );

                    return Type::getArray();
                }

                if (count($function_call_arg->value->stmts) === 1
                    && count($function_call_arg->value->params)
                    && ($first_param = $function_call_arg->value->params[0])
                    && $first_param->variadic === false
                    && ($stmt = $function_call_arg->value->stmts[0])
                    && $stmt instanceof PhpParser\Node\Stmt\Return_
                    && $stmt->expr
                ) {
                    $first_param_name = $first_param->name;
                    $assertions = AssertionFinder::getAssertions($stmt->expr, null, $statements_checker);

                    if (isset($assertions['$' . $first_param->name])) {
                        $changed_var_ids = [];

                        $reconciled_types = Reconciler::reconcileKeyedTypes(
                            ['$inner_type' => $assertions['$' . $first_param->name]],
                            ['$inner_type' => $inner_type],
                            $changed_var_ids,
                            ['$inner_type' => true],
                            $statements_checker,
                            new CodeLocation($statements_checker->getSource(), $stmt),
                            $statements_checker->getSuppressedIssues()
                        );

                        if (isset($reconciled_types['$inner_type'])) {
                            $inner_type = $reconciled_types['$inner_type'];
                        }
                    }
                }
            }

            return new Type\Union([
                new Type\Atomic\TArray([
                    $key_type,
                    $inner_type,
                ]),
            ]);
        }

        return new Type\Union([
            new Type\Atomic\TArray([
                $key_type,
                $inner_type,
            ]),
        ]);
    }

    /**
     * Gets the method/function call map
     *
     * @return array<string, array<int|string, string>>
     * @psalm-suppress MixedInferredReturnType as the use of require buggers things up
     * @psalm-suppress MixedAssignment
     */
    protected static function getCallMap()
    {
        if (self::$call_map !== null) {
            return self::$call_map;
        }

        /** @var array<string, array<string, string>> */
        $call_map = require_once(__DIR__ . '/../CallMap.php');

        self::$call_map = [];

        foreach ($call_map as $key => $value) {
            $cased_key = strtolower($key);
            self::$call_map[$cased_key] = $value;
        }

        return self::$call_map;
    }

    /**
     * @param   string $key
     *
     * @return  bool
     */
    public static function inCallMap($key)
    {
        return isset(self::getCallMap()[strtolower($key)]);
    }

    /**
     * @param  string                   $function_name
     * @param  StatementsSource         $source
     *
     * @return string
     */
    public static function getFQFunctionNameFromString($function_name, StatementsSource $source)
    {
        if (empty($function_name)) {
            throw new \InvalidArgumentException('$function_name cannot be empty');
        }

        if ($function_name[0] === '\\') {
            return substr($function_name, 1);
        }

        $function_name_lcase = strtolower($function_name);

        $aliases = $source->getAliases();

        $imported_function_namespaces = $aliases->functions;
        $imported_namespaces = $aliases->uses;

        if (strpos($function_name, '\\') !== false) {
            $function_name_parts = explode('\\', $function_name);
            $first_namespace = array_shift($function_name_parts);
            $first_namespace_lcase = strtolower($first_namespace);

            if (isset($imported_namespaces[$first_namespace_lcase])) {
                return $imported_namespaces[$first_namespace_lcase] . '\\' . implode('\\', $function_name_parts);
            }

            if (isset($imported_function_namespaces[$first_namespace_lcase])) {
                return $imported_function_namespaces[$first_namespace_lcase] . '\\' .
                    implode('\\', $function_name_parts);
            }
        } elseif (isset($imported_namespaces[$function_name_lcase])) {
            return $imported_namespaces[$function_name_lcase];
        } elseif (isset($imported_function_namespaces[$function_name_lcase])) {
            return $imported_function_namespaces[$function_name_lcase];
        }

        $namespace = $source->getNamespace();

        return ($namespace ? $namespace . '\\' : '') . $function_name;
    }
}
