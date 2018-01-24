<?php
namespace Psalm\Checker\Statements;

use PhpParser;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ClosureChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\Statements\Expression\ArrayChecker;
use Psalm\Checker\Statements\Expression\AssignmentChecker;
use Psalm\Checker\Statements\Expression\BinaryOpChecker;
use Psalm\Checker\Statements\Expression\CallChecker;
use Psalm\Checker\Statements\Expression\Fetch\ArrayFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\ConstFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\PropertyFetchChecker;
use Psalm\Checker\Statements\Expression\Fetch\VariableFetchChecker;
use Psalm\Checker\Statements\Expression\IncludeChecker;
use Psalm\Checker\Statements\Expression\TernaryChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\ForbiddenCode;
use Psalm\Issue\InvalidCast;
use Psalm\Issue\InvalidClone;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\PossiblyUndefinedVariable;
use Psalm\Issue\UndefinedVariable;
use Psalm\Issue\UnrecognizedExpression;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TString;

use function is_string;

class ExpressionChecker
{
    /**
     * @param   StatementsChecker   $statements_checker
     * @param   PhpParser\Node\Expr $stmt
     * @param   Context             $context
     * @param   bool                $array_assignment
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr $stmt,
        Context $context,
        $array_assignment = false
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable) {
            VariableFetchChecker::analyze(
                $statements_checker,
                $stmt,
                $context,
                false,
                null,
                $array_assignment
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\Assign) {
            $assignment_type = AssignmentChecker::analyze(
                $statements_checker,
                $stmt->var,
                $stmt->expr,
                null,
                $context,
                (string)$stmt->getDocComment(),
                $stmt->getLine()
            );

            if ($assignment_type !== false) {
                $stmt->inferredType = $assignment_type;
            }
            // TODO set it to Mixed?
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignOp) {
            AssignmentChecker::analyzeAssignmentOperation($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\MethodCall) {
            CallChecker::analyzeMethodCall($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticCall) {
            CallChecker::analyzeStaticCall($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\ConstFetch) {
            ConstFetchChecker::analyze($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\String_) {
            $stmt->inferredType = Type::getString();
        } elseif ($stmt instanceof PhpParser\Node\Scalar\EncapsedStringPart) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Scalar\MagicConst) {
            switch (strtolower($stmt->getName())) {
                case '__line__':
                    $stmt->inferredType = Type::getInt();
                    break;

                case '__file__':
                case '__dir__':
                case '__function__':
                case '__class__':
                case '__trait__':
                case '__method__':
                case '__namespace__':
                    $stmt->inferredType = Type::getString();
                    break;
            }
        } elseif ($stmt instanceof PhpParser\Node\Scalar\LNumber) {
            $stmt->inferredType = Type::getInt();
        } elseif ($stmt instanceof PhpParser\Node\Scalar\DNumber) {
            $stmt->inferredType = Type::getFloat();
        } elseif ($stmt instanceof PhpParser\Node\Expr\UnaryMinus ||
            $stmt instanceof PhpParser\Node\Expr\UnaryPlus
        ) {
            self::analyze($statements_checker, $stmt->expr, $context);

            if (!isset($stmt->expr->inferredType)) {
                $stmt->inferredType = new Type\Union([new TInt, new TFloat]);
            } elseif ($stmt->expr->inferredType->isMixed()) {
                $stmt->inferredType = Type::getMixed();
            } else {
                $acceptable_types = [];

                foreach ($stmt->expr->inferredType->getTypes() as $type_part) {
                    if ($type_part instanceof TInt || $type_part instanceof TFloat) {
                        $acceptable_types[] = $type_part;
                    } elseif ($type_part instanceof TString) {
                        $acceptable_types[] = new TInt;
                        $acceptable_types[] = new TFloat;
                    } else {
                        $acceptable_types[] = new TInt;
                    }
                }

                $stmt->inferredType = new Type\Union($acceptable_types);
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Isset_) {
            self::analyzeIsset($statements_checker, $stmt, $context);
            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\ClassConstFetch) {
            ConstFetchChecker::analyzeClassConst($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\PropertyFetch) {
            PropertyFetchChecker::analyzeInstance($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch) {
            PropertyFetchChecker::analyzeStatic($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\BitwiseNot) {
            self::analyze($statements_checker, $stmt->expr, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            BinaryOpChecker::analyze(
                $statements_checker,
                $stmt,
                $context
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\PostInc ||
            $stmt instanceof PhpParser\Node\Expr\PostDec ||
            $stmt instanceof PhpParser\Node\Expr\PreInc ||
            $stmt instanceof PhpParser\Node\Expr\PreDec
        ) {
            // TODO: Narrow the types of post-inc/dec to a subset of int|string ($x = 'x'; ++$x; gives 'y')
            self::analyze($statements_checker, $stmt->var, $context);

            if (isset($stmt->var->inferredType)) {
                $stmt->inferredType = clone $stmt->var->inferredType;
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\New_) {
            CallChecker::analyzeNew($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Array_) {
            ArrayChecker::analyze($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Scalar\Encapsed) {
            self::analyzeEncapsulatedString($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\FuncCall) {
            $project_checker = $statements_checker->getFileChecker()->project_checker;
            CallChecker::analyzeFunctionCall(
                $project_checker,
                $statements_checker,
                $stmt,
                $context
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\Ternary) {
            TernaryChecker::analyze($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\BooleanNot) {
            self::analyzeBooleanNot($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Empty_) {
            self::analyzeEmpty($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Closure) {
            $closure_checker = new ClosureChecker($stmt, $statements_checker->getSource());

            self::analyzeClosureUses($statements_checker, $stmt, $context);

            $codebase = $statements_checker->getFileChecker()->project_checker->codebase;

            $use_context = new Context($context->self);
            $use_context->collect_references = $codebase->collect_references;

            if (!$statements_checker->isStatic()) {
                if ($context->collect_mutations &&
                    $context->self &&
                    ClassChecker::classExtends(
                        $statements_checker->getFileChecker()->project_checker,
                        $context->self,
                        (string)$statements_checker->getFQCLN()
                    )
                ) {
                    $use_context->vars_in_scope['$this'] = clone $context->vars_in_scope['$this'];
                } elseif ($context->self) {
                    $use_context->vars_in_scope['$this'] = new Type\Union([new TNamedObject($context->self)]);
                }
            }

            foreach ($context->vars_in_scope as $var => $type) {
                if (strpos($var, '$this->') === 0) {
                    $use_context->vars_in_scope[$var] = clone $type;
                }
            }

            foreach ($context->vars_possibly_in_scope as $var => $type) {
                if (strpos($var, '$this->') === 0) {
                    $use_context->vars_possibly_in_scope[$var] = true;
                }
            }

            foreach ($stmt->uses as $use) {
                // insert the ref into the current context if passed by ref, as whatever we're passing
                // the closure to could execute it straight away.
                if (!$context->hasVariable('$' . $use->var) && $use->byRef) {
                    $context->vars_in_scope['$' . $use->var] = Type::getMixed();
                }

                $use_context->vars_in_scope['$' . $use->var] =
                    $context->hasVariable('$' . $use->var) && !$use->byRef
                    ? clone $context->vars_in_scope['$' . $use->var]
                    : Type::getMixed();

                $use_context->vars_possibly_in_scope['$' . $use->var] = true;
            }

            $closure_checker->analyze($use_context, $context);

            if (!isset($stmt->inferredType)) {
                $stmt->inferredType = Type::getClosure();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            ArrayFetchChecker::analyze(
                $statements_checker,
                $stmt,
                $context
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Int_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $stmt->inferredType = Type::getInt();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Double) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $stmt->inferredType = Type::getFloat();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Bool_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\String_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $container_type = Type::getString();

            if (isset($stmt->expr->inferredType)
                && !$stmt->expr->inferredType->isMixed()
                && !TypeChecker::isContainedBy(
                    $statements_checker->getFileChecker()->project_checker,
                    $stmt->expr->inferredType,
                    $container_type,
                    true,
                    false,
                    $has_scalar_match
                )
                && !$has_scalar_match
            ) {
                IssueBuffer::accepts(
                    new InvalidCast(
                        $stmt->expr->inferredType . ' cannot be cast to ' . $container_type,
                        new CodeLocation($statements_checker->getSource(), $stmt)
                    ),
                    $statements_checker->getSuppressedIssues()
                );
            }

            $stmt->inferredType = $container_type;
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Object_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $stmt->inferredType = new Type\Union([new TNamedObject('stdClass')]);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Array_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $permissible_atomic_types = [];
            $all_permissible = false;

            if (isset($stmt->expr->inferredType)) {
                $all_permissible = true;

                foreach ($stmt->expr->inferredType->getTypes() as $type) {
                    if ($type instanceof Scalar) {
                        $permissible_atomic_types[] = new TArray([Type::getInt(), new Type\Union([$type])]);
                    } elseif ($type instanceof TArray) {
                        $permissible_atomic_types[] = $type;
                    } elseif ($type instanceof ObjectLike) {
                        $permissible_atomic_types[] = $type->getGenericArrayType();
                    } else {
                        $all_permissible = false;
                        break;
                    }
                }
            }

            if ($all_permissible) {
                $stmt->inferredType = Type::combineTypes($permissible_atomic_types);
            } else {
                $stmt->inferredType = Type::getArray();
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Cast\Unset_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            $stmt->inferredType = Type::getNull();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Clone_) {
            self::analyzeClone($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Instanceof_) {
            self::analyze($statements_checker, $stmt->expr, $context);

            if ($stmt->class instanceof PhpParser\Node\Name &&
                !in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($context->check_classes) {
                    $fq_class_name = ClassLikeChecker::getFQCLNFromNameObject(
                        $stmt->class,
                        $statements_checker->getAliases()
                    );

                    ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statements_checker,
                        $fq_class_name,
                        new CodeLocation($statements_checker->getSource(), $stmt->class),
                        $statements_checker->getSuppressedIssues(),
                        false
                    );
                }
            }

            $stmt->inferredType = Type::getBool();
        } elseif ($stmt instanceof PhpParser\Node\Expr\Exit_) {
            if ($stmt->expr) {
                self::analyze($statements_checker, $stmt->expr, $context);
            }
        } elseif ($stmt instanceof PhpParser\Node\Expr\Include_) {
            IncludeChecker::analyze($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Eval_) {
            $context->check_classes = false;
            $context->check_variables = false;

            self::analyze($statements_checker, $stmt->expr, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\AssignRef) {
            AssignmentChecker::analyzeAssignmentRef($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\ErrorSuppress) {
            // do nothing
        } elseif ($stmt instanceof PhpParser\Node\Expr\ShellExec) {
            IssueBuffer::accepts(
                new ForbiddenCode(
                    'Use of shell_exec',
                    new CodeLocation($statements_checker->getSource(), $stmt)
                ),
                $statements_checker->getSuppressedIssues()
            );
        } elseif ($stmt instanceof PhpParser\Node\Expr\Print_) {
            self::analyze($statements_checker, $stmt->expr, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\Yield_) {
            self::analyzeYield($statements_checker, $stmt, $context);
        } elseif ($stmt instanceof PhpParser\Node\Expr\YieldFrom) {
            self::analyzeYieldFrom($statements_checker, $stmt, $context);
        } else {
            if (IssueBuffer::accepts(
                new UnrecognizedExpression(
                    'Psalm does not understand ' . get_class($stmt),
                    new CodeLocation($statements_checker->getSource(), $stmt)
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        $project_checker = $statements_checker->getFileChecker()->project_checker;

        $plugins = $project_checker->config->getPluginsForAfterExpressionCheck();

        if ($plugins) {
            $file_manipulations = [];
            $code_location = new CodeLocation($statements_checker->getSource(), $stmt);

            foreach ($plugins as $plugin) {
                $plugin->afterExpressionCheck(
                    $statements_checker,
                    $stmt,
                    $context,
                    $code_location,
                    $statements_checker->getSuppressedIssues(),
                    $file_manipulations
                );
            }

            if ($file_manipulations) {
                FileManipulationBuffer::add($statements_checker->getFilePath(), $file_manipulations);
            }
        }

        return null;
    }

    /**
     * @param  StatementsChecker    $statements_checker
     * @param  PhpParser\Node\Expr  $stmt
     * @param  Type\Union           $by_ref_type
     * @param  bool                 $constrain_type
     * @param  Context              $context
     *
     * @return void
     */
    public static function assignByRefParam(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr $stmt,
        Type\Union $by_ref_type,
        Context $context,
        $constrain_type = true
    ) {
        $var_id = self::getVarId(
            $stmt,
            $statements_checker->getFQCLN(),
            $statements_checker
        );

        if ($var_id) {
            if (!$by_ref_type->isMixed() && $constrain_type) {
                $context->byref_constraints[$var_id] = new \Psalm\ReferenceConstraint($by_ref_type);
            }

            if (!$context->hasVariable($var_id)) {
                $context->vars_possibly_in_scope[$var_id] = true;

                if (!$statements_checker->hasVariable($var_id)) {
                    $statements_checker->registerVariable($var_id, new CodeLocation($statements_checker, $stmt), null);
                    $context->hasVariable($var_id);
                }
            } else {
                $existing_type = $context->vars_in_scope[$var_id];

                // removes dependennt vars from $context
                $context->removeDescendents(
                    $var_id,
                    $existing_type,
                    $by_ref_type,
                    $statements_checker
                );

                if ($existing_type->getId() !== 'array<empty, empty>') {
                    $context->vars_in_scope[$var_id] = $by_ref_type;
                    $stmt->inferredType = $context->vars_in_scope[$var_id];

                    return;
                }
            }

            $context->vars_in_scope[$var_id] = $by_ref_type;
        }

        $stmt->inferredType = $by_ref_type;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $this_class_name
     * @param  StatementsSource|null    $source
     * @param  int|null                 &$nesting
     *
     * @return string|null
     */
    public static function getVarId(
        PhpParser\Node\Expr $stmt,
        $this_class_name,
        StatementsSource $source = null,
        &$nesting = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable && is_string($stmt->name)) {
            return '$' . $stmt->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch
            && is_string($stmt->name)
            && $stmt->class instanceof PhpParser\Node\Name
        ) {
            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if (!$this_class_name) {
                    $fq_class_name = $stmt->class->parts[0];
                } else {
                    $fq_class_name = $this_class_name;
                }
            } else {
                $fq_class_name = $source
                    ? ClassLikeChecker::getFQCLNFromNameObject(
                        $stmt->class,
                        $source->getAliases()
                    )
                    : implode('\\', $stmt->class->parts);
            }

            return $fq_class_name . '::$' . $stmt->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && is_string($stmt->name)) {
            $object_id = self::getVarId($stmt->var, $this_class_name, $source);

            if (!$object_id) {
                return null;
            }

            return $object_id . '->' . $stmt->name;
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch && $nesting !== null) {
            ++$nesting;

            return self::getVarId($stmt->var, $this_class_name, $source, $nesting);
        }

        return null;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $this_class_name
     * @param  StatementsSource|null    $source
     *
     * @return string|null
     */
    public static function getRootVarId(
        PhpParser\Node\Expr $stmt,
        $this_class_name,
        StatementsSource $source = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Variable
            || $stmt instanceof PhpParser\Node\Expr\StaticPropertyFetch
        ) {
            return self::getVarId($stmt, $this_class_name, $source);
        }

        if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch && is_string($stmt->name)) {
            $property_root = self::getRootVarId($stmt->var, $this_class_name, $source);

            if ($property_root) {
                return $property_root . '->' . $stmt->name;
            }
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            return self::getRootVarId($stmt->var, $this_class_name, $source);
        }

        return null;
    }

    /**
     * @param  PhpParser\Node\Expr      $stmt
     * @param  string|null              $this_class_name
     * @param  StatementsSource|null    $source
     *
     * @return string|null
     */
    public static function getArrayVarId(
        PhpParser\Node\Expr $stmt,
        $this_class_name,
        StatementsSource $source = null
    ) {
        if ($stmt instanceof PhpParser\Node\Expr\Assign) {
            return self::getArrayVarId($stmt->var, $this_class_name, $source);
        }

        if ($stmt instanceof PhpParser\Node\Expr\ArrayDimFetch &&
            ($stmt->dim instanceof PhpParser\Node\Scalar\String_ ||
                $stmt->dim instanceof PhpParser\Node\Scalar\LNumber)
        ) {
            $root_var_id = self::getArrayVarId($stmt->var, $this_class_name, $source);
            $offset = $stmt->dim instanceof PhpParser\Node\Scalar\String_
                ? '\'' . $stmt->dim->value . '\''
                : $stmt->dim->value;

            return $root_var_id ? $root_var_id . '[' . $offset . ']' : null;
        }

        return self::getVarId($stmt, $this_class_name, $source);
    }

    /**
     * @param  Type\Union   $return_type
     * @param  string|null  $calling_class
     * @param  string|null  $method_id
     *
     * @return Type\Union
     */
    public static function fleshOutType(
        ProjectChecker $project_checker,
        Type\Union $return_type,
        $calling_class = null,
        $method_id = null
    ) {
        $return_type = clone $return_type;

        $new_return_type_parts = [];

        foreach ($return_type->getTypes() as $return_type_part) {
            $new_return_type_parts[] = self::fleshOutAtomicType(
                $project_checker,
                $return_type_part,
                $calling_class,
                $method_id
            );
        }

        $fleshed_out_type = new Type\Union($new_return_type_parts);

        $fleshed_out_type->from_docblock = $return_type->from_docblock;
        $fleshed_out_type->ignore_nullable_issues = $return_type->ignore_nullable_issues;
        $fleshed_out_type->by_ref = $return_type->by_ref;

        return $fleshed_out_type;
    }

    /**
     * @param  Type\Atomic  &$return_type
     * @param  string|null  $calling_class
     * @param  string|null  $method_id
     *
     * @return Type\Atomic
     */
    protected static function fleshOutAtomicType(
        ProjectChecker $project_checker,
        Type\Atomic $return_type,
        $calling_class,
        $method_id
    ) {
        if ($return_type instanceof TNamedObject) {
            $return_type_lc = strtolower($return_type->value);

            if (in_array($return_type_lc, ['$this', 'static', 'self'], true)) {
                if (!$calling_class) {
                    throw new \InvalidArgumentException(
                        'Cannot handle ' . $return_type->value . ' when $calling_class is empty'
                    );
                }

                if ($return_type_lc === 'static' || !$method_id) {
                    $return_type->value = $calling_class;
                } elseif (strpos($method_id, ':-:closure') !== false) {
                    $return_type->value = $calling_class;
                } else {
                    list(, $method_name) = explode('::', $method_id);

                    $appearing_method_id = MethodChecker::getAppearingMethodId(
                        $project_checker,
                        $calling_class . '::' . $method_name
                    );

                    $return_type->value = explode('::', (string)$appearing_method_id)[0];
                }
            }
        }

        if ($return_type instanceof Type\Atomic\TArray || $return_type instanceof Type\Atomic\TGenericObject) {
            foreach ($return_type->type_params as &$type_param) {
                $type_param = self::fleshOutType($project_checker, $type_param, $calling_class, $method_id);
            }
        }

        return $return_type;
    }

    /**
     * @param   StatementsChecker           $statements_checker
     * @param   PhpParser\Node\Expr\Closure $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    protected static function analyzeClosureUses(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\Closure $stmt,
        Context $context
    ) {
        foreach ($stmt->uses as $use) {
            $use_var_id = '$' . $use->var;
            if (!$context->hasVariable($use_var_id)) {
                if ($use->byRef) {
                    $context->vars_in_scope[$use_var_id] = Type::getMixed();
                    $context->vars_possibly_in_scope[$use_var_id] = true;

                    if (!$statements_checker->hasVariable($use_var_id)) {
                        $statements_checker->registerVariable(
                            $use_var_id,
                            new CodeLocation($statements_checker, $use),
                            null
                        );
                    }

                    return;
                }

                if (!isset($context->vars_possibly_in_scope[$use_var_id])) {
                    if ($context->check_variables) {
                        IssueBuffer::accepts(
                            new UndefinedVariable(
                                'Cannot find referenced variable ' . $use_var_id,
                                new CodeLocation($statements_checker->getSource(), $use)
                            ),
                            $statements_checker->getSuppressedIssues()
                        );

                        return null;
                    }
                }

                $first_appearance = $statements_checker->getFirstAppearance($use_var_id);

                if ($first_appearance) {
                    IssueBuffer::accepts(
                        new PossiblyUndefinedVariable(
                            'Possibly undefined variable ' . $use_var_id . ', first seen on line ' .
                                $first_appearance->getLineNumber(),
                            new CodeLocation($statements_checker->getSource(), $use)
                        ),
                        $statements_checker->getSuppressedIssues()
                    );

                    continue;
                }

                if ($context->check_variables) {
                    IssueBuffer::accepts(
                        new UndefinedVariable(
                            'Cannot find referenced variable ' . $use_var_id,
                            new CodeLocation($statements_checker->getSource(), $use)
                        ),
                        $statements_checker->getSuppressedIssues()
                    );

                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @param   StatementsChecker           $statements_checker
     * @param   PhpParser\Node\Expr\Yield_  $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    protected static function analyzeYield(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\Yield_ $stmt,
        Context $context
    ) {
        $doc_comment_text = (string)$stmt->getDocComment();

        $var_comment = null;

        if ($doc_comment_text) {
            try {
                $var_comment = CommentChecker::getTypeFromComment(
                    $doc_comment_text,
                    $statements_checker,
                    $statements_checker->getAliases()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($statements_checker->getSource(), $stmt)
                    )
                )) {
                    // fall through
                }
            }

            if ($var_comment && $var_comment->var_id) {
                $comment_type = ExpressionChecker::fleshOutType(
                    $statements_checker->getFileChecker()->project_checker,
                    $var_comment->type,
                    $context->self
                );

                $context->vars_in_scope[$var_comment->var_id] = $comment_type;
            }
        }

        if ($stmt->key) {
            self::analyze($statements_checker, $stmt->key, $context);
        }

        if ($stmt->value) {
            self::analyze($statements_checker, $stmt->value, $context);

            if ($var_comment && !$var_comment->var_id) {
                $stmt->inferredType = $var_comment->type;
            } elseif (isset($stmt->value->inferredType)) {
                $stmt->inferredType = $stmt->value->inferredType;
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $stmt->inferredType = Type::getNull();
        }

        return null;
    }

    /**
     * @param   StatementsChecker               $statements_checker
     * @param   PhpParser\Node\Expr\YieldFrom   $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeYieldFrom(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\YieldFrom $stmt,
        Context $context
    ) {
        self::analyze($statements_checker, $stmt->expr, $context);

        if (isset($stmt->expr->inferredType)) {
            // this should be whatever the generator above returns, but *not* the return type
            $stmt->inferredType = Type::getMixed();
        }

        return null;
    }

    /**
     * @param   StatementsChecker               $statements_checker
     * @param   PhpParser\Node\Expr\BooleanNot  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeBooleanNot(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\BooleanNot $stmt,
        Context $context
    ) {
        $stmt->inferredType = Type::getBool();

        self::analyze($statements_checker, $stmt->expr, $context);
    }

    /**
     * @param   StatementsChecker           $statements_checker
     * @param   PhpParser\Node\Expr\Empty_  $stmt
     * @param   Context                     $context
     *
     * @return  void
     */
    protected static function analyzeEmpty(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\Empty_ $stmt,
        Context $context
    ) {
        self::analyzeIssetVar($statements_checker, $stmt->expr, $context);
    }

    /**
     * @param   StatementsChecker               $statements_checker
     * @param   PhpParser\Node\Scalar\Encapsed  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    protected static function analyzeEncapsulatedString(
        StatementsChecker $statements_checker,
        PhpParser\Node\Scalar\Encapsed $stmt,
        Context $context
    ) {
        $project_checker = $statements_checker->getFileChecker()->project_checker;

        $function_storage = null;

        if ($project_checker->infer_types_from_usage) {
            $source_checker = $statements_checker->getSource();

            if ($source_checker instanceof FunctionLikeChecker) {
                $function_storage = $source_checker->getFunctionLikeStorage($statements_checker);
            }
        }

        /** @var PhpParser\Node\Expr $part */
        foreach ($stmt->parts as $part) {
            self::analyze($statements_checker, $part, $context);

            if ($function_storage) {
                $context->inferType($part, $function_storage, Type::getString());
            }
        }

        $stmt->inferredType = Type::getString();

        return null;
    }

    /**
     * @param  StatementsChecker          $statements_checker
     * @param  PhpParser\Node\Expr\Isset_ $stmt
     * @param  Context                    $context
     *
     * @return void
     */
    protected static function analyzeIsset(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\Isset_ $stmt,
        Context $context
    ) {
        foreach ($stmt->vars as $isset_var) {
            if ($isset_var instanceof PhpParser\Node\Expr\PropertyFetch &&
                $isset_var->var instanceof PhpParser\Node\Expr\Variable &&
                $isset_var->var->name === 'this' &&
                is_string($isset_var->name)
            ) {
                $var_id = '$this->' . $isset_var->name;

                if (!isset($context->vars_in_scope[$var_id])) {
                    $context->vars_in_scope[$var_id] = Type::getMixed();
                    $context->vars_possibly_in_scope[$var_id] = true;
                }
            }

            self::analyzeIssetVar($statements_checker, $isset_var, $context);
        }
    }

    /**
     * @param  StatementsChecker   $statements_checker
     * @param  PhpParser\Node\Expr $stmt
     * @param  Context             $context
     *
     * @return false|null
     */
    protected static function analyzeIssetVar(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr $stmt,
        Context $context
    ) {
        $context->inside_isset = true;

        // TODO: Should the old code restore inside_isset? Would a count of entries or fetching and restoring $old_include_isset be more effective?
        self::analyze($statements_checker, $stmt, $context);

        $context->inside_isset = false;
    }

    /**
     * @param  StatementsChecker            $statements_checker
     * @param  PhpParser\Node\Expr\Clone_   $stmt
     * @param  Context                      $context
     *
     * @return false|null
     */
    protected static function analyzeClone(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\Clone_ $stmt,
        Context $context
    ) {
        self::analyze($statements_checker, $stmt->expr, $context);

        if (isset($stmt->expr->inferredType)) {
            foreach ($stmt->expr->inferredType->getTypes() as $clone_type_part) {
                if (!$clone_type_part instanceof TNamedObject &&
                    !$clone_type_part instanceof TObject &&
                    !$clone_type_part instanceof TMixed
                ) {
                    if (IssueBuffer::accepts(
                        new InvalidClone(
                            'Cannot clone ' . $clone_type_part,
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        return false;
                    }

                    return;
                }
            }

            $stmt->inferredType = $stmt->expr->inferredType;
        }
    }

    /**
     * @param  string  $fq_class_name
     *
     * @return bool
     */
    public static function isMock($fq_class_name)
    {
        return in_array($fq_class_name, Config::getInstance()->getMockClasses(), true);
    }
}
