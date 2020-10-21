<?php
namespace Psalm\Plugin\Hook;

use Psalm\Internal\Analyzer\ProjectAnalyzer;

interface BeforeAnalyzeFilesInterface
{
    /**
     * @return void
     */
    public static function beforeAnalyzeFiles(
        ProjectAnalyzer $project_analyzer
    );
}
