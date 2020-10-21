<?php
namespace Psalm\Tests;

use Psalm\Internal\Analyzer\CommentAnalyzer;

class CommentAnalyzerTest extends TestCase
{
    /** @return void */
    public function testParseDocComment()
    {
        $input_doc_comment = <<<'EOT'
   /**
    * Description
    *  Rest of description
    * @param int $value
    */
EOT;
        $expected_doc_comment = <<<'EOT'
Description
 Rest of description
EOT;
        $actual = CommentAnalyzer::parseDocComment($input_doc_comment);
        $expected = [
            'description' => $expected_doc_comment,
            'specials' => [
                'param' => [
                    'int $value',
                ],
            ],
        ];
        $this->assertSame($expected, $actual, 'Expected whitespace and leading comment text to be trimmed');
    }
}
