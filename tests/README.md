# PHP integration tests

The suite runs against the WordPress PHPUnit environment and a disposable set
of database tables. Test-only Composer dependencies are isolated in
`tests/vendor/` so they do not alter the plugin's bundled production
dependencies.

Install the test runner once:

```sh
composer test:install
```

Run the complete suite:

```sh
composer test
```

The defaults target a Local site on macOS: WordPress is read from the current
site, the `local` database is accessed as `root`/`root`, and only tables with
the `wptests_` prefix are created and removed. The bootstrap discovers Local's
MySQL socket automatically.

Override the defaults for another environment:

```sh
WP_CORE_DIR=/path/to/wordpress \
WP_TESTS_DB_NAME=wordpress_test \
WP_TESTS_DB_USER=root \
WP_TESTS_DB_PASSWORD=root \
WP_TESTS_DB_HOST=127.0.0.1 \
composer test
```

Never point the suite at a database where the `wptests_` prefix contains data
that must be preserved.
