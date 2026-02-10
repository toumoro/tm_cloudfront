#!/usr/bin/env bash

#
# TYPO3 core test runner based on docker or podman
#

trap 'cleanUp;exit 2' SIGINT

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
        COUNT=0;
        while ! nc -z ${HOST} ${PORT}; do
            if [ \"\${COUNT}\" -gt 10 ]; then
              echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
              exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_ALPINE} ${CONTAINER_SHELL} -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$
    fi
}

cleanUp() {
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}')
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} kill ${ATTACHED_CONTAINER} >/dev/null
    done
    if [ ${CONTAINER_BIN} = "docker" ]; then
        ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null
    else
        ${CONTAINER_BIN} network rm -f ${NETWORK} >/dev/null
    fi
}

handleDbmsOptions() {
    # -a, -d, -i depend on each other. Validate input combinations and set defaults.
    case ${DBMS} in
        mariadb)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.4"
            if ! [[ ${DBMS_VERSION} =~ ^(10.4|10.5|10.6|10.7|10.8|10.9|10.10|10.11|11.0|11.1)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            ;;
        mysql)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.0"
            if ! [[ ${DBMS_VERSION} =~ ^(8.0|8.1|8.2|8.3)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            ;;
        postgres)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10"
            if ! [[ ${DBMS_VERSION} =~ ^(10|11|12|13|14|15|16)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            ;;
        sqlite)
            if [ -n "${DATABASE_DRIVER}" ]; then
                echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            if [ -n "${DBMS_VERSION}" ]; then
                echo "Invalid combination -d ${DBMS} -i ${DATABASE_DRIVER}" >&2
                echo >&2
                echo "${HELP_MESSAGE}" >&2
                exit 1
            fi
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            echo >&2
            echo "${HELP_MESSAGE}" >&2
            exit 1
            ;;
    esac
}

cleanFiles() {
  local files=($"@")

  # Ensure anything is handed here
  if [ ${#files[@]} -eq 0 ]; then
      return
  fi

  # @TODO: Really remove
  ls -la "${files[@]}"
}

cleanBuildFiles() {
    echo -n "Clean builds ... "
    RUNTESTS_CLEANUP=($RUNTESTS_CLEANUP_BUILD)
    cleanFiles "${RUNTESTS_CLEANUP[@]}"
    echo "done"
}

cleanCacheFiles() {
    echo -n "Clean caches ... "
    RUNTESTS_CLEANUP=($RUNTESTS_CLEANUP_CACHE)
    cleanFiles "${RUNTESTS_CLEANUP[@]}"
    echo "done"
}

cleanTestFiles() {
    # composer distribution test
    echo -n "Clean composer distribution test ... "
    RUNTESTS_CLEANUP=($RUNTESTS_CLEANUP_COMPOSER)
    cleanFiles "${RUNTESTS_CLEANUP[@]}"
    echo "done"

    # test related
    echo -n "Clean test related files ... "
    RUNTESTS_CLEANUP=($RUNTESTS_CLEANUP_TESTS)
    cleanFiles "${GARBAGE[@]}"
    echo "done"
}

cleanRenderedDocumentationFiles() {
    echo -n "Clean rendered documentation files ... "
    RUNTESTS_CLEANUP=($RUNTESTS_CLEANUP_DOCS)
    cleanFiles "${RUNTESTS_CLEANUP[@]}"
    echo "done"
}

getPhpImageVersion() {
    case ${1} in
        8.2)
            echo -n "1.12"
            ;;
        8.3)
            echo -n "1.13"
            ;;
        8.4)
            echo -n "latest" # Ou la version la plus récente disponible sur ghcr.io/typo3/core-testing-php84
            ;;
    esac
}

loadHelp() {
    # Load help text into $HELP
    read -r -d '' HELP <<EOF
TYPO3 core test runner. Execute acceptance, unit, functional and other test suites in
a container based test environment. Handles execution of single test files, sending
xdebug information to a local IDE and more.

Usage: $0 [options] [file]

Options:
    -s <...>
        Specifies the test suite to run
            - acceptance: main application acceptance tests [CORE-ONLY]
            - acceptanceComposer: main application acceptance tests [CORE-ONLY]
            - acceptanceInstall: installation acceptance tests, only with -d mariadb|postgres|sqlite [CORE-ONLY]
            - buildCss: execute scss to css builder
            - buildJavascript: execute typescript to javascript builder
            - cgl: test and fix all core php files
            - cglGit: test and fix latest committed patch for CGL compliance
            - cglHeader: test and fix file header for all core php files
            - cglHeaderGit: test and fix latest committed patch for CGL file header compliance
            - checkBom: check UTF-8 files do not contain BOM [CORE-ONLY]
            - checkComposer: check composer.json files for version integrity [CORE-ONLY]
            - checkExceptionCodes: test core for duplicate exception codes [CORE-ONLY]
            - checkExtensionScannerRst: test all .rst files referenced by extension scanner exist [CORE-ONLY]
            - checkFilePathLength: test core file paths do not exceed maximum length
            - checkGitSubmodule: test core git has no sub modules defined
            - checkGruntClean: Verify "grunt build" is clean. Warning: Executes git commands! Usually used in CI only.
            - checkIntegrityPhp: check php code for with registered integrity rules [CORE-ONLY]
            - checkIsoDatabase: Verify "updateIsoDatabase.php" does not change anything. [CORE-ONLY]
            - checkPermissions: test some core files for correct executable bits
            - checkRst: test .rst files for integrity [CORE-ONLY]
            - clean: clean up build, cache and testing related files and folders
            - cleanBuild: clean up build related files and folders
            - cleanCache: clean up cache related files and folders
            - cleanRenderedDocumentation: clean up rendered documentation files and folders (Documentation-GENERATED-temp)
            - cleanTests: clean up test related files and folders
            - composer: "composer" command dispatcher, to execute various composer commands
            - composerInstall: "composer install"
            - composerInstallMax: "composer update", with no platform.php config.
            - composerInstallMin: "composer update --prefer-lowest", with platform.php set to PHP version x.x.0.
            - composerTestDistribution: "composer update" in Build/composer to verify core dependencies
            - composerValidate: "composer validate"
            - custom: Dispatch and execute additional runTests.sh sub tasks, see examples.
            - functional: PHP functional tests
            - functionalDeprecated: deprecated PHP functional tests
            - listExceptionCodes: list core exception codes in JSON format
            - lintHtml: HTML linting
            - lintPhp: PHP linting
            - lintScss: SCSS linting
            - lintServicesYaml: YAML Linting Services.yaml files with enabled tags parsing.
            - lintTypescript: TS linting
            - lintYaml: YAML Linting (excluding Services.yaml)
            - npm: "npm" command dispatcher, to execute various npm commands directly
            - phpstan: phpstan tests
            - phpstanGenerateBaseline: regenerate phpstan baseline, handy after phpstan updates
            - unit (default): PHP unit tests
            - unitDeprecated: deprecated PHP unit tests
            - unitJavascript: JavaScript unit tests
            - unitRandom: PHP unit tests in random order, add -o <number> to use specific seed

    -b <docker|podman>
        Container environment:
            - podman (default)
            - docker

    -a <mysqli|pdo_mysql>
        Only with -s functional|functionalDeprecated
        Specifies to use another driver, following combinations are available:
            - mysql
                - mysqli (default)
                - pdo_mysql
            - mariadb
                - mysqli (default)
                - pdo_mysql

    -d <sqlite|mariadb|mysql|postgres>
        Only with -s functional|functionalDeprecated|acceptance|acceptanceComposer|acceptanceInstall
        Specifies on which DBMS tests are performed
            - sqlite: (default): use sqlite
            - mariadb: use mariadb
            - mysql: use MySQL
            - postgres: use postgres

    -i version
        Specify a specific database version
        With "-d mariadb":
            - 10.4   short-term, maintained until 2024-06-18 (default)
            - 10.5   short-term, maintained until 2025-06-24
            - 10.6   long-term, maintained until 2026-06
            - 10.7   short-term, no longer maintained
            - 10.8   short-term, maintained until 2023-05
            - 10.9   short-term, maintained until 2023-08
            - 10.10  short-term, maintained until 2023-11
            - 10.11  long-term, maintained until 2028-02
            - 11.0   development series
            - 11.1   short-term development series
        With "-d mysql":
            - 8.0   maintained until 2026-04 (default) LTS
            - 8.1   unmaintained since 2023-10
            - 8.2   unmaintained since 2024-01
            - 8.3   maintained until 2024-04
        With "-d postgres":
            - 10    unmaintained since 2022-11-10 (default)
            - 11    unmaintained since 2023-11-09
            - 12    maintained until 2024-11-14
            - 13    maintained until 2025-11-13
            - 14    maintained until 2026-11-12
            - 15    maintained until 2027-11-11
            - 16    maintained until 2028-11-09

    -c <chunk/numberOfChunks>
        Only with -s functional|acceptance
        Hack functional or acceptance tests into #numberOfChunks pieces and run tests of #chunk.
        Example -c 3/13

    -p <8.2|8.3>
        Specifies the PHP minor version to be used
            - 8.2 (default): use PHP 8.2
            - 8.3: use PHP 8.3

    -t sets|systemplate
        Only with -s acceptance|acceptanceComposer
        Specifies which frontend rendering mechanism should be used
            - sets: (default): use site sets
            - systemplate: use sys_template records

    -e "<phpunit options>" (DEPRECATED).
        Only with -s functional|functionalDeprecated|unit|unitDeprecated|unitRandom|acceptance
        Additional options to send to phpunit (unit & functional tests) or codeception (acceptance
        tests). For phpunit, options starting with "--" must be added after options starting with "-".
        Example -e "-d memory_limit=-1 --filter filterByValueRecursiveCorrectlyFiltersArray" to enable verbose output AND filter tests
        named "canRetrieveValueWithGP"

        DEPRECATED - pass arguments after the "--" separator directly. For example, instead of
            $THIS_SCRIPT_NAME -s unit -e "--filter filterByValueRecursiveCorrectlyFiltersArray"
        use
            $THIS_SCRIPT_NAME -s unit -- --filter filterByValueRecursiveCorrectlyFiltersArray

    -g
        Only with -s acceptance|acceptanceComposer|acceptanceInstall
        Activate selenium grid as local port to watch browser clicking around. Can be surfed using
        http://localhost:7900/. A browser tab is opened automatically if xdg-open is installed.

    -x
        Only with -s functional|functionalDeprecated|unit|unitDeprecated|unitRandom|acceptance|acceptanceComposer|acceptanceInstall
        Send information to host instance for test or system under test break points. This is especially
        useful if a local PhpStorm instance is listening on default xdebug port 9003. A different port
        can be selected with -y

    -y <port>
        Send xdebug information to a different port than default 9003 if an IDE like PhpStorm
        is not listening on default port.

    -o <number>
        Only with -s unitRandom
        Set specific random seed to replay a random run in this order again. The phpunit randomizer
        outputs the used seed at the end (in gitlab core testing logs, too). Use that number to
        replay the unit tests in that order.

    -n
        Only with -s cgl|cglGit|cglHeader|cglHeaderGit
        Activate dry-run in CGL check that does not actively change files and only prints broken ones.

    -u
        Update existing typo3/core-testing-* container images and remove obsolete dangling image versions.
        Use this if weird test errors occur.

    -h
        Show this help.

Examples:
    # Run all core unit tests using PHP 8.2
    $THIS_SCRIPT_NAME
    $THIS_SCRIPT_NAME -s unit

    # Run all core units tests and enable xdebug (have a PhpStorm listening on port 9003!)
    $THIS_SCRIPT_NAME -x

    # Run unit tests in phpunit with xdebug on PHP 8.3 and filter for test filterByValueRecursiveCorrectlyFiltersArray
    $THIS_SCRIPT_NAME -x -p 8.3 -- --filter filterByValueRecursiveCorrectlyFiltersArray

    # Run functional tests in phpunit with a filtered test method name in a specified file
    # example will currently execute two tests, both of which start with the search term
    $THIS_SCRIPT_NAME -s functional -- \
      --filter datetimeInstanceCanBePersistedToDatabaseIfTypeIsExplicitlySpecified \
      typo3/sysext/core/Tests/Functional/Database/ConnectionTest.php

    # Run functional tests on postgres with xdebug, php 8.3 and execute a restricted set of tests
    $THIS_SCRIPT_NAME -x -p 8.3 -s functional -d postgres typo3/sysext/core/Tests/Functional/Authentication

    # Run functional tests on postgres 11
    $THIS_SCRIPT_NAME -s functional -d postgres -i 11

    # Run restricted set of application acceptance tests
    $THIS_SCRIPT_NAME -s acceptance typo3/sysext/core/Tests/Acceptance/Application/Login/BackendLoginCest.php:loginButtonMouseOver

    # Run installer tests of a new instance on sqlite
    $THIS_SCRIPT_NAME -s acceptanceInstall -d sqlite

    # Run composer require to require a dependency
    $THIS_SCRIPT_NAME -s composer -- require --dev typo3/testing-framework:dev-main

    # Some composer command examples
    $THIS_SCRIPT_NAME -s composer -- dumpautoload
    $THIS_SCRIPT_NAME -s composer -- info | grep "symfony"

    # Some npm command examples
    $THIS_SCRIPT_NAME -s npm -- audit
    $THIS_SCRIPT_NAME -s npm -- ci
    $THIS_SCRIPT_NAME -s npm -- run build
    $THIS_SCRIPT_NAME -s npm -- run watch:build
    $THIS_SCRIPT_NAME -s npm -- install --save bootstrap@^5.3.2

    # Custom sub-tasks dispatcher (see custom-runTests.sh)
    # Executes "custom-runTests.verboseStan.sh" (created by you!) and passes
    # parameters ("-param1 -param2 --param3...") plus local variables along
    $THIS_SCRIPT_NAME -s custom -- verboseStan -param1 -param2 --param3

EOF
}

# Test if docker exists, else exit out with error
if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script relies on docker or podman. Please install" >&2
    exit 1
fi

# Go to the directory this script is located, so everything else is relative
# to this dir, no matter from where this script is called. Later, go to the
# project root.
THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "$THIS_SCRIPT_DIR" || exit 1

# Source "runTests.env" to load variables containing path definitions.
# - All variables matching RUNTESTS_DIR_ will ensure a trailing slash.
# - All variables defined use a "${RUNTESTS_DIR_:=...}" definition to allow
#   overriding these from the outside script call for example: (`RUNTESTS_DIR_VENDOR="Build/something/vendor" .Build/Scripts/runTests.sh`)
RUNTESTS_ENV="${RUNTESTS_ENV:=../../runTests.env}"

# Check for runTeste.env, use $RUNTEST_ENV if specified first. If empty, check for "../../runTests.env" by default to shorten the search.
if [ ! -f "${RUNTESTS_ENV}" ] ; then
  # Begin searching one directory upwards if there is a runTests.env
  runtest_check_dir=$(dirname "$THIS_SCRIPT_DIR")
  while [[ ! -f "$runtest_check_dir/runTests.env" && "$runtest_check_dir" != "/" && "$runtest_check_dir" != "" ]]; do
    runtest_check_dir=$(dirname "$runtest_check_dir")
  done
  RUNTESTS_ENV="${runtest_check_dir}/runTests.env"
fi

if [ -f "${RUNTESTS_ENV}" ] ; then
  echo "Using runTests config from: ${RUNTESTS_ENV}"

  source "${RUNTESTS_ENV}"

  # Ensure all "RUNTESTS_DIR_" variables end with trailing slash.
  while IFS='=' read -r name value ; do
    if [[ $name == RUNTESTS_DIR_* ]]; then
      if [[ $value != */ ]]; then
        export "$name=$value/"
      fi
    fi
  done < <(env)
else
  # Default files when runTests.env is missing, using TYPO3 core configuration:
  RUNTESTS_DIR_ROOT="${RUNTESTS_DIR_ROOT:=../../}"
  RUNTESTS_DIR_BUILDER="${RUNTESTS_DIR_BUILDER:=Build/Scripts/}"
  RUNTESTS_DIR_BUILD="${RUNTESTS_DIR_BUILD:=Build}"
  RUNTESTS_DIR_VENDOR="${RUNTESTS_DIR_VENDOR:=Build/composer/vendor/}"
  RUNTESTS_DIR_BIN="${RUNTESTS_DIR_BIN:=bin/}"
  RUNTESTS_DIR_TESTTEMP="${RUNTESTS_DIR_TESTTEMP:=typo3temp/var/tests/}"
  RUNTESTS_DIR_CACHE="${RUNTESTS_DIR_TESTTEMP:=.cache/}"
  RUNTESTS_DIR_CUSTOM="${RUNTESTS_DIR_CUSTOM:=Build/Scripts/}"

  RUNTESTS_DIRS_CACHE="$RUNTESTS_DIR_CACHE $RUNTESTS_DIR_TESTTEMP"
  RUNTESTS_DIRS_PROJECT="${RUNTESTS_DIRS_PROJECT:=typo3/}"
  RUNTESTS_DIRS_PROJECT_BUILD="${RUNTESTS_DIRS_PROJECT_BUILD:=typo3/sysext}"

  RUNTESTS_CLEANUP_BUILD="${RUNTESTS_CLEANUP_BUILD:=\
                                    Build/JavaScript
                                    Build/node_modules}"
  RUNTESTS_CLEANUP_CACHE="${RUNTESTS_CLEANUP_CACHE:=\
                                    .cache
                                    Build/.cache
                                    Build/composer/.cache/
                                    .php-cs-fixer.cache}"
  RUNTESTS_CLEANUP_COMPOSER="${RUNTESTS_CLEANUP_COMPOSER:=\
                                    Build/composer/composer.json \
                                    Build/composer/composer.lock \
                                    Build/composer/public/index.php \
                                    Build/composer/public/typo3 \
                                    Build/composer/public/typo3conf/ext \
                                    Build/composer/var/ \
                                    Build/composer/vendor/}"
  RUNTESTS_CLEANUP_TESTS="${RUNTESTS_CLEANUP_TESTS:=\
                                    Build/phpunit/FunctionalTests-Job-*.xml \
                                    typo3/sysext/core/Tests/AcceptanceTests-Job-* \
                                    typo3/sysext/core/Tests/Acceptance/Support/_generated \
                                    typo3temp/var/tests/}"
  RUNTESTS_CLEANUP_DOCS="${RUNTESTS_CLEANUP_DOCS:=typo3/sysext/*/Documentation-GENERATED-temp}"

  RUNTESTS_PHPSTAN_CONFIG_FILE_LOCAL="${RUNTESTS_PHPSTAN_CONFIG_FILE_LOCAL:=Build/phpstan/phpstan.local.neon}"
  RUNTESTS_PHPSTAN_CONFIG_FILE_CI="${RUNTESTS_PHPSTAN_CONFIG_FILE_CI:=Build/phpstan/phpstan.ci.neon}"
  RUNTESTS_PHPSTAN_BASELINE_FILE="${RUNTESTS_PHPSTAN_BASELINE_FILE:=Build/phpstan/phpstan-baseline.neon}"
  RUNTESTS_PHPUNIT_FILE_UNITTEST="${RUNTESTS_PHPUNIT_FILE_UNITTEST:=Build/phpunit/UnitTests.xml}"
  RUNTESTS_PHPUNIT_FILE_UNITTEST_DEPRECATED="${RUNTESTS_PHPUNIT_FILE_UNITTEST_DEPRECATED:=Build/phpunit/UnitTestsDeprecated.xml}"
  RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST="${RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST:=Build/phpunit/FunctionalTests.xml}"
  RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST_DEPRECATED="${RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST_DEPRECATED:=Build/phpunit/FunctionalTestsDeprecated.xml}"
  RUNTESTS_DIR_PHPUNIT_FUNCTIONAL="${RUNTESTS_DIR_PHPUNIT_FUNCTIONAL:=Build/phpunit/}"
  RUNTESTS_CODECEPTION_CONFIG_FILE="${RUNTESTS_CODECEPTION_CONFIG_FILE:=typo3/sysext/core/Tests/codeception.yml}"
  RUNTESTS_PHPCSFIXER_CONFIG_FILE="${RUNTESTS_PHPCSFIXER_CONFIG_FILE:=Build/php-cs-fixer/config.php}"
  RUNTESTS_PHPCSFIXER_HEADER_CONFIG_FILE="${RUNTESTS_PHPCSFIXER_HEADER_CONFIG_FILE:=Build/php-cs-fixer/header-comment.php}"
  RUNTESTS_INTEGRITYCHECKER_CONFIG_FILE="${RUNTESTS_INTEGRITYCHECKER_CONFIG_FILE:=Build/Scripts/phpIntegrityChecker.php}"

  RUNTESTS_COMMAND_BUILD_CSS="cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt css"
  RUNTESTS_COMMAND_BUILD_JS="cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt scripts"
  RUNTESTS_COMMAND_BUILD_CLEAN="find '${RUNTESTS_DIRS_PROJECT_BUILD}' -name '*.js' -not -path '*/Fixtures/*' -exec rm '{}' + && cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt build; cd ..; git add *; git status; git status | grep -q \"nothing to commit, working tree clean\""
  RUNTESTS_COMMAND_BUILD_LINT="cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt exec:lintspaces"
  RUNTESTS_COMMAND_BUILD_LINT_STYLE="cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt stylelint"
  RUNTESTS_COMMAND_BUILD_LINT_TS="cd Build; npm ci || exit 1; node_modules/grunt/bin/grunt eslint"
  RUNTESTS_COMMAND_UNIT_JS="cd Build; npm ci || exit 1; CHROME_SANDBOX=false BROWSERS=chrome npm run test"
fi

# Now go into the actual "base" directory.
cd "$RUNTESTS_DIR_ROOT" || exit 1
CORE_ROOT="${PWD}"

# Default variables
TEST_SUITE="unit"
DBMS="sqlite"
DBMS_VERSION=""
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
ACCEPTANCE_HEADLESS=1
ACCEPTANCE_TOPIC="sets"
EXTRA_TEST_OPTIONS=""
PHPUNIT_RANDOM=""
CGLCHECK_DRY_RUN=""
DATABASE_DRIVER=""
CHUNKS=0
THISCHUNK=0
CONTAINER_BIN=""
COMPOSER_ROOT_VERSION="13.2.x-dev"
CONTAINER_SHELL="/bin/sh"
PHPSTAN_CONFIG_FILE="$RUNTESTS_PHPSTAN_CONFIG_FILE_LOCAL"
CONTAINER_INTERACTIVE="-it --init"
HOST_UID=$(id -u)
HOST_PID=$(id -g)
USERSET=""
SUFFIX=$(echo $RANDOM)
NETWORK="typo3-core-${SUFFIX}"
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_HOST="host.docker.internal"
THIS_SCRIPT_NAME="${RUNTESTS_DIR_BUILDER}runTests.sh"
HELP_MESSAGE="Use \"${THIS_SCRIPT_NAME} -h\" to display help and valid options"

# Option parsing updates above default vars
# Reset in case getopts has been used previously in the shell
OPTIND=1
# Array for invalid options
INVALID_OPTIONS=()
# Simple option parsing based on getopts (! not getopt)
while getopts ":a:b:s:c:d:i:t:p:e:xy:o:nhug" OPT; do
    case ${OPT} in
        s)
            TEST_SUITE=${OPTARG}
            ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        a)
            DATABASE_DRIVER=${OPTARG}
            ;;
        c)
            if ! [[ ${OPTARG} =~ ^([0-9]+\/[0-9]+)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            else
                # Split "2/13" - run chunk 2 of 13 chunks
                THISCHUNK=$(echo "${OPTARG}" | cut -d '/' -f1)
                CHUNKS=$(echo "${OPTARG}" | cut -d '/' -f2)
            fi
            ;;
        d)
            DBMS=${OPTARG}
            ;;
        i)
            DBMS_VERSION=${OPTARG}
            ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.2|8.3|8.4)$ ]]; then
                INVALID_OPTIONS+=("${OPTARG}")
            fi
            ;;
        e)
            EXTRA_TEST_OPTIONS=${OPTARG}
            ;;
        g)
            ACCEPTANCE_HEADLESS=0
            ;;
        t)
            ACCEPTANCE_TOPIC=${OPTARG}
            ;;
        x)
            PHP_XDEBUG_ON=1
            ;;
        y)
            PHP_XDEBUG_PORT=${OPTARG}
            ;;
        o)
            PHPUNIT_RANDOM="--random-order-seed=${OPTARG}"
            ;;
        n)
            CGLCHECK_DRY_RUN="-n"
            ;;
        h)
            loadHelp
            echo "${HELP}"
            exit 0
            ;;
        u)
            TEST_SUITE=update
            ;;
        \?)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
        :)
            INVALID_OPTIONS+=("${OPTARG}")
            ;;
    esac
done

# Exit on invalid options
if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s):" >&2
    for I in "${INVALID_OPTIONS[@]}"; do
        echo "-"${I} >&2
    done
    echo >&2
    echo "$HELP_MESSAGE" >&2
    exit 1
fi

handleDbmsOptions

# ENV var "CI" is set by gitlab-ci. Use it to force some CI details.
if [ "${CI}" == "true" ]; then
    PHPSTAN_CONFIG_FILE="$RUNTESTS_PHPSTAN_CONFIG_FILE_CI"
    CONTAINER_INTERACTIVE=""
fi

# determine default container binary to use: 1. podman 2. docker
if [[ -z "${CONTAINER_BIN}" ]]; then
    if type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

if [ $(uname) != "Darwin" ] && [ ${CONTAINER_BIN} = "docker" ]; then
    # Run docker jobs as current user to prevent permission issues. Not needed with podman.
    USERSET="--user $HOST_UID"
fi

if ! type ${CONTAINER_BIN} >/dev/null 2>&1; then
    echo "Selected container environment \"${CONTAINER_BIN}\" not found. Please install or use -b option to select one." >&2
    exit 1
fi

IMAGE_APACHE="ghcr.io/typo3/core-testing-apache24:1.5"
IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):$(getPhpImageVersion $PHP_VERSION)"

IMAGE_NODEJS="ghcr.io/typo3/core-testing-nodejs22:1.1"
IMAGE_NODEJS_CHROME="ghcr.io/typo3/core-testing-nodejs22-chrome:1.1"
IMAGE_ALPINE="docker.io/alpine:3.8"
IMAGE_SELENIUM="docker.io/selenium/standalone-chrome:4.11.0-20230801"
IMAGE_REDIS="docker.io/redis:4-alpine"
IMAGE_MEMCACHED="docker.io/memcached:1.5-alpine"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"

# Detect arm64 to use seleniarm image.
ARCH=$(uname -m)
if [ ${ARCH} = "arm64" ]; then
    IMAGE_SELENIUM="docker.io/seleniarm/standalone-chromium:4.10.0-20230615"
fi

# Remove handled options and leaving the rest in the line, so it can be passed raw to commands
shift $((OPTIND - 1))

# Create .cache dir: composer and various npm jobs need this.
mkdir -p $RUNTESTS_DIRS_CACHE

${CONTAINER_BIN} network create ${NETWORK} >/dev/null

if [ ${CONTAINER_BIN} = "docker" ]; then
    # docker needs the add-host for xdebug remote debugging. podman has host.container.internal built in
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -v ${CORE_ROOT}:${CORE_ROOT} -w ${CORE_ROOT}"
else
    # podman
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${CORE_ROOT}:${CORE_ROOT} -w ${CORE_ROOT}"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
    PHP_FPM_OPTIONS="-d xdebug.mode=off"
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
    PHP_FPM_OPTIONS="-d xdebug.mode=debug -d xdebug.start_with_request=yes -d xdebug.client_host=${CONTAINER_HOST} -d xdebug.client_port=${PHP_XDEBUG_PORT} -d memory_limit=256M"
fi

# Suite execution
case ${TEST_SUITE} in
    acceptance)
        CODECEPION_ENV="--env ci,classic,${ACCEPTANCE_TOPIC}"
        if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
            CODECEPION_ENV="--env ci,classic,headless,${ACCEPTANCE_TOPIC}"
        fi
        if [ "${CHUNKS}" -gt 0 ]; then
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-splitter-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}splitAcceptanceTests.php -v ${CHUNKS}
            COMMAND=(${RUNTESTS_DIR_BIN}codecept run Application -d -g AcceptanceTests-Job-${THISCHUNK} -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} "$@" --html reports.html)
        else
            COMMAND=(${RUNTESTS_DIR_BIN}codecept run Application -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} "$@" --html reports.html)
        fi
        SELENIUM_GRID=""
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
            SELENIUM_GRID="-p 7900:7900 -e SE_VNC_NO_PASSWORD=1 -e VNC_NO_PASSWORD=1"
        fi
        rm -rf "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance" "${CORE_ROOT}/AcceptanceReports"
        mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance"
        APACHE_OPTIONS="-e APACHE_RUN_USER=#${HOST_UID} -e APACHE_RUN_SERVERNAME=web -e APACHE_RUN_GROUP=#${HOST_PID} -e APACHE_RUN_DOCROOT=${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000"
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d ${SELENIUM_GRID} --name ac-chrome-${SUFFIX} --network ${NETWORK} --network-alias chrome --tmpfs /dev/shm:rw,nosuid,nodev,noexec ${IMAGE_SELENIUM} >/dev/null
        if [ ${CONTAINER_BIN} = "docker" ]; then
            ${CONTAINER_BIN} run --rm -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -e PHPFPM_USER=${HOST_UID} -e PHPFPM_GROUP=${HOST_PID} -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm ${PHP_FPM_OPTIONS} >/dev/null
            ${CONTAINER_BIN} run --rm -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web --add-host "${CONTAINER_HOST}:host-gateway" -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
        else
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm ${USERSET} -e PHPFPM_USER=0 -e PHPFPM_GROUP=0 -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm -R ${PHP_FPM_OPTIONS} >/dev/null
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
        fi
        waitFor chrome 4444
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
            waitFor chrome 7900
        fi
        waitFor web 80
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "xdg-open" >/dev/null; then
            xdg-open http://localhost:7900/?autoconnect=1 >/dev/null
        elif [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "open" >/dev/null; then
            open http://localhost:7900/?autoconnect=1 >/dev/null
        fi
        case ${DBMS} in
            mariadb)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-ac-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-ac-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mariadb-ac-${SUFFIX}"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-mariadb ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-ac-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-ac-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mysql-ac-${SUFFIX}"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-mysql ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-ac-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-ac-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=func_test -e typo3DatabaseUsername=funcu -e typo3DatabasePassword=funcp -e typo3DatabaseHost=postgres-ac-${SUFFIX}"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-postgres ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                rm -rf "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-sqlite-dbs/"
                mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-sqlite ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    acceptanceComposer)
        rm -rf "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-composer" "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}AcceptanceReports"

        PREPAREPARAMS=""
        TESTPARAMS=""
        case ${DBMS} in
            mariadb)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-ac-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=acp -e MYSQL_DATABASE=ac_test --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-ac-${SUFFIX} 3306
                PREPAREPARAMS="-e TYPO3_DB_DRIVER=mysqli -e TYPO3_DB_DBNAME=ac_test -e TYPO3_DB_USERNAME=root -e TYPO3_DB_PASSWORD=acp -e TYPO3_DB_HOST=mariadb-ac-${SUFFIX} -e TYPO3_DB_PORT=3306"
                TESTPARAMS="-e typo3DatabaseName=ac_test -e typo3DatabaseUsername=root -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mariadb-ac-${SUFFIX}"
                ;;
            mysql)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-ac-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=acp -e MYSQL_DATABASE=ac_test --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-ac-${SUFFIX} 3306
                PREPAREPARAMS="-e TYPO3_DB_DRIVER=mysqli -e TYPO3_DB_DBNAME=ac_test -e TYPO3_DB_USERNAME=root -e TYPO3_DB_PASSWORD=acp -e TYPO3_DB_HOST=mysql-ac-${SUFFIX} -e TYPO3_DB_PORT=3306"
                TESTPARAMS="-e typo3DatabaseName=ac_test -e typo3DatabaseUsername=root -e typo3DatabasePassword=funcp -e typo3DatabaseHost=mysql-ac-${SUFFIX}"
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-ac-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_DB=ac_test -e POSTGRES_PASSWORD=acp -e POSTGRES_USER=ac_test --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-ac-${SUFFIX} 5432
                PREPAREPARAMS="-e TYPO3_DB_DRIVER=postgres -e TYPO3_DB_DBNAME=ac_test -e TYPO3_DB_USERNAME=ac_test -e TYPO3_DB_PASSWORD=acp -e TYPO3_DB_HOST=postgres-ac-${SUFFIX} -e TYPO3_DB_PORT=5432"
                TESTPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=ac_test -e typo3DatabaseUsername=ac_test -e typo3DatabasePassword=acp -e typo3DatabaseHost=postgres-ac-${SUFFIX}"
                ;;
            sqlite)
                PREPAREPARAMS="-e TYPO3_DB_DRIVER=sqlite"
                TESTPARAMS="-e typo3DatabaseDriver=pdo_sqlite"
                ;;
        esac

        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name acceptance-prepare ${XDEBUG_MODE} -e COMPOSER_CACHE_DIR=${CORE_ROOT}/${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${PREPAREPARAMS} ${IMAGE_PHP} "${CORE_ROOT}/${RUNTESTS_DIR_BUILDER}setupAcceptanceComposer.sh" "${RUNTESTS_DIR_TESTTEMP}acceptance-composer" sqlite "" "${ACCEPTANCE_TOPIC}"
        SUITE_EXIT_CODE=$?
        if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
            CODECEPION_ENV="--env ci,composer,${ACCEPTANCE_TOPIC}"
            if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
                CODECEPION_ENV="--env ci,composer,headless,${ACCEPTANCE_TOPIC}"
            fi
            if [ "${CHUNKS}" -gt 0 ]; then
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-splitter-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}splitAcceptanceTests.php -v ${CHUNKS}
                COMMAND=(${RUNTESTS_DIR_BIN}codecept run Application -d -g AcceptanceTests-Job-${THISCHUNK} -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} "$@" --html reports.html)
            else
                COMMAND=(${RUNTESTS_DIR_BIN}codecept run Application -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} "$@" --html reports.html)
            fi
            SELENIUM_GRID=""
            if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
                SELENIUM_GRID="-p 7900:7900 -e SE_VNC_NO_PASSWORD=1 -e VNC_NO_PASSWORD=1"
            fi
            APACHE_OPTIONS="-e APACHE_RUN_USER=#${HOST_UID} -e APACHE_RUN_SERVERNAME=web -e APACHE_RUN_GROUP=#${HOST_PID} -e APACHE_RUN_DOCROOT=${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-composer/public -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000"
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d ${SELENIUM_GRID} --name ac-chrome-${SUFFIX} --network ${NETWORK} --network-alias chrome --tmpfs /dev/shm:rw,nosuid,nodev,noexec ${IMAGE_SELENIUM} >/dev/null
            if [ ${CONTAINER_BIN} = "docker" ]; then
                ${CONTAINER_BIN} run --rm -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -e PHPFPM_USER=${HOST_UID} -e PHPFPM_GROUP=${HOST_PID} -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm ${PHP_FPM_OPTIONS} >/dev/null
                ${CONTAINER_BIN} run --rm -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web --add-host "${CONTAINER_HOST}:host-gateway" -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
            else
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm ${USERSET} -e PHPFPM_USER=0 -e PHPFPM_GROUP=0 -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm -R ${PHP_FPM_OPTIONS} >/dev/null
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
            fi
            waitFor chrome 4444
            if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
                waitFor chrome 7900
            fi
            waitFor web 80
            if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "xdg-open" >/dev/null; then
                xdg-open http://localhost:7900/?autoconnect=1 >/dev/null
            elif [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "open" >/dev/null; then
                open http://localhost:7900/?autoconnect=1 >/dev/null
            fi

            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-${DBMS}-composer ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${TESTPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
            SUITE_EXIT_CODE=$?
        fi
        ;;
    acceptanceInstall)
        SELENIUM_GRID=""
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
            SELENIUM_GRID="-p 7900:7900 -e SE_VNC_NO_PASSWORD=1 -e VNC_NO_PASSWORD=1"
        fi
        rm -rf "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance" "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}AcceptanceReports"
        mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance"
        APACHE_OPTIONS="-e APACHE_RUN_USER=#${HOST_UID} -e APACHE_RUN_SERVERNAME=web -e APACHE_RUN_GROUP=#${HOST_PID} -e APACHE_RUN_DOCROOT=${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000"
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d ${SELENIUM_GRID} --name ac-install-chrome-${SUFFIX} --network ${NETWORK} --network-alias chrome --tmpfs /dev/shm:rw,nosuid,nodev,noexec ${IMAGE_SELENIUM} >/dev/null
        if [ ${CONTAINER_BIN} = "docker" ]; then
            ${CONTAINER_BIN} run --rm -d --name ac-install-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} -e PHPFPM_USER=${HOST_UID} -e PHPFPM_GROUP=${HOST_PID} -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm ${PHP_FPM_OPTIONS} >/dev/null
            ${CONTAINER_BIN} run --rm -d --name ac-install-web-${SUFFIX} --network ${NETWORK} --network-alias web --add-host "${CONTAINER_HOST}:host-gateway" -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
        else
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-install-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm ${USERSET} -e PHPFPM_USER=0 -e PHPFPM_GROUP=0 -v ${CORE_ROOT}:${CORE_ROOT} ${IMAGE_PHP} php-fpm -R ${PHP_FPM_OPTIONS} >/dev/null
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} -d --name ac-install-web-${SUFFIX} --network ${NETWORK} --network-alias web -v ${CORE_ROOT}:${CORE_ROOT} ${APACHE_OPTIONS} ${IMAGE_APACHE} >/dev/null
        fi
        waitFor chrome 4444
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ]; then
            waitFor chrome 7900
        fi
        waitFor web 80
        if [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "xdg-open" >/dev/null; then
            xdg-open http://localhost:7900/?autoconnect=1 >/dev/null
        elif [ "${ACCEPTANCE_HEADLESS}" -eq 0 ] && type "open" >/dev/null; then
            open http://localhost:7900/?autoconnect=1 >/dev/null
        fi
        case ${DBMS} in
            mariadb)
                CODECEPION_ENV="--env ci,mysql"
                if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
                    CODECEPION_ENV="--env ci,mysql,headless"
                fi
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-ac-install-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-ac-install-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3InstallMysqlDatabaseName=func_test -e typo3InstallMysqlDatabaseUsername=root -e typo3InstallMysqlDatabasePassword=funcp -e typo3InstallMysqlDatabaseHost=mariadb-ac-install-${SUFFIX}"
                COMMAND="${RUNTESTS_DIR_BIN}codecept run Install -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} --html reports.html"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-install-mariadb ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} ${COMMAND}
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                CODECEPION_ENV="--env ci,mysql"
                if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
                    CODECEPION_ENV="--env ci,mysql,headless"
                fi
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-ac-install-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-ac-install-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3InstallMysqlDatabaseName=func_test -e typo3InstallMysqlDatabaseUsername=root -e typo3InstallMysqlDatabasePassword=funcp -e typo3InstallMysqlDatabaseHost=mysql-ac-install-${SUFFIX}"
                COMMAND="${RUNTESTS_DIR_BIN}codecept run Install -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} --html reports.html"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-install-mysql ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} ${COMMAND}
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                CODECEPION_ENV="--env ci,postgresql"
                if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
                    CODECEPION_ENV="--env ci,postgresql,headless"
                fi
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-ac-install-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-ac-install-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3InstallPostgresqlDatabasePort=5432 -e typo3InstallPostgresqlDatabaseName=${USER} -e typo3InstallPostgresqlDatabaseHost=postgres-ac-install-${SUFFIX} -e typo3InstallPostgresqlDatabaseUsername=funcu -e typo3InstallPostgresqlDatabasePassword=funcp"
                COMMAND="${RUNTESTS_DIR_BIN}codecept run Install -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} --html reports.html"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-install-postgres ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} ${COMMAND}
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                rm -rf "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-sqlite-dbs/"
                mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}acceptance-sqlite-dbs/"
                CODECEPION_ENV="--env ci,sqlite"
                if [ "${ACCEPTANCE_HEADLESS}" -eq 1 ]; then
                    CODECEPION_ENV="--env ci,sqlite,headless"
                fi
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite"
                COMMAND="${RUNTESTS_DIR_BIN}codecept run Install -d -c ${RUNTESTS_CODECEPTION_CONFIG_FILE} ${EXTRA_TEST_OPTIONS} ${CODECEPION_ENV} --html reports.html"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name ac-install-sqlite ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} ${COMMAND}
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    buildCss)
        COMMAND="${RUNTESTS_COMMAND_BUILD_CSS}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name build-css-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    buildJavascript)
        COMMAND="${RUNTESTS_COMMAND_BUILD_JS}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name build-js-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        # Active dry-run for cgl needs not "-n" but specific options
        if [ -n "${CGLCHECK_DRY_RUN}" ]; then
            CGLCHECK_DRY_RUN="--dry-run --diff"
        fi
        COMMAND="php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}php-cs-fixer fix -v ${CGLCHECK_DRY_RUN} --path-mode intersection --config=${RUNTESTS_PHPCSFIXER_CONFIG_FILE} ${RUNTESTS_DIRS_PROJECT}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} ${COMMAND}
        SUITE_EXIT_CODE=$?
        ;;
    cglGit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-git-${SUFFIX} -e RUNTESTS_PHPCSFIXER_CONFIG_FILE="${RUNTESTS_PHPCSFIXER_CONFIG_FILE}" ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}cglFixMyCommit.sh ${CGLCHECK_DRY_RUN}
        SUITE_EXIT_CODE=$?
        ;;
    cglHeader)
        # Active dry-run for cgl needs not "-n" but specific options
        if [ -n "${CGLCHECK_DRY_RUN}" ]; then
            CGLCHECK_DRY_RUN="--dry-run --diff"
        fi
        COMMAND="php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}php-cs-fixer fix -v ${CGLCHECK_DRY_RUN} --path-mode intersection --config=${RUNTESTS_PHPCSFIXER_HEADER_CONFIG_FILE} ${RUNTESTS_DIRS_PROJECT}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-header-${SUFFIX} ${IMAGE_PHP} ${COMMAND}
        SUITE_EXIT_CODE=$?
        ;;
    cglHeaderGit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-header-git-${SUFFIX} -e RUNTESTS_PHPCSFIXER_HEADER_CONFIG_FILE="${RUNTESTS_PHPCSFIXER_HEADER_CONFIG_FILE}" ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}cglFixMyCommitFileHeader.sh ${CGLCHECK_DRY_RUN}
        SUITE_EXIT_CODE=$?
        ;;
    checkIntegrityPhp)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-annotations-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} php ${RUNTESTS_INTEGRITYCHECKER_CONFIG_FILE} -p ${PHP_VERSION}
        SUITE_EXIT_CODE=$?
        ;;
    checkBom)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-utf8bom-${SUFFIX} ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}checkUtf8Bom.sh
        SUITE_EXIT_CODE=$?
        ;;
    checkComposer)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-composer-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}checkIntegrityComposer.php
        SUITE_EXIT_CODE=$?
        ;;
    checkExceptionCodes)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-exception-codes-${SUFFIX} ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}duplicateExceptionCodeCheck.sh
        SUITE_EXIT_CODE=$?
        ;;
    checkExtensionScannerRst)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-extensionscanner-rst-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}extensionScannerRstFileReferences.php
        SUITE_EXIT_CODE=$?
        ;;
    checkFilePathLength)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-file-path-length-${SUFFIX} -e RUNTESTS_DIRS_PROJECT="${RUNTESTS_DIRS_PROJECT}" ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}maxFilePathLength.sh
        SUITE_EXIT_CODE=$?
        ;;
    checkGitSubmodule)
        COMMAND="if [ \$(git submodule status 2>&1 | wc -l) -ne 0 ]; then echo \"Found a submodule definition in repository\"; exit 1; fi"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-git-submodule-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkGruntClean)
        COMMAND="${RUNTESTS_COMMAND_BUILD_CLEAN}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-grunt-clean-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkIsoDatabase)
        COMMAND="git checkout -- composer.json; git checkout -- composer.lock; php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}updateIsoDatabase.php; git add *; git status; git status | grep -q \"nothing to commit, working tree clean\""
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-iso-database-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    checkPermissions)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-permissions-${SUFFIX} -e RUNTESTS_DIRS_PROJECT="${RUNTESTS_DIRS_PROJECT}" ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}checkFilePermissions.sh
        SUITE_EXIT_CODE=$?
        ;;
    checkRst)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name check-rst-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}validateRstFiles.php
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        cleanBuildFiles
        cleanCacheFiles
        cleanRenderedDocumentationFiles
        cleanTestFiles
        ;;
    cleanBuild)
        cleanBuildFiles
        ;;
    cleanCache)
        cleanCacheFiles
        ;;
    cleanRenderedDocumentation)
        cleanRenderedDocumentationFiles
        ;;
    cleanTests)
        cleanTestFiles
        ;;
    composer)
        COMMAND=(composer "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-${SUFFIX} -e COMPOSER_CACHE_DIR=${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    composerInstall)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-${SUFFIX} -e COMPOSER_CACHE_DIR=${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} composer install --no-progress --no-interaction
        SUITE_EXIT_CODE=$?
        ;;
    composerInstallMax)
        COMMAND="composer config --unset platform.php; composer update --no-progress --no-interaction; composer dumpautoload"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-max-${SUFFIX} -e COMPOSER_CACHE_DIR=${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    composerInstallMin)
        COMMAND="composer config platform.php ${PHP_VERSION}.0; composer update --prefer-lowest --no-progress --no-interaction; composer dumpautoload"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-min-${SUFFIX} -e COMPOSER_CACHE_DIR=${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    composerTestDistribution)
        COMMAND="cd ${RUNTESTS_DIR_BUILD}/composer; rm -rf composer.json composer.lock public/index.php public/typo3 public/typo3conf/ext var/ vendor/; cp composer.dist.json composer.json; composer update --no-progress --no-interaction"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-test-distribution-${SUFFIX} -e COMPOSER_CACHE_DIR=${CORE_ROOT}/${RUNTESTS_DIR_CACHE}composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    composerValidate)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-validate-${SUFFIX} ${IMAGE_PHP} composer validate
        SUITE_EXIT_CODE=$?
        ;;
    custom)
        COMMAND="$@"
        if [[ -z "$COMMAND" ]]; then
          echo "No custom command specified. Example usage:"
          echo "> $THIS_SCRIPT_NAME -s custom -- verboseStan -param1 -param2 --param3"
          exit 1
        else
          source "${RUNTESTS_DIR_BUILDER}custom-runTests.sh"
          SUITE_EXIT_CODE=$?
        fi
        ;;
    functional)
        if [ "${CHUNKS}" -gt 0 ]; then
            ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name func-splitter-${SUFFIX} -e CORE_ROOT="${CORE_ROOT}" -e RUNTESTS_DIRS_RPOJECT="${RUNTESTS_DIRS_RPOJECT}" ${IMAGE_PHP} php -dxdebug.mode=off ${RUNTESTS_DIR_BUILDER}splitFunctionalTests.php -v ${CHUNKS}
            COMMAND=(${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_DIR_PHPUNIT_FUNCTIONAL}FunctionalTests-Job-${THISCHUNK}.xml --exclude-group not-${DBMS} ${EXTRA_TEST_OPTIONS} "$@")
        else
            COMMAND=(${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST} --exclude-group not-${DBMS} ${EXTRA_TEST_OPTIONS} "$@")
        fi
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name redis-func-${SUFFIX} --network ${NETWORK} -d ${IMAGE_REDIS} >/dev/null
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name memcached-func-${SUFFIX} --network ${NETWORK} -d ${IMAGE_MEMCACHED} >/dev/null
        waitFor redis-func-${SUFFIX} 6379
        waitFor memcached-func-${SUFFIX} 11211
        CONTAINER_COMMON_PARAMS="${CONTAINER_COMMON_PARAMS} -e typo3TestingRedisHost=redis-func-${SUFFIX} -e typo3TestingMemcachedHost=memcached-func-${SUFFIX}"
        case ${DBMS} in
            mariadb)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-func-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-func-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                # create sqlite tmpfs mount (temp)functional-sqlite-dbs/ to avoid permission issues
                mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}functional-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}functional-sqlite-dbs/:rw,noexec,nosuid"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    functionalDeprecated)
        COMMAND=(${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_PHPUNIT_FILE_FUNCTIONALTEST_DEPRECATED} --exclude-group not-${DBMS} ${EXTRA_TEST_OPTIONS} "$@")
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name redis-func-dep-${SUFFIX} --network ${NETWORK} -d ${IMAGE_REDIS} >/dev/null
        ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name memcached-func-dep-${SUFFIX} --network ${NETWORK} -d ${IMAGE_MEMCACHED} >/dev/null
        waitFor redis-func-dep-${SUFFIX} 6379
        waitFor memcached-func-dep-${SUFFIX} 11211
        CONTAINER_COMMON_PARAMS="${CONTAINER_COMMON_PARAMS} -e typo3TestingRedisHost=redis-func-dep-${SUFFIX} -e typo3TestingMemcachedHost=memcached-func-dep-${SUFFIX}"
        case ${DBMS} in
            mariadb)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-dep-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
                waitFor mariadb-func-dep-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-dep-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-deprecated-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            mysql)
                echo "Using driver: ${DATABASE_DRIVER}"
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-dep-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
                waitFor mysql-func-dep-${SUFFIX} 3306
                CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-dep-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-deprecated-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            postgres)
                ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-dep-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=funcu --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
                waitFor postgres-func-dep-${SUFFIX} 5432
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=funcu -e typo3DatabaseHost=postgres-func-dep-${SUFFIX} -e typo3DatabasePassword=funcp"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-deprecated-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
            sqlite)
                # create sqlite tmpfs mount (temp)functional-sqlite-dbs/ to avoid permission issues
                mkdir -p "${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}functional-sqlite-dbs/"
                CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${CORE_ROOT}/${RUNTESTS_DIR_TESTTEMP}functional-sqlite-dbs/:rw,noexec,nosuid"
                ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-deprecated-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
                SUITE_EXIT_CODE=$?
                ;;
        esac
        ;;
    listExceptionCodes)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name list-exception-codes-${SUFFIX} ${IMAGE_PHP} ${RUNTESTS_DIR_BUILDER}duplicateExceptionCodeCheck.sh -p
        SUITE_EXIT_CODE=$?
        ;;
    lintHtml)
        COMMAND="${RUNTESTS_COMMAND_BUILD_LINT}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-html-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintPhp)
        COMMAND="php -v | grep '^PHP'; find ${RUNTESTS_DIRS_PROJECT} -name \\*.php -print0 | xargs -0 -n1 -P"'$(nproc 2>/dev/null || echo 4)'" php -dxdebug.mode=off -l >/dev/null"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintScss)
        COMMAND="${RUNTESTS_COMMAND_BUILD_LINT_STYLE}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-css-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintServicesYaml)
        COMMAND="php -v | grep '^PHP'; find ${RUNTESTS_DIRS_PROJECT} -name 'Services.yaml' | xargs -r php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}yaml-lint --parse-tags"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintTypescript)
        COMMAND="${RUNTESTS_COMMAND_BUILD_LINT_TS}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-typescript-${SUFFIX} -e HOME=${CORE_ROOT}/${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    lintYaml)
        EXCLUDE_INVALID_FIXTURE_YAML_FILES="--exclude typo3/sysext/form/Tests/Unit/Mvc/Configuration/Fixtures/Invalid.yaml"
        COMMAND="php -v | grep '^PHP'; find ${RUNTESTS_DIRS_PROJECT} \\( -name '*.yaml' -o -name '*.yml' \\) ! -name 'Services.yaml' | xargs -r php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}yaml-lint --no-parse-tags ${EXCLUDE_INVALID_FIXTURE_YAML_FILES}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    npm)
        COMMAND=(npm "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} -w ${CORE_ROOT}/${RUNTESTS_DIR_BUILD} -e HOME=${CORE_ROOT}${RUNTESTS_DIR_CACHE} --name npm-${SUFFIX} ${IMAGE_NODEJS} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        COMMAND=(php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-progress --no-interaction --memory-limit 4G "$@")
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} ${IMAGE_PHP} "${COMMAND[@]}"
        SUITE_EXIT_CODE=$?
        ;;
    phpstanGenerateBaseline)
        COMMAND="php -dxdebug.mode=off ${RUNTESTS_DIR_BIN}phpstan analyse -c ${PHPSTAN_CONFIG_FILE} --no-progress --no-interaction --memory-limit 4G --generate-baseline=${RUNTESTS_PHPSTAN_BASELINE_FILE}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-baseline-${SUFFIX} ${IMAGE_PHP} ${CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} ${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_PHPUNIT_FILE_UNITTEST} ${EXTRA_TEST_OPTIONS} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    unitDeprecated)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-deprecated-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} ${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_PHPUNIT_FILE_UNITTEST_DEPRECATED} ${EXTRA_TEST_OPTIONS} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    unitJavascript)
        COMMAND="${RUNTESTS_COMMAND_UNIT_JS}"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-javascript-${SUFFIX} -e HOME=${CORE_ROOT}${RUNTESTS_DIR_CACHE} ${IMAGE_NODEJS_CHROME} ${RUNTESTS_COMMAND_CONTAINER_SHELL} -c "${COMMAND}"
        SUITE_EXIT_CODE=$?
        ;;
    unitRandom)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-random-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} ${RUNTESTS_DIR_BIN}phpunit -c ${RUNTESTS_PHPUNIT_FILE_UNITTEST} --order-by=random ${EXTRA_TEST_OPTIONS} ${PHPUNIT_RANDOM} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    update)
        # pull typo3/core-testing-* versions of those ones that exist locally
        echo "> pull ghcr.io/typo3/core-testing-* versions of those ones that exist locally"
        ${CONTAINER_BIN} images "ghcr.io/typo3/core-testing-*" --format "{{.Repository}}:{{.Tag}}" | xargs -I {} ${CONTAINER_BIN} pull {}
        echo ""
        # remove "dangling" typo3/core-testing-* images (those tagged as <none>)
        echo "> remove \"dangling\" ghcr.io/typo3/core-testing-* images (those tagged as <none>)"
        ${CONTAINER_BIN} images --filter "reference=ghcr.io/typo3/core-testing-*" --filter "dangling=true" --format "{{.ID}}" | xargs -I {} ${CONTAINER_BIN} rmi -f {}
        echo ""
        ;;
    *)
        loadHelp
        echo "Invalid -s option argument ${TEST_SUITE}" >&2
        echo >&2
        echo "${HELP}" >&2
        exit 1
        ;;
esac

cleanUp

# Print summary
echo "" >&2
echo "###########################################################################" >&2
echo "Result of ${TEST_SUITE}" >&2
echo "Container runtime: ${CONTAINER_BIN}" >&2
echo "PHP: ${PHP_VERSION}" >&2
if [[ ${TEST_SUITE} =~ ^(functional|functionalDeprecated|acceptance|acceptanceComposer|acceptanceInstall)$ ]]; then
    case "${DBMS}" in
        mariadb|mysql|postgres)
            echo "DBMS: ${DBMS}  version ${DBMS_VERSION}  driver ${DATABASE_DRIVER}" >&2
            ;;
        sqlite)
            echo "DBMS: ${DBMS}" >&2
            ;;
    esac
fi
if [[ -n ${EXTRA_TEST_OPTIONS} ]]; then
    echo " Note: Using -e is deprecated. Simply add the options at the end of the command."
    echo " Instead of: ${THIS_SCRIPT_NAME} -s ${TEST_SUITE} -e '${EXTRA_TEST_OPTIONS}' $@"
    echo " use:        ${THIS_SCRIPT_NAME} -s ${TEST_SUITE} -- ${EXTRA_TEST_OPTIONS} $@"
fi
if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
    echo "SUCCESS" >&2
else
    echo "FAILURE" >&2
fi
echo "###########################################################################" >&2
echo "" >&2

# Exit with code of test suite - This script return non-zero if the executed test failed.
exit $SUITE_EXIT_CODE

