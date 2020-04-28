# Basic Setup

This class should most likely live in a WP-CLI Package, or perhaps WordPress Plugin. It probably doesn't belong in a WordPress Theme.

## Require Replicator Files & Register WP-CLI Command

### Using Composer (recommended)

1. `composer install pressnitro/replicator` (or install `pressnitro/replicate` command to branch off an existing generator)
2. In an instantiated file, use `require_once` to include the Composer Autoload file in your project _(this should not be inside a WordPress Action Hook -- this should execute earlier than **init** or **plugins_loaded**)_.
3. Name and create a new PHP class, and use a Composer autoloading scheme to make sure it's included.

```php
require_once dirname( __FILE__ ) . '/vendor/composer/autoload.php';
```

### Using Vanilla PHP workflow

1. Download or `git clone` this repository into a directory in your project (i.e. `lib`).
2. In an instantiated file, use `require_once` to include the `Base` class file -- if you're using the `Common` class require the `Base` class file first, then require the `Common` class file _(this should not be inside a WordPress Action Hook)_.
3. Name and create a new PHP class, and require it last.

```php
<?php
require_once 'path/to/replicator/src/class-base.php';
// then, if using the Common class...
require_once 'path/to/replicator/src/class-common.php';

require_once 'path/to/custom-class.php';
```

### Setup WP-CLI Command
Below your require statements, check for WP-CLI and register a new Command.

```php
if ( ! defined( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command(
    'replicate magic',
    '\\Brand\\Replicate\\Magic\\Command'
);
```

## Setup the Command Class with Common
Then in your Command class...
* Extend one of the Replicator classes.
* Create a `public function __invoke() {}` method.
    * Run `$this->initialConfig()` first.
    * Then run `$this->commonInit( $args, $assoc_args )` from the `Common` class.
    * In-between is a great opportunity to inject custom functionality.
    * Finally, run `$this->runReplication()` last. 
* Create a `protected function initialConfig() {}` method.
* Create a `protected function setupStructure() {}` method.
* (Optional) Add a PHP namespace
* (Optional) Add use statement(s) for `\WP_CLI` and `\PressCloud\Replicator\Common`.

```php
<?php
namespace Brand\Replicate\Magic;

use \WP_CLI;
use \PressCloud\Replicator\Common;

class Command extends Common {
    public function __invoke( $args, $assoc_args ) {
        $this->initialConfig();

        $this->commonInit( $args, $assoc_args );

        $this->runReplication();
    }

    protected function initialConfig() {
        $this->type      = 'plugin';
        $this->templates = dirname( __FILE__ ) . '/templates';
    }

    protected function setupStructure() {
        $this->structure = [
            '{$this->slug}.php'         => 'main.php'
            'inc/public.php'            => 'class.php',
            'inc/authenticated.php'     => 'class.php',
            'assets/public.css'         => 'css.css',
            'assets/authenticated.css'  => 'css.css',
            'CONTRIBUTING.md'           => 'CONTRIBUTING.md',
            'assets/brand-logo.png'     => 'logo.png',                  
        ];
    }
}
```
