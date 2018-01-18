<?php
namespace Psalm;

use PhpParser;
use Psalm\Checker\FileChecker;
use Psalm\Checker\ProjectChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\FileManipulation\FileManipulation;
use Psalm\Storage\ClassLikeStorage;

/**
 * Example plugins can be found in the examples folder.
 *
 * See https://github.com/vimeo/psalm/wiki/Plugins
 */
abstract class Plugin
{
    /**
     * Called after an expression has been checked,
     * but only if no errors were encountered in earlier checks by Psalm or other plugins.
     *
     * @param  StatementsChecker    $statements_checker
     * @param  PhpParser\Node\Expr  $stmt
     * @param  Context              $context
     * @param  CodeLocation         $code_location
     * @param  string[]             $suppressed_issues
     * @param  FileManipulation[]   $file_replacements
     *
     * @return null|false
     */
    public function afterExpressionCheck(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr $stmt,
        Context $context,
        CodeLocation $code_location,
        array $suppressed_issues,
        array &$file_replacements = []
    ) {
        return null;
    }

    /**
     * Called after a statement has been checked,
     * but only if no errors were encountered in earlier checks by Psalm or other plugins.
     *
     * @param  StatementsChecker                        $statements_checker
     * @param  PhpParser\Node\Stmt|PhpParser\Node\Expr  $stmt
     * @param  Context                                  $context
     * @param  CodeLocation                             $code_location
     * @param  string[]                                 $suppressed_issues
     * @param  FileManipulation[]                       $file_replacements
     *
     * @return null|false
     */
    public function afterStatementCheck(
        StatementsChecker $statements_checker,
        PhpParser\Node $stmt,
        Context $context,
        CodeLocation $code_location,
        array $suppressed_issues,
        array &$file_replacements = []
    ) {
        return null;
    }

    /**
     * This is called immediately after successfully loading a definition of a ClassLike (class, trait, or interface).
     * visitClassLike is also called on classes that the analyzed directories depend on.
     * Properties, methods, constants, etc. won't be inherited yet.
     *
     * @param  FileManipulation[] $file_replacements
     *
     * @return void
     */
    public function visitClassLike(
        PhpParser\Node\Stmt\ClassLike $stmt,
        ClassLikeStorage $storage,
        FileChecker $file_checker,
        Aliases $aliases,
        array &$file_replacements = []
    ) {
    }

    /**
     * This is called immediately after successfully loading a definition of a ClassLike (class, trait, or interface).
     * visitClassLike is also called on classes that the analyzed directories depend on.
     * Properties, methods, constants, etc. won't be inherited yet.
     *
     * @param  ProjectChecker $checker
     *
     * @return void
     */
    public function beforeAnalyzeFiles(
        ProjectChecker $checker
    ) {
    }

    /**
     * @param  string             $fq_class_name
     * @param  FileManipulation[] $file_replacements
     *
     * @return void
     */
    public function afterClassLikeExistsCheck(
        StatementsSource $statements_source,
        $fq_class_name,
        CodeLocation $code_location,
        array &$file_replacements = []
    ) {
    }
}
