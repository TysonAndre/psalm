<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TypeAnalyzer;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\EmptyArrayAccess;
use Psalm\Issue\InvalidArrayAccess;
use Psalm\Issue\InvalidArrayAssignment;
use Psalm\Issue\InvalidArrayOffset;
use Psalm\Issue\MixedArrayAccess;
use Psalm\Issue\MixedArrayAssignment;
use Psalm\Issue\MixedArrayOffset;
use Psalm\Issue\MixedStringOffsetAssignment;
use Psalm\Issue\NullArrayAccess;
use Psalm\Issue\NullArrayOffset;
use Psalm\Issue\PossiblyInvalidArrayAccess;
use Psalm\Issue\PossiblyInvalidArrayAssignment;
use Psalm\Issue\PossiblyInvalidArrayOffset;
use Psalm\Issue\PossiblyNullArrayAccess;
use Psalm\Issue\PossiblyNullArrayAssignment;
use Psalm\Issue\PossiblyNullArrayOffset;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\ObjectLike;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TEmpty;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;

// This analyzes uses of tag_array::get($array, $key [, $default]) { return $array[$key] ?? $default; }, which is a polyfill for `??` in code predating php 7
// FIXME: Finish implementing this.
// TODO: Move this into a plugin.
class ArrayUtilChecker
{

    /**
     * @param PhpParser\Node\Expr $stmt
     * @param array<int,PhpParser\Node\Arg> $args
     * @return Type\Union
     * @suppress MixedPropertyFetch
     */
    public static function getTypeOfGet(
        ProjectAnalyzer $project_analyzer,
        StatementsAnalyzer $statements_checker,
        PhpParser\Node\Expr $stmt,
        array $args,
        Context $context
    ) {
        if (count($args) < 2) {
            return Type::getMixed();
        }
        $var_node = $args[0]->value;
        if ($args[1]->unpack) {
            return Type::getMixed();
        }
        $dim_node = $args[1]->value;
        // TODO: $args[2] is default_node. Or use null.

        $array_var_id = ExpressionAnalyzer::getArrayVarId(
            $var_node,
            $statements_checker->getFQCLN(),
            $statements_checker
        );

        // No keyed_array_var_id equivalent

        if (ExpressionAnalyzer::analyze($statements_checker, $dim_node, $context) === false) {
            return Type::getMixed();
        }

        if (isset($dim_node->inferredType)) {
            /** @var Type\Union */
            $used_key_type = $dim_node->inferredType;
        } else {
            $used_key_type = Type::getMixed();
        }

        if (ExpressionAnalyzer::analyze(
            $statements_checker,
            $var_node,
            $context
        ) === false) {
            return Type::getMixed();
        }

        if (isset($var_node->inferredType)) {
            /** @var Type\Union */
            $var_type = $var_node->inferredType;

            if ($var_type->isNull()) {
                if (!$context->inside_isset) {
                    if (IssueBuffer::accepts(
                        new NullArrayAccess(
                            'Cannot access array value on null variable ' . $array_var_id,
                            new CodeLocation($statements_checker->getSource(), $var_node)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (isset($stmt->inferredType)) {
                    $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, Type::getNull());
                } else {
                    $stmt->inferredType = Type::getNull();
                }

                return $stmt->inferredType;
            }

            $field_type = self::getArrayAccessTypeGivenOffset(
                $statements_checker,
                $stmt,
                $var_node,
                $dim_node,
                $var_node->inferredType,
                $used_key_type,
                false,
                $array_var_id,
                null,
                $context->inside_isset
            );
            // TODO: Add the default's type to $field_type, similar to the null coalesce operator.
            // (Be more precise when ObjectLike)
            $stmt->inferredType = $field_type;
        }

        if (!isset($stmt->inferredType)) {
            $stmt->inferredType = Type::getMixed();
        }

        return $stmt->inferredType;
    }

    /**
     * @param  Type\Union $array_type
     * @param  Type\Union $offset_type
     * @param  bool       $in_assignment
     * @param  ?string    $array_var_id
     * @param  bool       $inside_isset
     *
     * @return Type\Union
     */
    public static function getArrayAccessTypeGivenOffset(
        StatementsAnalyzer $statements_checker,
        PhpParser\Node\Expr $stmt,
        PhpParser\Node\Expr $var_node,
        PhpParser\Node\Expr $dim_node,
        Type\Union $array_type,
        Type\Union $offset_type,
        $in_assignment,
        $array_var_id,
        Type\Union $replacement_type = null,
        $inside_isset = false
    ) {
        $project_analyzer = $statements_checker->getFileAnalyzer()->project_analyzer;
        $codebase = $project_analyzer->getCodebase();

        $has_array_access = false;
        $non_array_types = [];

        $has_valid_offset = false;
        $invalid_offset_types = [];

        $key_value = null;

        if ($dim_node instanceof PhpParser\Node\Scalar\String_
            || $dim_node instanceof PhpParser\Node\Scalar\LNumber
        ) {
            $key_value = $dim_node->value;
        }

        $array_access_type = null;

        if ($offset_type->isNull()) {
            if (IssueBuffer::accepts(
                new NullArrayOffset(
                    'Cannot access value on variable ' . $array_var_id . ' using null offset',
                    new CodeLocation($statements_checker->getSource(), $stmt)
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                // fall through
            }

            return Type::getMixed();
        }

        if ($offset_type->isNullable() && !$offset_type->ignore_nullable_issues && !$inside_isset) {
            if (IssueBuffer::accepts(
                new PossiblyNullArrayOffset(
                    'Cannot access value on variable ' . $array_var_id
                        . ' using possibly null offset ' . $offset_type,
                    new CodeLocation($statements_checker->getSource(), $var_node)
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                // fall through
            }
        }

        foreach ($array_type->getTypes() as &$type) {
            if ($type instanceof TNull) {
                if ($array_type->ignore_nullable_issues) {
                    continue;
                }

                if ($in_assignment) {
                    if ($replacement_type) {
                        if ($array_access_type) {
                            $array_access_type = Type::combineUnionTypes($array_access_type, $replacement_type);
                        } else {
                            $array_access_type = clone $replacement_type;
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new PossiblyNullArrayAssignment(
                                'Cannot access array value on possibly null variable ' . $array_var_id .
                                    ' of type ' . $array_type,
                                new CodeLocation($statements_checker->getSource(), $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            // fall through
                        }

                        $array_access_type = new Type\Union([new TEmpty]);
                    }
                } else {
                    if (!$inside_isset) {
                        if (IssueBuffer::accepts(
                            new PossiblyNullArrayAccess(
                                'Cannot access array value on possibly null variable ' . $array_var_id .
                                    ' of type ' . $array_type,
                                new CodeLocation($statements_checker->getSource(), $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }

                    if ($array_access_type) {
                        $array_access_type = Type::combineUnionTypes($array_access_type, Type::getNull());
                    } else {
                        $array_access_type = Type::getNull();
                    }
                }

                continue;
            }

            if ($type instanceof TArray || $type instanceof ObjectLike) {
                $has_array_access = true;

                if ($in_assignment
                    && $type instanceof TArray
                    && $type->type_params[0]->isEmpty()
                    && $key_value !== null
                ) {
                    // ok, type becomes an ObjectLike

                    $type = new ObjectLike([$key_value => new Type\Union([new TEmpty])]);
                }

                if ($type instanceof TArray) {
                    // if we're assigning to an empty array with a key offset, refashion that array
                    if ($in_assignment) {
                        if ($type->type_params[0]->isEmpty()) {
                            $type->type_params[0] = $offset_type;
                        }
                    } elseif (!$type->type_params[0]->isEmpty()) {
                        if (!TypeAnalyzer::isContainedBy(
                            $codebase,
                            $offset_type,
                            $type->type_params[0],
                            true
                        )) {
                            $invalid_offset_types[] = (string)$type->type_params[0];
                        } else {
                            $has_valid_offset = true;
                        }
                    }

                    if ($in_assignment && $replacement_type) {
                        $type->type_params[1] = Type::combineUnionTypes(
                            $type->type_params[1],
                            $replacement_type
                        );
                    }

                    if (!$array_access_type) {
                        $array_access_type = $type->type_params[1];
                    } else {
                        $array_access_type = Type::combineUnionTypes(
                            $array_access_type,
                            $type->type_params[1]
                        );
                    }

                    if ($array_access_type->isEmpty() && !$in_assignment) {
                        if (IssueBuffer::accepts(
                            new EmptyArrayAccess(
                                'Cannot access value on empty array variable ' . $array_var_id,
                                new CodeLocation($statements_checker->getSource(), $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return Type::getMixed();
                        }

                        if (!IssueBuffer::isRecording()) {
                            $array_access_type = Type::getMixed();
                        }
                    }
                } else {  // $type is ObjectLike
                    if ($key_value !== null) {
                        if (isset($type->properties[$key_value]) || $replacement_type) {
                            $has_valid_offset = true;

                            if ($replacement_type) {
                                if (isset($type->properties[$key_value])) {
                                    $type->properties[$key_value] = Type::combineUnionTypes(
                                        $type->properties[$key_value],
                                        $replacement_type
                                    );
                                } else {
                                    $type->properties[$key_value] = $replacement_type;
                                }
                            }

                            if (!$array_access_type) {
                                $array_access_type = clone $type->properties[$key_value];
                            } else {
                                $array_access_type = Type::combineUnionTypes(
                                    $array_access_type,
                                    $type->properties[$key_value]
                                );
                            }
                        } elseif ($in_assignment) {
                            $type->properties[$key_value] = new Type\Union([new TEmpty]);

                            if (!$array_access_type) {
                                $array_access_type = clone $type->properties[$key_value];
                            } else {
                                $array_access_type = Type::combineUnionTypes(
                                    $array_access_type,
                                    $type->properties[$key_value]
                                );
                            }
                        } else {
                            $object_like_keys = array_keys($type->properties);

                            if (count($object_like_keys) === 1) {
                                $expected_keys_string = '\'' . $object_like_keys[0] . '\'';
                            } else {
                                $last_key = array_pop($object_like_keys);
                                $expected_keys_string = '\'' . implode('\', \'', $object_like_keys) .
                                    '\' or \'' . $last_key . '\'';
                            }

                            $invalid_offset_types[] = $expected_keys_string;

                            $array_access_type = Type::getMixed();
                        }
                    } elseif (TypeAnalyzer::isContainedBy(
                        $codebase,
                        $offset_type,
                        $type->getGenericKeyType(),
                        true
                    ) || $in_assignment
                    ) {
                        if ($replacement_type) {
                            $generic_params = Type::combineUnionTypes(
                                $type->getGenericValueType(),
                                $replacement_type
                            );

                            $new_key_type = Type::combineUnionTypes(
                                $type->getGenericKeyType(),
                                $offset_type
                            );

                            $type = new TArray([
                                $new_key_type,
                                $generic_params,
                            ]);

                            if (!$array_access_type) {
                                $array_access_type = clone $generic_params;
                            } else {
                                $array_access_type = Type::combineUnionTypes(
                                    $array_access_type,
                                    $generic_params
                                );
                            }
                        } else {
                            if (!$array_access_type) {
                                $array_access_type = $type->getGenericValueType();
                            } else {
                                $array_access_type = Type::combineUnionTypes(
                                    $array_access_type,
                                    $type->getGenericValueType()
                                );
                            }
                        }

                        $has_valid_offset = true;
                    } else {
                        $invalid_offset_types[] = (string)$type->getGenericKeyType();

                        $array_access_type = Type::getMixed();
                    }
                }
                continue;
            }

            if ($type instanceof TString) {
                if ($in_assignment && $replacement_type && $replacement_type->isMixed()) {
                    if (IssueBuffer::accepts(
                        new MixedStringOffsetAssignment(
                            'Right-hand-side of string offset assignment cannot be mixed',
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (!TypeAnalyzer::isContainedBy(
                    $codebase,
                    $offset_type,
                    Type::getInt(),
                    true
                )) {
                    $invalid_offset_types[] = 'int';
                } else {
                    $has_valid_offset = true;
                }

                if (!$array_access_type) {
                    $array_access_type = Type::getString();
                } else {
                    $array_access_type = Type::combineUnionTypes(
                        $array_access_type,
                        Type::getString()
                    );
                }

                continue;
            }

            if ($type instanceof TMixed || $type instanceof TEmpty) {
                if ($in_assignment) {
                    if (IssueBuffer::accepts(
                        new MixedArrayAssignment(
                            'Cannot access array value on mixed variable ' . $array_var_id,
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new MixedArrayAccess(
                            'Cannot access array value on mixed variable ' . $array_var_id,
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $array_access_type = Type::getMixed();
                break;
            }

            if ($type instanceof TNamedObject) {
                if (strtolower($type->value) !== 'simplexmlelement'
                    && $codebase->classExists($type->value)
                    && !$codebase->classImplements($type->value, 'ArrayAccess')
                ) {
                    $non_array_types[] = (string)$type;
                } else {
                    $array_access_type = Type::getMixed();
                }
            } else {
                $non_array_types[] = (string)$type;
            }
        }

        if ($non_array_types) {
            if ($has_array_access) {
                if ($in_assignment) {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArrayAssignment(
                            'Cannot access array value on non-array variable ' .
                            $array_var_id . ' of type ' . $non_array_types[0],
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )
                    ) {
                        // do nothing
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new PossiblyInvalidArrayAccess(
                            'Cannot access array value on non-array variable ' .
                            $array_var_id . ' of type ' . $non_array_types[0],
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )
                    ) {
                        // do nothing
                    }
                }
            } else {
                if ($in_assignment) {
                    if (IssueBuffer::accepts(
                        new InvalidArrayAssignment(
                            'Cannot access array value on non-array variable ' .
                            $array_var_id . ' of type ' . $non_array_types[0],
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                } else {
                    if (IssueBuffer::accepts(
                        new InvalidArrayAccess(
                            'Cannot access array value on non-array variable ' .
                            $array_var_id . ' of type ' . $non_array_types[0],
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                $array_access_type = Type::getMixed();
            }
        }

        if ($offset_type->isMixed()) {
            if (IssueBuffer::accepts(
                new MixedArrayOffset(
                    'Cannot access value on variable ' . $array_var_id . ' using mixed offset',
                    new CodeLocation($statements_checker->getSource(), $stmt)
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                // fall through
            }
        } elseif ($invalid_offset_types) {
            $invalid_offset_type = $invalid_offset_types[0];

            if ($has_valid_offset) {
                if (IssueBuffer::accepts(
                    new PossiblyInvalidArrayOffset(
                        'Cannot access value on variable ' . $array_var_id . ' using ' . $offset_type
                            . ' offset, expecting ' . $invalid_offset_type,
                        new CodeLocation($statements_checker->getSource(), $stmt)
                    ),
                    $statements_checker->getSuppressedIssues()
                )) {
                    // fall through
                }
            } else {
                if (IssueBuffer::accepts(
                    new InvalidArrayOffset(
                        'Cannot access value on variable ' . $array_var_id . ' using ' . $offset_type
                            . ' offset, expecting ' . $invalid_offset_type,
                        new CodeLocation($statements_checker->getSource(), $stmt)
                    ),
                    $statements_checker->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        if ($array_access_type === null) {
            throw new \InvalidArgumentException('This is a bad place');
        }

        return $array_access_type;
    }
}
