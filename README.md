# presscloud/replicator

Replicator lets developers scaffold WordPress code projects using WP-CLI and Mustache Templates.

## How It Works

Replicator is a code scaffolding tool for WP-CLI using Mustache Templates, CLImate helpers and Flysystem Local file access.

Replicator has zero dependencies on WordPress Core, so it can be used to build all kinds of custom WordPress products -- including WordPress installers -- using WP-CLI.

Replicator is designed to be reused, extended and reduce bespoke code needed for a highly-customizable code scaffolding tool -- focus on making templates instead of building a code generator.

#### Included Features
* Replicate Starter Projects in WP-CLI from Mustache Templates.
* Scan destination directory for existing folder, offering to backup, partially overwrite -- leaving custom files alone or fully wipe and write.
* Handle Project Licensing and README.md files out-of-the-box.
* Easy side-loading of dynamically-generated files (example: composer.json or package.json files).
* Export built files to local filesystem using league/flysystem (allowing for futher integrations).
* Build powerful prompts, format terminal output and more using built-in league/climate helpers.

## Setup
1. `composer require presspwrd/replicator`
2. `composer install`
3. Add
    ```json
    "autoload": {
        "classmap": [
            "src/"
        ]
    }
    ```
4. `require_once vendor/autoload.php` in your project
5. Add `class-command.php` to `/src`

## Using The Command Classes

1.  In `class-command.php`,  `use` and set your Command class to extend `\PressCloud\Replicator\Base` (or `\Common`).
2.  Create `public function __invoke( $args, $assoc_args ) {}`, receiving args from WP-CLI.
3.  Inside the invoke magic method, run `$this->init()`.
4.  Create `protected function init() {}` and default varaiables like `$this->templates`, `$this->destination`, and `$this->type`. You also should run `$this->replicator_init()` in this method.
5.  Back in the invoke magic method, run `$this->setup()` after all data has been set via prompts/otherwise.
6.  Create `protected function setup() {}`, setting `$this->structure` - keys are destination file paths, values are templates files in `$this->templates`.
7.  Optionally, add `$this->sideload()` and inject prebuilt or dynamic files into `$this->files`.
8.  At the botton of `$this->__invoke()`, trigger `$this->run()`.

```php
<?php // main file
require_once dirname( __FILE__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command( 'my plugin builder', '\\My\\Plugin\\Builder\\Command' );
```

```php
<?php

namespace My\Plugin\Builder;
use \PressCloud\Replicator\Common;

class Command extends Common {

    /**
     * @param array $args - WP-CLI Positional Arguments.
     * @param array $assoc_args - WP-CLI Flags & Associative Arguments.
     */
    $this->__invoke( $args, $assoc_args ) {

        $this->init();
        $this->generic_prompts(); // in \PressPwrd\Replicator\Common
        $this->custom_prompts();

        $this->setup();
        // $this->sideload();

        /**
         * - $this->type, $this->templates, $this->destination, $this->slug, $this->data  must be set already.
         */
        $this->run();

    }

    protected function init() {

        $this->replicator_init();

        $this->type         = 'plugin';
        $this->templates    = dirname( __FILE__ ) . '/templates';
        $this->destination  = ! empty( $this->wp_dirs['plugins'] ) ? $this->wp_dirs['plugins'] : null;
    }

    protected function setup() {

        $this->structure = [
            $this->slug . '.php'    => 'plugin.php', 
            '.gitignore'            => 'gitignore',
            'src/class-api.php'     => 'api-endpoint.php',
            'assets/style.css'      => 'styles.css',
            'assets/script.js'      => 'script.js',
        ];

    }

    protected function custom_prompts() {

        $api_endpoint                   = $this->cli->input( 'Plugin API Endpoint:' );
        $api_endpoint->defaultTo( Utils\trailingslashit( $this->slug ) . '/data' );

        $this->data['api_endpoint']     = $api_endpoint->prompt();

    }

    // protected function sideload() {
    // }
}

```
