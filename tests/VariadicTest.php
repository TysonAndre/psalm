<?php
namespace Psalm\Tests;

use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\IncludeCollector;
use Psalm\Tests\Internal\Provider;
use function dirname;
use function getcwd;

class VariadicTest extends TestCase
{
    use Traits\ValidCodeAnalysisTestTrait;

    /**
     * @return                   void
     */
    public function testVariadicArrayBadParam()
    {
        $this->expectExceptionMessage('InvalidScalarArgument');
        $this->expectException(\Psalm\Exception\CodeException::class);
        $this->addFile(
            'somefile.php',
            '<?php
                /**
                 * @param int ...$a_list
                 * @return void
                 */
                function f(int ...$a_list) {
                }
                f(1, 2, "3");'
        );

        $this->analyzeFile('somefile.php', new Context());
    }

    /**
     * @throws \Psalm\Exception\ConfigException
     * @return void
     * @runInSeparateProcess
     */
    public function testVariadicFunctionFromAutoloadFile()
    {
        $this->project_analyzer = $this->getProjectAnalyzerWithConfig(
            TestConfig::loadFromXML(
                dirname(__DIR__),
                '<?xml version="1.0"?>
                <psalm
                    autoloader="tests/fixtures/stubs/custom_functions.php"
                >
                    <projectFiles>
                        <directory name="src" />
                    </projectFiles>
                </psalm>'
            )
        );

        $file_path = getcwd() . '/src/somefile.php';

        $this->addFile(
            $file_path,
            '<?php
                variadic2(16, 30);
            '
        );

        $this->analyzeFile($file_path, new Context());
    }

    /**
     * @return iterable<string,array{string,1?:array<string,string>,2?:string[]}>
     */
    public function providerValidCodeParse()
    {
        return [
            'variadic' => [
                '<?php
                /**
                 * @param mixed $req
                 * @param mixed $opt
                 * @param mixed ...$params
                 * @return array<mixed>
                 */
                function f($req, $opt = null, ...$params) {
                    return $params;
                }

                f(1);
                f(1, 2);
                f(1, 2, 3);
                f(1, 2, 3, 4);
                f(1, 2, 3, 4, 5);',
            ],
            'funcNumArgsVariadic' => [
                '<?php
                    function test(): array {
                        return func_get_args();
                    }
                    var_export(test(2));',
            ],
            'variadicArray' => [
                '<?php
                    /**
                     * @param int ...$a_list
                     * @return array<int, int>
                     */
                    function f(int ...$a_list) {
                        return array_map(
                            /**
                             * @return int
                             */
                            function (int $a) {
                                return $a + 1;
                            },
                            $a_list
                        );
                    }

                    f(1);
                    f(1, 2);
                    f(1, 2, 3);

                    /**
                     * @param string ...$a_list
                     * @return void
                     */
                    function g(string ...$a_list) {
                    }',
            ],
        ];
    }

    /**
     * @param  Config $config
     *
     * @return \Psalm\Internal\Analyzer\ProjectAnalyzer
     */
    private function getProjectAnalyzerWithConfig(Config $config)
    {
        $project_analyzer = new \Psalm\Internal\Analyzer\ProjectAnalyzer(
            $config,
            new \Psalm\Internal\Provider\Providers(
                $this->file_provider,
                new Provider\FakeParserCacheProvider()
            )
        );
        $project_analyzer->setPhpVersion('7.3');

        $config->setIncludeCollector(new IncludeCollector());
        $config->visitComposerAutoloadFiles($project_analyzer, null);

        return $project_analyzer;
    }
}
