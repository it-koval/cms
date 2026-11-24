#!/bin/sh
set -eu

# The global Flex install pins the Symfony line through SYMFONY_REQUIRE.
composer global config --no-plugins allow-plugins.symfony/flex true
composer global require --no-progress --no-scripts --no-plugins symfony/flex
if [ "$1" = lowest ]; then
    set -- --prefer-lowest --prefer-stable
else
    set --
fi
composer update --no-progress --prefer-dist --no-interaction "$@"
composer show symfony/framework-bundle
