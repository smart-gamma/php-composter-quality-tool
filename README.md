# PHP Composter: Smart Gamma Quality tool

The tool allows automaticly add git pre-commit hook to local dev machine and runs set of quality assertions:

- PHPLint
- PHPCS
- php-cs-fixer
- Unit tests
- PHPMD(disabled)

As additional feature it allow use php-cs-fixer with auto fix of commited files

## Install 

``
composer require --dev smart-gamma/php-composter-quality-tool
``

## Usage

1. Edit source files
2. git add files
3. git commit -m "my commit"

Will initiate assertions and if violations in code style will be found in the files applied in commit it will prompt to autofix these violations and rescan files.
Autofixed files will be automaticly added to the latest commit.