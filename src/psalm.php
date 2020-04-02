<?php
require_once('command_functions.php');

use Psalm\ErrorBaseline;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\Provider;
use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\Progress\DebugProgress;
use Psalm\Progress\DefaultProgress;
use Psalm\Progress\LongProgress;
use Psalm\Progress\VoidProgress;

// show all errors
error_reporting(-1);

$valid_short_options = [
    'f:',
    'm',
    'h',
    'v',
    'c:',
    'i',
    'r:',
];

$valid_long_options = [
    'clear-cache',
    'clear-global-cache',
    'config:',
    'debug',
    'debug-by-line',
    'diff',
    'diff-methods',
    'disable-extension:',
    'find-dead-code::',
    'find-unused-code::',
    'find-unused-variables',
    'find-references-to:',
    'help',
    'ignore-baseline',
    'init',
    'monochrome',
    'no-cache',
    'no-reflection-cache',
    'no-vendor-autoloader',
    'output-format:',
    'plugin:',
    'report:',
    'report-show-info:',
    'root:',
    'set-baseline:',
    'show-info:',
    'show-snippet:',
    'stats',
    'threads:',
    'update-baseline',
    'use-ini-defaults',
    'version',
    'php-version:',
    'generate-json-map:',
    'generate-stubs:',
    'alter',
    'language-server',
    'refactor',
    'shepherd::',
    'no-progress',
    'long-progress',
    'no-suggestions',
    'include-php-versions', // used for baseline
    'track-tainted-input',
    'find-unused-psalm-suppress',
    'error-level:',
];

gc_collect_cycles();
gc_disable();

$args = array_slice($argv, 1);

// get options from command line
$options = getopt(implode('', $valid_short_options), $valid_long_options);

if (isset($options['alter'])) {
    require_once __DIR__ . '/psalter.php';
    exit;
}

if (isset($options['language-server'])) {
    require_once __DIR__ . '/psalm-language-server.php';
    exit;
}

if (isset($options['refactor'])) {
    require_once __DIR__ . '/psalm-refactor.php';
    exit;
}

require_once __DIR__ . '/Psalm/Internal/exception_handler.php';

array_map(
    /**
     * @param string $arg
     *
     * @return void
     */
    function ($arg) use ($valid_long_options, $valid_short_options) {
        if (substr($arg, 0, 2) === '--' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 2));

            if (!in_array($arg_name, $valid_long_options)
                && !in_array($arg_name . ':', $valid_long_options)
                && !in_array($arg_name . '::', $valid_long_options)
            ) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "--' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        } elseif (substr($arg, 0, 1) === '-' && $arg !== '-' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 1));

            if (!in_array($arg_name, $valid_short_options) && !in_array($arg_name . ':', $valid_short_options)) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "-' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        }
    },
    $args
);

if (!array_key_exists('use-ini-defaults', $options)) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('memory_limit', (string) (8 * 1024 * 1024 * 1024));
}

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (array_key_exists('version', $options)) {
    $options['v'] = false;
}

if (array_key_exists('init', $options)) {
    $options['i'] = false;
}

if (array_key_exists('monochrome', $options)) {
    $options['m'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    fwrite(STDERR, 'Too many config files provided' . PHP_EOL);
    exit(1);
}


if (array_key_exists('h', $options)) {
    echo getPsalmHelpText();
    /*
    --shepherd[=host]
        Send data to Shepherd, Psalm's GitHub integration tool.
        `host` is the location of the Shepherd server. It defaults to shepherd.dev
        More information is available at https://psalm.dev/shepherd
    */

    exit;
}

if (getcwd() === false) {
    fwrite(STDERR, 'Cannot get current working directory' . PHP_EOL);
    exit(1);
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$current_dir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $root_path = realpath($options['r']);

    if (!$root_path) {
        fwrite(
            STDERR,
            'Could not locate root directory ' . $current_dir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL
        );
        exit(1);
    }

    $current_dir = $root_path . DIRECTORY_SEPARATOR;
}

$path_to_config = get_path_to_config($options);

$vendor_dir = getVendorDir($current_dir);

$first_autoloader = requireAutoloaders($current_dir, isset($options['r']), $vendor_dir, isset($options['no-vendor-autoloader']));


if (array_key_exists('v', $options)) {
    echo 'Psalm ' . PSALM_VERSION . PHP_EOL;
    exit;
}

$output_format = isset($options['output-format']) && is_string($options['output-format'])
    ? $options['output-format']
    : \Psalm\Report::TYPE_CONSOLE;

$init_level = null;
$init_source_dir = null;

if (isset($options['i'])) {
    if (file_exists($current_dir . 'psalm.xml')) {
        die('A config file already exists in the current directory' . PHP_EOL);
    }

    $args = array_values(array_filter(
        $args,
        /**
         * @param string $arg
         *
         * @return bool
         */
        function ($arg) {
            return $arg !== '--ansi'
                && $arg !== '--no-ansi'
                && $arg !== '-i'
                && $arg !== '--init'
                && $arg !== '--debug'
                && $arg !== '--debug-by-line'
                && strpos($arg, '--disable-extension=') !== 0
                && strpos($arg, '--root=') !== 0
                && strpos($arg, '--r=') !== 0;
        }
    ));

    if (count($args)) {
        if (count($args) > 2) {
            die('Too many arguments provided for psalm --init' . PHP_EOL);
        }

        if (isset($args[1])) {
            if (!preg_match('/^[1-8]$/', $args[1])) {
                die('Config strictness must be a number between 1 and 8 inclusive' . PHP_EOL);
            }

            $init_level = (int)$args[1];
        }

        $init_source_dir = $args[0];
    }

    $vendor_dir = getVendorDir($current_dir);

    if ($init_level === null) {
        echo "Calculating best config level based on project files\n";
        Psalm\Config\Creator::createBareConfig($current_dir, $init_source_dir, $vendor_dir);
        $config = \Psalm\Config::getInstance();
    } else {
        try {
            $template_contents = Psalm\Config\Creator::getContents(
                $current_dir,
                $init_source_dir,
                $init_level,
                $vendor_dir
            );
        } catch (Psalm\Exception\ConfigCreationException $e) {
            die($e->getMessage() . PHP_EOL);
        }

        if (!file_put_contents($current_dir . 'psalm.xml', $template_contents)) {
            die('Could not write to psalm.xml' . PHP_EOL);
        }

        exit('Config file created successfully. Please re-run psalm.' . PHP_EOL);
    }
} else {
    $config = initialiseConfig($path_to_config, $current_dir, $output_format, $first_autoloader);

    if (isset($options['error-level'])
        && is_numeric($options['error-level'])
    ) {
        $config_level = (int) $options['error-level'];

        if (!in_array($config_level, [1, 2, 3, 4, 5, 6, 7, 8], true)) {
            throw new \Psalm\Exception\ConfigException(
                'Invalid error level ' . $config_level
            );
        }

        $config->level = $config_level;
    }
}

if ($config->resolve_from_config_file) {
    $current_dir = $config->base_dir;
    chdir($current_dir);
}

$in_ci = isset($_SERVER['TRAVIS'])
    || isset($_SERVER['CIRCLECI'])
    || isset($_SERVER['APPVEYOR'])
    || isset($_SERVER['JENKINS_URL'])
    || isset($_SERVER['SCRUTINIZER'])
    || isset($_SERVER['GITLAB_CI'])
    || isset($_SERVER['GITHUB_WORKFLOW']);

// disable progressbar on CI
if ($in_ci) {
    $options['long-progress'] = true;
}

if (isset($options['threads'])) {
    $threads = (int)$options['threads'];
} elseif (isset($options['debug']) || $in_ci) {
    $threads = 1;
} else {
    $threads = max(1, ProjectAnalyzer::getCpuCount() - 1);
}

if (!isset($options['threads'])
    && !isset($options['debug'])
    && $threads === 1
    && ini_get('pcre.jit') === '1'
    && PHP_OS === 'Darwin'
    && version_compare(PHP_VERSION, '7.3.0') >= 0
    && version_compare(PHP_VERSION, '7.4.0') < 0
) {
    echo(
        'If you want to run Psalm as a language server, or run Psalm with' . PHP_EOL
            . 'multiple processes (--threads=4), beware:' . PHP_EOL
            . \Psalm\Internal\Fork\Pool::MAC_PCRE_MESSAGE . PHP_EOL . PHP_EOL
    );
}

$ini_handler = new \Psalm\Internal\Fork\PsalmRestarter('PSALM');

if (isset($options['disable-extension'])) {
    if (is_array($options['disable-extension'])) {
        /** @psalm-suppress MixedAssignment */
        foreach ($options['disable-extension'] as $extension) {
            if (is_string($extension)) {
                $ini_handler->disableExtension($extension);
            }
        }
    } elseif (is_string($options['disable-extension'])) {
        $ini_handler->disableExtension($options['disable-extension']);
    }
}

if ($threads > 1) {
    $ini_handler->disableExtension('grpc');
}

$ini_handler->disableExtension('uopz');

$type_map_location = null;

if (isset($options['generate-json-map']) && is_string($options['generate-json-map'])) {
    $type_map_location = $options['generate-json-map'];
}

$stubs_location = null;

if (isset($options['generate-stubs']) && is_string($options['generate-stubs'])) {
    $stubs_location = $options['generate-stubs'];
}

// If Xdebug is enabled, restart without it
$ini_handler->check();

if (is_null($config->load_xdebug_stub) && '' !== $ini_handler->getSkippedVersion()) {
    $config->load_xdebug_stub = true;
}

setlocale(LC_CTYPE, 'C');

if (isset($options['set-baseline'])) {
    if (is_array($options['set-baseline'])) {
        die('Only one baseline file can be created at a time' . PHP_EOL);
    }
}

$paths_to_check = getPathsToCheck(isset($options['f']) ? $options['f'] : null);

$plugins = [];

if (isset($options['plugin'])) {
    $plugins = $options['plugin'];

    if (!is_array($plugins)) {
        $plugins = [$plugins];
    }
}



$show_info = isset($options['show-info'])
    ? $options['show-info'] === 'true' || $options['show-info'] === '1'
    : false;

$is_diff = isset($options['diff']);

/** @var false|'always'|'auto' $find_unused_code */
$find_unused_code = false;
if (isset($options['find-dead-code'])) {
    $options['find-unused-code'] = $options['find-dead-code'];
}

if (isset($options['find-unused-code'])) {
    if ($options['find-unused-code'] === 'always') {
        $find_unused_code = 'always';
    } else {
        $find_unused_code = 'auto';
    }
}

$find_unused_variables = isset($options['find-unused-variables']);

$find_references_to = isset($options['find-references-to']) && is_string($options['find-references-to'])
    ? $options['find-references-to']
    : null;

if (isset($options['shepherd'])) {
    if (is_string($options['shepherd'])) {
        $config->shepherd_host = $options['shepherd'];
    }
    $shepherd_plugin = __DIR__ . '/Psalm/Plugin/Shepherd.php';

    if (!file_exists($shepherd_plugin)) {
        die('Could not find Shepherd plugin location ' . $shepherd_plugin . PHP_EOL);
    }

    $plugins[] = $shepherd_plugin;
}

if (isset($options['clear-cache'])) {
    $cache_directory = $config->getCacheDirectory();

    Config::removeCacheDirectory($cache_directory);
    echo 'Cache directory deleted' . PHP_EOL;
    exit;
}

if (isset($options['clear-global-cache'])) {
    $cache_directory = $config->getGlobalCacheDirectory();

    if ($cache_directory) {
        Config::removeCacheDirectory($cache_directory);
        echo 'Global cache directory deleted' . PHP_EOL;
    }

    exit;
}

$debug = array_key_exists('debug', $options) || array_key_exists('debug-by-line', $options);

if ($debug) {
    $progress = new DebugProgress();
} elseif (isset($options['no-progress'])) {
    $progress = new VoidProgress();
} else {
    $show_errors = !$config->error_baseline || isset($options['ignore-baseline']);
    if (isset($options['long-progress'])) {
        $progress = new LongProgress($show_errors, $show_info);
    } else {
        $progress = new DefaultProgress($show_errors, $show_info);
    }
}

if (isset($options['no-cache']) || isset($options['i'])) {
    $providers = new Provider\Providers(
        new Provider\FileProvider
    );
} else {
    $no_reflection_cache = isset($options['no-reflection-cache']);

    $file_storage_cache_provider = $no_reflection_cache
        ? null
        : new Provider\FileStorageCacheProvider($config);

    $classlike_storage_cache_provider = $no_reflection_cache
        ? null
        : new Provider\ClassLikeStorageCacheProvider($config);

    $providers = new Provider\Providers(
        new Provider\FileProvider,
        new Provider\ParserCacheProvider($config),
        $file_storage_cache_provider,
        $classlike_storage_cache_provider,
        new Provider\FileReferenceCacheProvider($config)
    );
}

$stdout_report_options = new \Psalm\Report\ReportOptions();
$stdout_report_options->use_color = !array_key_exists('m', $options);
$stdout_report_options->show_info = $show_info;
$stdout_report_options->show_suggestions = !array_key_exists('no-suggestions', $options);
/**
 * @psalm-suppress PropertyTypeCoercion
 */
$stdout_report_options->format = $output_format;
$stdout_report_options->show_snippet = !isset($options['show-snippet']) || $options['show-snippet'] !== "false";

$project_analyzer = new ProjectAnalyzer(
    $config,
    $providers,
    $stdout_report_options,
    ProjectAnalyzer::getFileReportOptions(
        isset($options['report']) && is_string($options['report']) ? [$options['report']] : [],
        isset($options['report-show-info'])
            ? $options['report-show-info'] !== 'false' && $options['report-show-info'] !== '0'
            : true
    ),
    $threads,
    $progress
);

if (!isset($options['php-version'])) {
    $options['php-version'] = $config->getPhpVersion();
}

if (isset($options['php-version'])) {
    if (!is_string($options['php-version'])) {
        die('Expecting a version number in the format x.y' . PHP_EOL);
    }

    $project_analyzer->setPhpVersion($options['php-version']);
}

if ($type_map_location) {
    $project_analyzer->getCodebase()->store_node_types = true;
}

$start_time = microtime(true);

if (array_key_exists('debug-by-line', $options)) {
    $project_analyzer->debug_lines = true;
}

if ($config->find_unused_code) {
    $find_unused_code = 'auto';
}

if ($find_references_to !== null) {
    $project_analyzer->getCodebase()->collectLocations();
    $project_analyzer->show_issues = false;
}

if ($find_unused_code) {
    $project_analyzer->getCodebase()->reportUnusedCode($find_unused_code);
}

if ($config->find_unused_variables || $find_unused_variables) {
    $project_analyzer->getCodebase()->reportUnusedVariables();
}

if (isset($options['track-tainted-input'])) {
    $project_analyzer->trackTaintedInputs();
}

if (isset($options['find-unused-psalm-suppress'])) {
    $project_analyzer->trackUnusedSuppressions();
}

/** @var string $plugin_path */
foreach ($plugins as $plugin_path) {
    $config->addPluginPath($plugin_path);
}

if ($paths_to_check === null) {
    $project_analyzer->check($current_dir, $is_diff);
} elseif ($paths_to_check) {
    $project_analyzer->checkPaths($paths_to_check);
}

if ($find_references_to) {
    $project_analyzer->findReferencesTo($find_references_to);
}

if (isset($options['set-baseline']) && is_string($options['set-baseline'])) {
    if ($is_diff) {
        fwrite(STDERR, 'Cannot set baseline in --diff mode' . PHP_EOL);
    } else {
        fwrite(STDERR, 'Writing error baseline to file...' . PHP_EOL);

        ErrorBaseline::create(
            new \Psalm\Internal\Provider\FileProvider,
            $options['set-baseline'],
            IssueBuffer::getIssuesData(),
            $config->include_php_versions_in_error_baseline || isset($options['include-php-versions'])
        );

        fwrite(STDERR, "Baseline saved to {$options['set-baseline']}.");

        update_config_file(
            $config,
            $path_to_config ?? $current_dir,
            $options['set-baseline']
        );

        fwrite(STDERR, PHP_EOL);
    }
}

$issue_baseline = [];

if (isset($options['update-baseline'])) {
    if ($is_diff) {
        fwrite(STDERR, 'Cannot update baseline in --diff mode' . PHP_EOL);
    } else {
        $baselineFile = Config::getInstance()->error_baseline;

        if (empty($baselineFile)) {
            die('Cannot update baseline, because no baseline file is configured.' . PHP_EOL);
        }

        try {
            $issue_current_baseline = ErrorBaseline::read(
                new \Psalm\Internal\Provider\FileProvider,
                $baselineFile
            );
            $total_issues_current_baseline = ErrorBaseline::countTotalIssues($issue_current_baseline);

            $issue_baseline = ErrorBaseline::update(
                new \Psalm\Internal\Provider\FileProvider,
                $baselineFile,
                IssueBuffer::getIssuesData(),
                $config->include_php_versions_in_error_baseline || isset($options['include-php-versions'])
            );
            $total_issues_updated_baseline = ErrorBaseline::countTotalIssues($issue_baseline);

            $total_fixed_issues = $total_issues_current_baseline - $total_issues_updated_baseline;

            if ($total_fixed_issues > 0) {
                echo str_repeat('-', 30) . "\n";
                echo $total_fixed_issues . ' errors fixed' . "\n";
            }
        } catch (\Psalm\Exception\ConfigException $exception) {
            fwrite(STDERR, 'Could not update baseline file: ' . $exception->getMessage() . PHP_EOL);
            exit(1);
        }
    }
}

if (!empty(Config::getInstance()->error_baseline) && !isset($options['ignore-baseline'])) {
    try {
        $issue_baseline = ErrorBaseline::read(
            new \Psalm\Internal\Provider\FileProvider,
            (string)Config::getInstance()->error_baseline
        );
    } catch (\Psalm\Exception\ConfigException $exception) {
        fwrite(STDERR, 'Error while reading baseline: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($type_map_location) {
    $file_map = $providers->file_reference_provider->getFileMaps();

    $name_file_map = [];

    $expected_references = [];

    foreach ($file_map as $file_path => $map) {
        $file_name = $config->shortenFileName($file_path);
        foreach ($map[0] as $map_parts) {
            $expected_references[$map_parts[1]] = true;
        }
        $map[2] = [];
        $name_file_map[$file_name] = $map;
    }

    $reference_dictionary = \Psalm\Internal\Codebase\ReferenceMapGenerator::getReferenceMap(
        $providers->classlike_storage_provider,
        $expected_references
    );

    $type_map_string = json_encode(['files' => $name_file_map, 'references' => $reference_dictionary]);

    $providers->file_provider->setContents(
        $type_map_location,
        $type_map_string
    );
}

if ($stubs_location) {
    $providers->file_provider->setContents(
        $stubs_location,
        \Psalm\Internal\Stubs\Generator\StubsGenerator::getAll(
            $project_analyzer->getCodebase(),
            $providers->classlike_storage_provider,
            $providers->file_storage_provider
        )
    );
}

if (!isset($options['i'])) {
    IssueBuffer::finish(
        $project_analyzer,
        !$paths_to_check,
        $start_time,
        isset($options['stats']),
        $issue_baseline
    );
} else {
    $issues_by_file = IssueBuffer::getIssuesData();

    if (!$issues_by_file) {
        $init_level = 1;
    } else {
        $codebase = $project_analyzer->getCodebase();
        $mixed_counts = $codebase->analyzer->getTotalTypeCoverage($codebase);

        $init_level = \Psalm\Config\Creator::getLevel(
            array_merge(...array_values($issues_by_file)),
            (int) array_sum($mixed_counts)
        );
    }

    echo "\n" . 'Detected level ' . $init_level . ' as a suitable initial default' . "\n";

    try {
        $template_contents = Psalm\Config\Creator::getContents(
            $current_dir,
            $init_source_dir,
            $init_level,
            $vendor_dir
        );
    } catch (Psalm\Exception\ConfigCreationException $e) {
        die($e->getMessage() . PHP_EOL);
    }

    if (!file_put_contents($current_dir . 'psalm.xml', $template_contents)) {
        die('Could not write to psalm.xml' . PHP_EOL);
    }

    exit('Config file created successfully. Please re-run psalm.' . PHP_EOL);
}
