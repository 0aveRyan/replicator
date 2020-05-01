<?php

namespace Replicator\Core;

use \WP_CLI;
use \WP_CLI\Utils;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\Filesystem;

/**
 * Basic prompts for universal data and license handling.
 */
abstract class Common extends Base {

	/**
	 * Run License Handler
	 *
	 * @var boolean
	 */
	protected $handleLicense;

	/**
	 * Run Readme Handler
	 *
	 * @var boolean
	 */
	protected $handleReadme;

	/**
	 * Should handle Tests rig setup?
	 */
	protected $handleTests;

	/**
	 * Flysystem Filesystem for this package.
	 *
	 * @var null|Flilesystem
	 */
	protected $commonFS = null;

	/**
	 * Composer.json Data
	 */
	protected $composer_data;

	/**
	 * Package.json Data
	 */
	protected $package_data;

	/**
	 * Types of licenses
	 *
	 * @var array
	 */
	protected $licenses = [
		'apache2' => [
			'label' => 'Apache 2.0',
			'spdx'  => 'Apache-2.0',
			'url'   => 'https://www.apache.org/licenses/LICENSE-2.0.txt',
		],
		'gpl2'    => [],
		'gpl3'    => [],
		'mit'     => [
			'label' => 'MIT',
			'spdx'  => 'mit',
			'url'   => 'https://opensource.org/licenses/MIT',
		],
		'private' => [
			'label' => 'Private. Not for distribution.',
			'spdx'  => '',
			'url'   => '',
		],
		'other'   => [
			'label' => 'Other (don\'t handle license)',
		],
	];

	/**
	 * GPL License Types
	 *
	 * @var array
	 */
	protected $gplTypes = [
		'gpl2only'  => [
			'spdx'  => 'GPL-2.0-only',
			'label' => 'GNU General Public License v2.0 only',
			'url'   => 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt',
		],
		'gpl2later' => [
			'spdx'  => 'GPL-2.0-or-later',
			'label' => 'GNU General Public License v2.0 or later',
			'url'   => 'https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt',
		],
		'gpl3only'  => [
			'spdx'  => 'GPL-3.0-only',
			'label' => 'GNU General Public License v3.0 only',
			'url'   => 'https://www.gnu.org/licenses/gpl-3.0.txt',
		],
		'gpl3later' => [
			'spdx'  => 'GPL-3.0-or-later',
			'label' => 'GNU General Public License v3.0 or later',
			'url'   => 'https://www.gnu.org/licenses/gpl-3.0.txt',
		],
	];

	/**
	 * Undocumented variable
	 *
	 * @var [type]
	 */
	protected $labelType;

	/**
	 *
	 */
	protected $defaultsType;

	/**
	 * Use this method to set class variables, instantiate code, etc.
	 */
	abstract protected function initialConfig();

	/**
	 * Use this method to set $this->structure.
	 *
	 * @return void
	 */
	abstract protected function setupStructure();

	/**
	 * Undocumented function
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	protected function commonPrompts( $args = [], $assoc_args = [] ) {
		$this->replicatorInit();
		$this->commonInit();

		// if empty flag value, then handle feature, otherwise if flag is set don't handle
		$this->handleLicense = empty( Utils\get_flag_value( $assoc_args, 'no-license', false ) ) ? true : false;
		$this->handleReadme  = empty( Utils\get_flag_value( $assoc_args, 'no-readme', false ) ) ? true : false;
		$this->handleTests   = empty( Utils\get_flag_value( $assoc_args, 'no-tests', false ) ) ? true : false;

		$this->destination = ! empty( $i = Utils\get_flag_value( $assoc_args, 'dest', false ) ) ? $this->handleCustomDir( $i ) : $this->destination;
		$this->templates   = ! empty( $t = Utils\get_flag_value( $assoc_args, 'templates', false ) ) ? $this->handleCustomDir( $t ) : $this->templates;

		$this->resolveDefaultsType( $assoc_args );
		$this->namingPrompts();
		$this->authorAndUrlPrompts();

		if ( $this->handleLicense ) {
			$this->data['copyright_year'] = date( 'Y' );
			$this->licenseHandler();
		}

		$this->handleConfigFiles();

		if ( $this->handleTests ) {
			WP_CLI::add_hook(
				'after_invoke:replicate plugin',
				function() {
					WP_CLI::runcommand( 'scaffold plugin-tests ' . $this->slug );
				}
			);
			WP_CLI::add_hook(
				'after_invoke:replicate theme',
				function() {
					WP_CLI::runcommand( 'scaffold theme-tests ' . $this->slug );
				}
			);
		}
	}

	/**
	 * Resolves which set of defaults to use -- "user" or "org".
	 */
	protected function resolveDefaultsType( $assoc_args ) {
		if ( ! empty( $type = Utils\get_flag_value( $assoc_args, 'defaults', false ) )
			&& ( 'user' === $type || 'org' === $type )
		) {
			$this->defaultsType = strtoupper( $type ) . '_';
		} elseif ( ! empty( $this->env['DEFAULTS_TYPE'] ) ) {
			$this->defaultsType = strtoupper( $this->env['DEFAULTS_TYPE'] ) . '_';
		} elseif ( // when an 'org_' key is set in env vars, ask which set to use
			! empty(
				array_filter(
					$this->env,
					function( $key ) {
						return false !== stripos( $key, 'org_' );
					},
					ARRAY_FILTER_USE_KEY
				)
			)
		) {
			$defaultsTypePrompt = $this->cli->radio( 'Which set of environment variables should be used?', [ 'User', 'Org' ] );
			$this->defaultsType = strtoupper( $defaultsTypePrompt->prompt() ) . '_';
		} else {
			$this->defaultsType = 'USER_';
		}
	}

	/**
	 * Naming Prompts
	 */
	protected function namingPrompts() {

		$this->labelType = ! empty( $this->type ) ? rtrim( ucwords( $this->type ), 's' ) : 'Product';

		/**
		 * - Switch between package and other types
		 * - Only ask for label for other types
		 * - Build in README.md scanner and Markdown to Text Generator.
		 */
		if ( 'packages' === $this->type ) {
			$this->cli->br()->out( "WP-CLI Commands require a name string that command functionality maps to -- 'plugin' is the command name for 'wp plugin'. " )->br();
			$name                   = $this->cli->input( 'Command Name: wp ' );
			$this->data['cmd_name'] = $name->prompt();
			$this->replicateDefaults( true );
		}

		if ( empty( $this->data['label'] ) ) {
			$label               = $this->cli->input( sprintf( '%s Name:', $this->labelType ) );
			$this->data['label'] = $label->prompt();
		}

		if ( 'packages' !== $this->type ) {
			$this->replicateDefaults();
		}

		if ( empty( $this->data['slug'] ) ) {
			$slug = $this->cli->input( sprintf( '%s Slug:', $this->labelType ) );
			$slug->defaultTo( $this->replicateDefaultSlug() );
			$this->data['slug'] = $slug->prompt();
			$this->slug         = $this->data['slug'];
		}

		if ( empty( $this->data['namespace'] ) ) {
			$namespace = $this->cli->input( sprintf( '%s Namespace:', $this->labelType ) );
			$namespace->defaultTo( $this->replicateDefaultNamespace() );
			$this->data['namespace'] = $namespace->prompt();
		}

		$this->data['namespace_check'] = str_ireplace( '\\', '\\\\', $this->data['namespace'] );

		$desc               = $this->cli->input( sprintf( '%s Description:', $this->labelType ) );
		$this->data['desc'] = $desc->prompt();

		if ( ! empty( $this->env['DEFAULT_VERSION'] ) && ! empty( $this->env['ALWAYS_DEFAULTS'] ) ) {
			$this->data['version'] = $this->env['DEFAULT_VERSION'];
		}

		if ( empty( $this->data['version'] ) ) {
			$default_version       = ! empty( $this->env['DEFAULT_VERSION'] ) ? $this->env['DEFAULT_VERISON'] : '0.1.0';
			$version               = $this->cli->input( sprintf( '%s Version:', $this->labelType ) );
			$this->data['version'] = $version->defaultTo( $default_version )->prompt();
		}

		$this->data['constant_prefix'] = strtoupper( str_ireplace( '-', '_', $this->data['slug'] ) );
	}

	protected function authorAndUrlPrompts() {

		if ( empty( $this->env[ "{$this->defaultsType}GIT_HOST" ] ) ) {
			$git_host                       = $this->cli->input( 'Which Git host do you use?' );
			$this->data['creator_git_host'] = $git_host->accept( [ 'github', 'gitlab', 'bitbucket', 'other', 'none' ], true )->prompt();
			if ( 'other' === $this->data['creator_git_host'] ) {
				$custom_git                     = $this->cli->input( 'Enter the domain of your Git host:' );
				$this->data['creator_git_host'] = $custom_git->prompt();
			}
		} else {
			$this->data['creator_git_host'] = $this->env[ "{$this->defaultsType}GIT_HOST" ];
		}

		$prompts = [
			'creator_name'         => '%s Author Name',
			'creator_email'        => '%s Author Email',
			'creator_url'          => '%s Author URL',
			'creator_git_username' => 'Git Username',
		];

		if ( 'none' === $this->data['git_host'] ) {
			unset( $prompts['creator_git_username'] );
		}

		foreach ( $prompts as $key => $label ) {
			${$key} = $this->cli->input( sprintf( $label . ':', $this->labelType ) );
			$envKey = $this->defaultsType . strtoupper( str_ireplace( 'creator_', '', $key ) );
			if ( ! empty( $this->env['ALWAYS_DEFAULTS'] ) && ! empty( $this->env[ $envKey ] ) ) {
				$this->data[ $key ] = $this->env[ $envKey ];
			} elseif ( ! empty( $this->env[ $envKey ] ) ) {
				$this->cli->bold( 'Set custom or hit return to use "' . $this->env[ $envKey ] . '" for ' . $key . '...' );
				$this->data[ $key ] = ${$key}->defaultTo( $this->env[ $envKey ] )->prompt();
			} else {
				$this->data[ $key ] = ${$key}->prompt();
			}
		}
		if ( 'none' !== $this->data['creator_git_host'] ) {
			$this->data['creator_git_url'] = 'https://' . $this->data['creator_git_host'] . '.com/' . Utils\trailingslashit( $this->data['creator_git_username'] ) . $this->slug;
		}
	}

	/**
	 * Handle License Selection
	 *
	 * @return void
	 */
	protected function licenseHandler() {
		if ( empty( $this->env['DEFAULT_LICNESE'] ) ) {
			$license_type = $this->cli->input( 'License Type:' );
			$selected     = $license_type->accept( array_keys( $this->licenses ), true )->defaultTo( 'gplv3' )->prompt();

			if ( 'gpl2' === $selected || 'gpl3' === $selected ) {
				$all_licenses                = $this->cli->input( sprintf( 'Use %s only or %s and later versions?', $selected, $selected ) );
				$selected                    = $selected . $all_licenses->accept( [ 'only', 'later' ], true )->prompt();
				$this->licenses[ $selected ] = $this->gplTypes[ $selected ];
			}
		} else {
			$selected = $this->env['DEFAULT_LICENSE'];
		}

		if ( 'other' !== $selected ) {
			$this->data['license_spdx']  = $this->licenses[ $selected ]['spdx'];
			$this->data['license_label'] = $this->licenses[ $selected ]['label'];
			$this->data['license_url']   = $this->licenses[ $selected ]['url'];

			if ( $this->commonFS->has( "licenses/{$selected}.mustache" ) ) {
				$this->files['LICENSE'] = $this->renderMustacheTemplate(
					$this->commonFS->read( "licenses/{$selected}.mustache" ),
					$this->data
				);
			}

			if ( $this->commonFS->has( "licenses/{$selected}-readme.mustache" ) ) {
				$this->data['license_readme_insert'] = $this->renderMustacheTemplate(
					$this->commonFS->read( "licenses/{$selected}-readme.mustache" ),
					$this->data
				);
			}
		}
	}

	protected function modifyConfigFiles() {
		// Optionally override/update config files
	}

	protected function handleConfigFiles() {
		$this->composer_data = $this->replicateJSONFile( 'composer' );
		$this->package_data  = $this->replicateJSONFile( 'npm' );

		$this->modifyConfigFiles();

		$this->files['composer.json'] 	= json_encode( $this->composer_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->files['package.json'] 	= json_encode( $this->package_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	protected function replicateJSONFile( $type ) {
		$package = [
			'name'        => $this->data['slug'],
			'version'     => $this->data['version'],
			'description' => $this->data['desc'],
		];

		$author = [
			'name'  => $this->data['creator_name'],
			'email' => $this->data['creator_email'],
			'url'   => $this->data['creator_url'],
		];

		if ( 'npm' === $type ) {
			$package['author'] = $author;
		} elseif ( 'composer' === $type ) {
			unset( $author['url'] );
			$package['authors'] = [ $author ];
		}

		if ( 'composer' === $type && ! empty( $this->data['creator_git_username'] ) ) {
			$package['name'] = $this->data['creator_git_username'] . '/' . $this->data['slug'];
		}

		if ( ! empty( $this->data['license_spdx'] ) ) {
			$package['license'] = $this->data['license_spdx'];
		} elseif ( 'composer' === $type ) {
			$package['license'] = 'proprietary';
		} else {
			$package['license'] = 'UNLICENSED';
			$package['private'] = true;
		}

		if ( 'npm' === $type ) {
			if ( ! empty( $this->data['creator_git_url'] ) ) {
				$package['bugs']       = Utils\trailingslashit( $this->data['creator_git_url'] ) . 'bugs';
				$package['homepage']   = $this->data['creator_git_url'];
				$package['repository'] = $this->data['creator_git_host'] . ':' . $this->data['creator_git_username'] . '/' . $this->data['slug'];
			}
		}

		return $package;
	}

	/**
	 * Transform '.' or './relative/path' into an absolute path.
	 *
	 * @param string $input
	 * @return string
	 */
	protected function handleCustomDir( $input ) {
		if ( 'current' === $input ) {
			return getcwd();
		}

		return trim( realpath( $input ) );
	}

	protected function replicateDefaults( $cli = false ) {
		$defaults = [];
		if ( $cli ) {
			$defaults['label'] = ucwords( $this->data['cmd_name'] ) . ' Command';
		}
		$defaults['slug']      = $this->replicateDefaultSlug( $defaults['label'] );
		$defaults['namespace'] = $this->replicateDefaultNamespace( $defaults['label'] );
		$this->cli->table( [ $defaults ] );
		$useDefaults = $this->cli->confirm( 'Use defaults above? ("n" to define custom)' );
		if ( true === $useDefaults->confirmed() ) {
			foreach ( $defaults as $key => $value ) {
				$this->data[ $key ] = $value;
			}
			$this->slug = $this->data['slug'];
		}
	}

	protected function replicateDefaultSlug( $label = null ) {
		return strtolower( str_ireplace( [ ' ', '_' ], '-', preg_replace( '/[^\da-z- ]/i', '', empty( $label ) ? $this->data['label'] : $label ) ) );
	}

	protected function replicateDefaultNamespace( $label = null ) {
		return ucwords( str_ireplace( '-', '_', $this->replicateDefaultSlug( empty( $label ) ? $this->data['label'] : $label ) ), '_' );
	}

	/**
	 * Initialize Filesystem to read /licenses and /templates in this folder.
	 *
	 * @return void
	 */
	protected function commonInit() {
		if ( null === $this->commonFS ) {
			$this->commonFS = new Filesystem( new Local( __DIR__ ) );
		}
	}
}
