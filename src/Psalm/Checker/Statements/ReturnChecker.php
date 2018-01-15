<?php
namespace Psalm\Checker\Statements;

use PhpParser;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TraitChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Exception\DocblockParseException;
use Psalm\Issue\FalsableReturnStatement;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidReturnStatement;
use Psalm\Issue\LessSpecificReturnStatement;
use Psalm\Issue\MixedReturnStatement;
use Psalm\Issue\NullableReturnStatement;
use Psalm\Issue\PossiblyInvalidReturnStatement;
use Psalm\IssueBuffer;
use Psalm\Type;

class ReturnChecker
{
    /**
     * @param  PhpParser\Node\Stmt\Return_ $stmt
     * @param  Context                     $context
     *
     * @return false|null
     */
    public static function analyze(
        StatementsChecker $statements_checker,
        ProjectChecker $project_checker,
        PhpParser\Node\Stmt\Return_ $stmt,
        Context $context
    ) {
        $doc_comment_text = (string)$stmt->getDocComment();

        $var_comment = null;

        $source = $statements_checker->getSource();

        if ($doc_comment_text) {
            try {
                $var_comment = CommentChecker::getTypeFromComment(
                    $doc_comment_text,
                    $source,
                    $source->getAliases()
                );
            } catch (DocblockParseException $e) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        (string)$e->getMessage(),
                        new CodeLocation($source, $stmt)
                    )
                )) {
                    // fall through
                }
            }

            if ($var_comment && $var_comment->var_id) {
                $comment_type = ExpressionChecker::fleshOutType(
                    $project_checker,
                    $var_comment->type,
                    $context->self
                );

                $context->vars_in_scope[$var_comment->var_id] = $comment_type;
            }
        }

        if ($stmt->expr) {
            if (ExpressionChecker::analyze($statements_checker, $stmt->expr, $context) === false) {
                return false;
            }

            if ($var_comment && !$var_comment->var_id) {
                $stmt->inferredType = $var_comment->type;
            } elseif (isset($stmt->expr->inferredType)) {
                $stmt->inferredType = $stmt->expr->inferredType;

                if ($stmt->inferredType->isVoid()) {
                    $stmt->inferredType = Type::getNull();
                }
            } else {
                $stmt->inferredType = Type::getMixed();
            }
        } else {
            $stmt->inferredType = Type::getVoid();
        }

        if ($source instanceof FunctionLikeChecker
            && !($source->getSource() instanceof TraitChecker)
        ) {
            $source->addReturnTypes($stmt->expr ? (string) $stmt->inferredType : '', $context);

            if ($stmt->expr) {
                $storage = $source->getFunctionLikeStorage($statements_checker);
                $cased_method_id = $source->getCorrectlyCasedMethodId();

                if ($storage->return_type
                    && !$storage->return_type->isMixed()
                ) {
                    $inferred_type = ExpressionChecker::fleshOutType(
                        $project_checker,
                        $stmt->inferredType,
                        $source->getFQCLN(),
                        ''
                    );

                    $local_return_type = $source->getLocalReturnType($storage->return_type);

                    if ($local_return_type->isGenerator()) {
                        return null;
                    }

                    if ($stmt->inferredType->isMixed()) {
                        if ($local_return_type->isVoid()) {
                            if (IssueBuffer::accepts(
                                new InvalidReturnStatement(
                                    'No return values are expected for ' . $cased_method_id,
                                    new CodeLocation($source, $stmt)
                                ),
                                $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }

                        if (IssueBuffer::accepts(
                            new MixedReturnStatement(
                                'Could not infer a return type',
                                new CodeLocation($source, $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }

                    if ($local_return_type->isVoid()) {
                        if (IssueBuffer::accepts(
                            new InvalidReturnStatement(
                                'No return values are expected for ' . $cased_method_id,
                                new CodeLocation($source, $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return null;
                    }

                    if (!$stmt->inferredType->ignore_nullable_issues
                        && $inferred_type->isNullable()
                        && !$local_return_type->isNullable()
                    ) {
                        if (IssueBuffer::accepts(
                            new NullableReturnStatement(
                                'The declared return type \'' . $local_return_type . '\' for '
                                    . $cased_method_id . ' is not nullable, but \'' . $inferred_type
                                    . '\' contains null',
                                new CodeLocation($source, $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    if ($inferred_type->isFalsable()
                        && !$local_return_type->isFalsable()
                        && !$local_return_type->hasBool()
                    ) {
                        if (IssueBuffer::accepts(
                            new FalsableReturnStatement(
                                'The declared return type \'' . $local_return_type . '\' for '
                                    . $cased_method_id . ' does not allow false, but \'' . $inferred_type
                                    . '\' contains false',
                                new CodeLocation($source, $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    if (!TypeChecker::isContainedBy(
                        $source->getFileChecker()->project_checker,
                        $inferred_type,
                        $local_return_type,
                        true,
                        true,
                        $has_scalar_match,
                        $type_coerced,
                        $type_coerced_from_mixed,
                        $ignored_to_string_cast,
                        $has_partial_match
                    )
                    ) {
                        // is the declared return type more specific than the inferred one?
                        if ($type_coerced) {
                            if (IssueBuffer::accepts(
                                new LessSpecificReturnStatement(
                                    'The type \'' . $stmt->inferredType . '\' is more general than the declared '
                                        . 'return type \'' . $local_return_type . '\' for ' . $cased_method_id,
                                    new CodeLocation($source, $stmt)
                                ),
                                $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        } elseif ($has_partial_match) {
                            if (IssueBuffer::accepts(
                                new PossiblyInvalidReturnStatement(
                                    'The type \'' . $stmt->inferredType . '\' does not match the declared return '
                                        . 'type \'' . $local_return_type . '\' (but has some compatible types) for ' . $cased_method_id,
                                    new CodeLocation($source, $stmt)
                                ),
                               $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new InvalidReturnStatement(
                                    'The type \'' . $stmt->inferredType . '\' does not match the declared return '
                                        . 'type \'' . $local_return_type . '\' for ' . $cased_method_id,
                                    new CodeLocation($source, $stmt)
                                ),
                                $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }
}
