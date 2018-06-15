# PHP Composter: Smart Gamma Quality tool

> The interactive tool will allow project's team to keep common development code style standards based on pre-commit git hook   

The tool allows automaticly add git pre-commit hook to local dev machine and runs set of quality assertions:

- PHPLint
- PHPCS
- php-cs-fixer
- Unit tests (TODO)
- PHPMD

As additional feature it allows use php-cs-fixer with auto fix of commited files

## Pre-Configuration

if you want to modify default tool configuration, add follow line to composer.json "post-install-cmd" / "post-update-cmd" that will install default config.yml
 
``
"PHPComposter\\GammaQualityTool\\Installer::persistConfig"
``

## Install 

1. Edit your composer.json

1.1. In order to override default setting you can autoinstall tool config.yml for further modification.

Add to 

>Until follow [pull request](https://github.com/php-composter/php-composter/pull/13) will be merged, it will require following lines to composer.json:


    "repositories": [
        {
            "type" : "vcs",
            "url" : "git@github.com:Evgenas/php-composter.git"
        }
    ],

2. 
``
composer require --dev smart-gamma/php-composter-quality-tool
``

## Usage

1. Edit source files
2. git add files
3. git commit -m "my commit"

## PHPCS & php-cs-fixer

Will initiate assertions and if violations in code style will be found in the files applied in commit it will prompt to autofix these violations and rescan files.
Autofixed files will be automaticly added to the latest commit.

## PHPMD

The tool will scan PhpMD warning, but won't block the commit, but will output the list and prompt to rescan your commited file if you want to fix  via IDE some indicated warnings. 
