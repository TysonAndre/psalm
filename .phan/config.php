<?php

use \Phan\Issue;

/**
 * This configuration will be read and overlayed on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */
return [

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    "allow_missing_properties" => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    "null_casts_as_any_type" => false,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    'scalar_implicit_cast' => false,

    // If true, seemingly undeclared variables in the global
    // scope will be ignored. This is useful for projects
    // with complicated cross-file globals that you have no
    // hope of fixing.
    'ignore_undeclared_variables_in_global_scope' => false,

    // Backwards Compatibility Checking
    'backward_compatibility_checks' => false,

    // If enabled, check all methods that override a
    // parent method to make sure its signature is
    // compatible with the parent's. This check
    // can add quite a bit of time to the analysis.
    'analyze_signature_compatibility' => true,

    // Set to true in order to attempt to detect dead
    // (unreferenced) code. Keep in mind that the
    // results will only be a guess given that classes,
    // properties, constants and methods can be referenced
    // as variables (like `$class->$property` or
    // `$class->$method()`) in ways that we're unable
    // to make sense of.
    'dead_code_detection' => false,

    // Run a quick version of checks that takes less
    // time
    "quick_mode" => false,

    // Enable or disable support for generic templated
    // class types.
    'generic_types_enabled' => true,

    // The minimum severity level to report on. This can be
    // set to Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL or
    // Issue::SEVERITY_CRITICAL.
    'minimum_severity' => Issue::SEVERITY_LOW,

    // Add any issue types (such as 'PhanUndeclaredMethod')
    // here to inhibit them from being reported
    'suppress_issue_types' => [
        'PhanUndeclaredProperty',  // PhpParser dynamic properties, let Psalm check that
        // 'PhanUndeclaredMethod',
    ],

    // A list of files to include in analysis
    'file_list' => [
        // 'vendor/phpunit/phpunit/src/Framework/TestCase.php',
    ],

    // A file list that defines files that will be excluded
    // from parsing and analysis and will not be read at all.
    //
    // This is useful for excluding hopelessly unanalyzable
    // files that can't be removed for whatever reason.
    'exclude_file_list' => [
        'src/Psalm/CallMap.php',
    ],
    'exclude_file_regex' => '@^(vendor/.*/(tests|Tests)/|src/Psalm/Stubs/)@',

    // The number of processes to fork off during the analysis
    // phase.
    'processes' => 1,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    'directory_list' => [
        'vendor/composer/composer/src/Composer',
        'vendor/muglug/package-versions-56/src/PackageVersions',
        'vendor/nikic/php-parser/lib/PhpParser',
        'vendor/openlss/lib-array2xml',
        'vendor/php-cs-fixer/diff/src',
        'vendor/friendsofphp/php-cs-fixer/src',
        'src',
        'examples',
    ],

    // A directory list that defines files that will be excluded
    // from static analysis, but whose class and method
    // information should be included.
    //
    // Generally, you'll want to include the directories for
    // third-party code (such as "vendor/") in this list.
    //
    // n.b.: If you'd like to parse but not analyze 3rd
    //       party code, directories containing that code
    //       should be added to the `directory_list` as
    //       to `exclude_analysis_directory_list`.
    "exclude_analysis_directory_list" => [
        'vendor',
    ],

    // A list of plugin files to execute
    'plugins' => [
        'AlwaysReturnPlugin',
        'DemoPlugin',
        'DollarDollarPlugin',
        'UnreachableCodePlugin',
        // NOTE: src/Phan/Language/Internal/FunctionSignatureMap.php mixes value without keys (as return type) with values having keys deliberately.
        'DuplicateArrayKeyPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
    ],

];
