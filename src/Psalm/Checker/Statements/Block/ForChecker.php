<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Context;
use Psalm\Type;

class ForChecker
{
    /**
     * @param   StatementsChecker           $statements_checker
     * @param   PhpParser\Node\Stmt\For_    $stmt
     * @param   Context                     $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\For_ $stmt,
        Context $context
    ) {
        foreach ($stmt->init as $init) {
            if (ExpressionChecker::analyze($statements_checker, $init, $context) === false) {
                return false;
            }
        }

        $for_context = clone $context;
        $before_context = clone $context;
        $for_context->inside_loop = true;

        foreach ($stmt->cond as $condition) {
            $for_context->inside_conditional = true;
            if (ExpressionChecker::analyze($statements_checker, $condition, $for_context) === false) {
                return false;
            }
            $for_context->inside_conditional = false;
        }

        $changed_vars = Context::getNewOrUpdatedVarIds($before_context, $for_context);

        $statements_checker->analyzeLoop($stmt->stmts, $changed_vars, $for_context, $context);

        foreach ($stmt->loop as $expr) {
            if (ExpressionChecker::analyze($statements_checker, $expr, $for_context) === false) {
                return false;
            }
        }

        foreach ($context->vars_in_scope as $var => $type) {
            if ($type->isMixed() || !isset($for_context->vars_in_scope[$var])) {
                continue;
            }

            if ($for_context->vars_in_scope[$var]->isMixed()) {
                $context->vars_in_scope[$var] = $for_context->vars_in_scope[$var];
            }

            if ((string) $for_context->vars_in_scope[$var] !== (string) $type) {
                $context->vars_in_scope[$var] = Type::combineUnionTypes(
                    $context->vars_in_scope[$var],
                    $for_context->vars_in_scope[$var]
                );

                $context->removeVarFromConflictingClauses($var);
            }
        }

        $context->vars_possibly_in_scope = array_merge(
            $for_context->vars_possibly_in_scope,
            $context->vars_possibly_in_scope
        );

        $context->referenced_var_ids = array_merge(
            $for_context->referenced_var_ids,
            $context->referenced_var_ids
        );

        return null;
    }
}
