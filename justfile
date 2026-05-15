default:
    @just --list

install:
    composer install

update:
    composer update

lint:
    composer check-cs

fix:
    composer fix-cs

phpstan:
    composer phpstan

check: lint phpstan
