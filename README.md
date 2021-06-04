<h1>Psalm</h1>

[![Packagist](https://img.shields.io/packagist/v/vimeo/psalm.svg)](https://packagist.org/packages/vimeo/psalm)
[![Packagist](https://img.shields.io/packagist/dt/vimeo/psalm.svg)](https://packagist.org/packages/vimeo/psalm)
[![Coverage Status](https://coveralls.io/repos/github/vimeo/psalm/badge.svg)](https://coveralls.io/github/vimeo/psalm)
![Psalm coverage](https://shepherd.dev/github/vimeo/psalm/coverage.svg?)
[![Psalm level](https://shepherd.dev/github/vimeo/psalm/level.svg?)](https://psalm.dev/)

Psalm is a static analysis tool for finding errors in PHP applications.

## Installation

To get started, check out the [installation guide](docs/running_psalm/installation.md).

## Details about this fork

This was customized for a narrow use case. It differs from the upstream repo psalm/psalm in the following ways:

- Hardcoded support for [runkit/runkit7 superglobals](https://secure.php.net/manual/en/runkit.configuration.php#ini.runkit.superglobal).
  Right now, it hardcodes the assumption that `$_TAG` is a superglobal of the union type `tag_global`
  That can easily be changed to be configurable.
- PossiblyNullReference is disabled for nullable union types containing `User`
- Error tolerance. This will proceed with analysis in many cases, whether or not a previous error was suppressed.
- It adds `--no-vendor-autoloader` (may be broadly useful)

# Psalm documentation

## Live Demo

You can play around with Psalm [on its website](https://psalm.dev/).

## Documentation

Documentation is available on [Psalm’s website](https://psalm.dev/docs), generated from the [docs](https://github.com/vimeo/psalm/blob/master/docs) folder.

## Interested in contributing?

Have a look at [CONTRIBUTING.md](CONTRIBUTING.md).

## Who made this

Built by Matt Brown ([@muglug](https://github.com/muglug)).

Maintained by Matt and Bruce Weirdan ([@weirdan](https://github.com/weirdan)).

The engineering team at [Vimeo](https://github.com/vimeo) have provided a lot encouragement, especially [@nbeliard](https://github.com/nbeliard), [@erunion](https://github.com/erunion) and [@nickyr](https://github.com/nickyr).
