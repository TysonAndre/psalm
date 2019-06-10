<?php
require_once('command_functions.php');

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\Progress\DebugProgress;
use Psalm\Progress\DefaultProgress;

// show all errors
error_reporting(-1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('memory_limit', '8192M');

gc_collect_cycles();
gc_disable();

$args = array_slice($argv, 1);

$valid_short_options = ['f:', 'm', 'h', 'r:'];
$valid_long_options = [
    'help', 'debug', 'config:', 'root:',
    'threads:', 'move:', 'into:', 'rename:', 'to:',
];

// get options from command line
$options = getopt(implode('', $valid_short_options), $valid_long_options);

array_map(
    /**
     * @param string $arg
     *
     * @return void
     */
    function ($arg) use ($valid_long_options, $valid_short_options) {
        if (substr($arg, 0, 2) === '--' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 2));

            if ($arg_name === 'refactor') {
                // valid option for psalm, ignored by psalter
                return;
            }

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
        } elseif (substr($arg, 0, 2) === '-' && $arg !== '-' && $arg !== '--') {
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

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    die('Too many config files provided' . PHP_EOL);
}

if (array_key_exists('h', $options)) {
    echo <<< HELP
Usage:
    psalm-refactor [options] [symbol1] into [symbol2]

Options:
    -h, --help
        Display this help message

    --debug, --debug-by-line
        Debug information

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --threads=auto
        If greater than one, Psalm will run analysis on multiple threads, speeding things up.
        By default

    --move "[Identifier]" --into "[Class]"
        Moves the specified item into the class. More than one item can be moved into a class
        by passing a comma-separated list of values e.g.

        --move "Ns\Foo::bar,Ns\Foo::baz" --into "Biz\Bang\DestinationClass"

    --rename "[Identifier]" --to "[NewIdentifier]"
        Renames a specfied item (e.g. method) and updates all references to it that Psalm can
        identify.

HELP;

    exit;
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$current_dir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $root_path = realpath($options['r']);

    if (!$root_path) {
        die('Could not locate root directory ' . $current_dir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL);
    }

    $current_dir = $root_path . DIRECTORY_SEPARATOR;
}

$vendor_dir = getVendorDir($current_dir);

$first_autoloader = requireAutoloaders($current_dir, isset($options['r']), $vendor_dir);

// If XDebug is enabled, restart without it
(new \Composer\XdebugHandler\XdebugHandler('PSALTER'))->check();

$path_to_config = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

if ($path_to_config === false) {
    /** @psalm-suppress InvalidCast */
    die('Could not resolve path to config ' . (string)$options['c'] . PHP_EOL);
}

$args = getArguments();

$operation = null;
$last_arg = null;

$to_refactor = [];

foreach ($args as $arg) {
    if ($arg === '--move') {
        $operation = 'move';
        continue;
    }

    if ($arg === '--into') {
        if ($operation !== 'move' || !$last_arg) {
            die('--into is not expected here' . PHP_EOL);
        }

        $operation = 'move_into';
        continue;
    }

    if ($arg === '--rename') {
        $operation = 'rename';
        continue;
    }

    if ($arg === '--to') {
        if ($operation !== 'rename' || !$last_arg) {
            die('--to is not expected here' . PHP_EOL);
        }

        $operation = 'rename_to';

        continue;
    }

    if ($arg[0] === '-') {
        $operation = null;
        continue;
    }

    if ($operation === 'move_into' || $operation === 'rename_to') {
        if (!$last_arg) {
            die('Expecting a previous argument' . PHP_EOL);
        }

        if ($operation === 'move_into') {
            $last_arg_parts = preg_split('/, ?/', $last_arg);

            foreach ($last_arg_parts as $last_arg_part) {
                if (strpos($last_arg_part, '::')) {
                    list(, $identifier_name) = explode('::', $last_arg_part);
                    $to_refactor[$last_arg_part] = $arg . '::' . $identifier_name;
                } else {
                    $namespace_parts = explode('\\', $last_arg_part);
                    $class_name = end($namespace_parts);
                    $to_refactor[$last_arg_part] = $arg . '\\' . $class_name;
                }
            }
        } else {
            $to_refactor[$last_arg] = $arg;
        }

        $last_arg = null;
        $operation = null;
        continue;
    }

    if ($operation === 'move' || $operation === 'rename') {
        $last_arg = $arg;

        continue;
    }

    die('Unexpected argument "' . $arg . '"' . PHP_EOL);
}

if (!$to_refactor) {
    die('No --move or --rename arguments supplied' . PHP_EOL);
}

// initialise custom config, if passed
// Initializing the config can be slow, so any UI logic should precede it, if possible
if ($path_to_config) {
    $config = Config::loadFromXMLFile($path_to_config, $current_dir);
} else {
    $config = Config::getConfigForPath($current_dir, $current_dir, \Psalm\Report::TYPE_CONSOLE);
}

$config->setComposerClassLoader($first_autoloader);

$threads = isset($options['threads'])
    ? (int)$options['threads']
    : max(1, ProjectAnalyzer::getCpuCount() - 2);

$providers = new Psalm\Internal\Provider\Providers(
    new Psalm\Internal\Provider\FileProvider(),
    new Psalm\Internal\Provider\ParserCacheProvider($config),
    new Psalm\Internal\Provider\FileStorageCacheProvider($config),
    new Psalm\Internal\Provider\ClassLikeStorageCacheProvider($config)
);

$debug = array_key_exists('debug', $options);
$progress = $debug
    ? new DebugProgress()
    : new DefaultProgress();

$project_analyzer = new ProjectAnalyzer(
    $config,
    $providers,
    new \Psalm\Report\ReportOptions(),
    [],
    $threads,
    $progress
);

$config->visitComposerAutoloadFiles($project_analyzer);

$project_analyzer->refactorCodeAfterCompletion($to_refactor);

$start_time = microtime(true);

$project_analyzer->check($current_dir);

IssueBuffer::finish($project_analyzer, false, $start_time);
