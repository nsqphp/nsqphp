
all: install composer-validate php-cs-fixer psalm phpstan phpunit

install:
	composer install

psalm:
	php vendor/bin/psalm

phpstan:
	php vendor/bin/phpstan analyse

phpunit:
	php vendor/bin/phpunit

php-cs-fixer:
	php vendor/bin/php-cs-fixer fix

composer-validate:
	composer validate
