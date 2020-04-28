<?php

namespace PressNitro\Replicator;

use \WP_CLI\Utils;
use \WP_CLI_Command;
use \League\CLImate\CLImate;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\ZipArchive\ZipArchiveAdapter;
use \League\Flysystem\Filesystem;
use \Mustache_Engine;
use \Dotenv\Dotenv;

/**
 * Base Class for Custom WP-CLI Commands To scaffold new WordPress projects.
 */
abstract class Base extends WP_CLI_Command {

	/**
	 * Instance of The PHP League's CLImate pacakge.
	 *
	 * @var  null|CLImate
	 * @link https://climate.thephpleague.com
	 */
	protected $cli = null;

	/**
	 * Keys and values injected into Mustache Templates.
	 *
	 * @var  array
	 * @link https://github.com/bobthecow/mustache.php/wiki
	 */
	protected $data = array();

	/**
	 * Server path to destination directory.
	 *
	 * @var null|string
	 */
	protected $destination = null;

	/**
	 * Filesystem for writing final files.
	 *
	 * @var null|Filesystem
	 */
	protected $destinationFS = null;

	/**
	 * Loaded environment variables (only variables prefixed with "REPLICATOR_")
	 *
	 * @var array
	 */
	protected $env = [];

	/**
	 * Optional directory path containing .env file with REPLICATOR_* variables.
	 * This defaults to using ABSPATH if available.
	 * 
	 * @var null|string
	 */
	protected $envDir = null;

	/**
	 * Built files to be written to $this->destinationFS.
	 *
	 * Keys are the path to the file inside the output folder ($this->slug).
	 * Values are the file contents to insert into the file.
	 *
	 * Built in $this->replicate(). Sideload dynamic files in $this->sideload().
	 *
	 * @var array
	 */
	protected $files = [];

	/**
	 * When true (default), presents warning about which files are about to be overwritten.
	 *
	 * @var boolean
	 */
	protected $overwrite_warning = true;

	/**
	 * Kebab-cased directory name, translation string, etc.
	 *
	 * @var array
	 */
	protected $slug = null;

	/**
	 * Status of $this->run().
	 *
	 * @var array
	 */
	protected $status = [
		'local' => [],
		'zip'   => [],
	];

	/**
	 * Use this variable in $this->setup(), injecting destination files and templates used to build them.
	 *
	 * Keys are the relative destination to write in the destination folder.
	 * Values are the relative path and filename for your Mustache Template in your $this->templates.
	 *
	 * Ex.
	 *
	 * array(
	 *     'README.md'                   => 'README.md',  -- i.e. /your/templates/path/README.md.mustache
	 *     'your-plugin-slug.php'        => 'plugin-file.php',
	 *     'inc/class-admin.php'         => 'singleton-class.php',
	 *     'inc/class-public.php'        => 'singleton-class.php',
	 *     'assets/style.css'            => 'css-file.css',
	 *     'src/scss/style.scss'         => 'scss-file.scss',
	 * )
	 *
	 * @var array
	 */
	protected $structure = [];

	/**
	 * Instance of Mustache_Engine
	 *
	 * @var null|Mustache
	 */
	protected $mustache = null;

	/**
	 * Absolute path to directory containing Mustache Templates.
	 *
	 * Ex. /Users/picard/templates/tea-patterns
	 *
	 * @var string
	 */
	protected $templates;

	/**
	 * An instance of Flysystem to retrieve template files.
	 *
	 * @var null|Filesystem
	 */
	protected $templatesFS = null;

	/**
	 * Set the type of product being replicated.
	 *
	 * Valid values: plugin, theme, package, other
	 *
	 * @var null|string
	 */
	protected $type = null;

	/**
	 * Handles mapping for common incorrect $this->type inputs to the correct value.
	 *
	 * @var array
	 */
	protected $typeMaps = [
		'plugin'   => [ 'map' => 'plugins' ],
		'theme'    => [ 'map' => 'themes' ],
		'root'     => [ 'map' => 'abspath' ],
		'package'  => [ 'map' => 'packages' ],
		'wpcli'    => [ 'map' => 'packages' ],
		'cmd'      => [ 'map' => 'packages' ],
	];

	/**
	 * Directory paths for WordPress
	 *
	 * Ex. array(
	 *      'abspath' 		- webroot
	 *      'wp-content' 	- webroot/wp-content
	 *      'plugins' 		- webroot/wp-content/plugins
	 *      'themes' 		- webroot/wp-content/themes
	 *      'packages' 		- path/to/wp-cli/packages/local
	 * )
	 *
	 * @var array
	 */
	protected $wpDirs = [];

	/**
	 * Optionally export a .ZIP to the destination directory.
	 *
	 * @var bool
	 */
	protected $zip = false;

	/**
	 * Filesystem for Writing Zip Archive
	 *
	 * @var null|Filesystem
	 */
	protected $zipFS = null;

	/**
	 * Use this method to set class variables, instantiate code, etc.
	 */
	abstract protected function initialConfig();

	/**
	 * Use this method to set $this->structure.
	 */
	abstract protected function setupStructure();

	/**
	 * This primary runner...
	 * - Checks if directory exists & offers options to proceed.
	 * - Sets up final output structure and templates to use.
	 * - Replicates file contents using templates.
	 * - Handles file sideload for dynamic file generation.
	 * - Writes files to destination directory.
	 * - Maybe write .ZIP to destination directory.
	 *
	 * The following variables *must* be set to run this method:
	 * - $this->templates
	 * - $this->data
	 * - $this->destination
	 */
	protected function runReplication() {
		$this->destinationDirExists();
		$this->setupStructure();
		$this->replicateFromTemplates();
		$this->sideloadDynamicFiles();
		$this->writeToDestination();
		if ( $this->zip ) {
			$this->generateZipArchive();
		}
	}

	/**
	 * Checks for existing directory/files and whether to backup, overwrite or delete.
	 */
	protected function destinationDirExists() {

		$this->destinationFSInit();

		$contents = $this->destinationFS->listContents( $this->slug );

		if ( empty( $contents ) ) {
			return false;
		} else {
			$this->cli->error( sprintf( '%s already exists.', Utils\trailingslashit( $this->destination ) . $this->slug ) );

			$backup_types = [
				'backup'    => 'Backup - duplicates directory & deletes original',
				'overwrite' => 'Overwrite or Insert - preserve custom files, only inserts/overwrites files with templates.',
				'delete'    => 'Delete',
			];

			$this->cli->dump( $backup_types );

			$proceed_input = $this->cli->input( 'How would you like to proceed?' );
			$proceed       = $proceed_input->accept( array_keys( $backup_types ), true )->prompt();

			if ( 'delete' === $proceed ) {
				$delete = $this->cli->confirm( sprintf( 'Delete %1$s?', $this->slug ) );
				if ( true === $delete->confirmed() ) {
					$this->cli->info( 'Deleting...' );
					$this->destinationFS->deleteDir( $this->slug );
				} else {
					exit;
				}
			} elseif ( 'backup' === $proceed ) {
				$this->cli->bold( 'Starting backup...' );
				$files         = [];
				foreach ( $this->destinationFS->listContents( $this->slug, true ) as $file ) {
					if ( 'file' !== $file['type'] ) {
						continue;
					}
					$file['new_path'] = str_ireplace( $this->slug, $this->slug . '-' . \uniqid( 'backup_' ), $file['path'] );
					$files[]          = $file;
				}
				$this->cli->info( sprintf( 'Found %d files to backup...', count( $files ) ) );
				foreach ( $files as $file ) {
					$this->destinationFS->copy( $file['path'], $file['new_path'] );
				}

				$this->destinationFS->deleteDir( $this->slug );
			} elseif ( true === $this->overwrite_warning ) {

			}
			// overwrite is the default behavior
		}
	}

	/**
	 * Load new patterns into the food replicator.
	 */
	protected function replicateFromTemplates() {
		if ( 
			empty( $this->structure )
			|| empty( $this->destination )
		) {
			$this->cli->error( 'Didn\'t have structure or destination.' );
		}

		$structure_count = count( $this->structure );

		$this->cli->bold( sprintf( 'Replicating %d files from Mustache Templates...', $structure_count ) );

		foreach ( $this->structure as $path => $template ) {
			if ( is_string( $contents = $this->replicateFileContents( $template ) ) ) {
				$this->files[ $path ] = $contents;
			} else {
				$this->cli->error( sprintf( '%s couldn\'t be replicated.', $template ) );
			}
		}
	}

	/**
	 * Render Mustache Template
	 *
	 * @param  string $contents - Mustache Template File Contents (with variable tags)
	 * @param  array  $data     - Associative array of data, matching tags in Mustache Templates.
	 * @return string
	 */
	protected function renderMustacheTemplate( $contents, $data = array() ) {
		return $this->mustache->render( $contents, ! empty( $data ) ? $data : $this->data );
	}

	/**
	 * Render a single file's contents. Uses Flysystem to retrieve template file and Mustache to render contents.
	 *
	 * @param  string $templateFilename - Filename for template inside $this->templates directory. The .mustache extension is automatically added.
	 * @param  array  $data             - Optionally override $this->data with a different array.
	 * @return false|string
	 */
	protected function replicateFileContents( $templateFilename, $data = array() ) {
		if ( empty( $this->templates ) ) {
			$userInputTemplates = $this->cli->input( 'No template directory specified, provide absolute path:' );
			$this->templates    = $userInputTemplates->prompt();
		}

		$this->templatesFSInit();

		$templateFilename = ( false === stripos( $templateFilename, '.mustache' ) ) ? $templateFilename . '.mustache' : $templateFilename;

		if ( $this->templatesFS->has( $templateFilename ) ) {
			$template = $this->templatesFS->read( $templateFilename );
		} else {
			return false;
		}

		return $this->renderMustacheTemplate( $template, $data );
	}

	/**
	 * Add data file and run custom sideloader
	 *
	 * @return void
	 */
	protected function sideloadDynamicFiles() {
		$this->cli->bold( 'Sideloading dynamic files...' );
		$this->files[ $this->slug . '-data.json' ] = json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->customFileSideload();
	}

	protected function customFileSideload() {
		// optional method to sideload dynamic data from filesystem or make HTTP requests for files.
	}

	/**
	 * Energize! Meet you in Transporter Room 3.
	 *
	 * Takes built file paths and built file contents 
	 * and writes them to the filesystem using Flysystem.
	 */
	protected function writeToDestination() {
		if ( empty( $this->files ) ) {
			$this->cli->to( 'error' )->red( 'Couldn\'t write empty $this->files.' );
		}

		$this->destinationFSInit();

		$this->cli->bold( sprintf( 'Writing %d files...', count( $this->files ) ) );

		foreach ( $this->files as $destination => $contents ) {
			$file    = array_pop( explode( $this->slug, $destination ) );
			$file    = $destination;
			$partial = Utils\trailingslashit( $this->slug ) . $file;

			$response = $this->destinationFS->put(
				Utils\trailingslashit( $this->slug ) . $destination,
				$contents
			);

			if ( $response ) {
				$this->cli->success( '✅ Success: ' . $file )->br();
			} else {
				$this->cli->error( '⚠️ Failed: ' . $file )->br();
			}
			
			$this->status['local'][] = [
				'destination'  => $destination,
				'partial_path' => $partial,
				'success'      => $response,
			];
		}
	}

	/**
	 * Make .zip of generated product.
	 */
	protected function generateZipArchive() {
		$this->zipInit();

		if ( ! empty( $this->files ) ) {
			foreach ( $this->files as $destination => $contents ) {
				$file    = array_pop( explode( $this->slug, $destination ) );
				$partial = Utils\trailingslashit( $this->slug ) . $file;

				$response = $this->zipFS->put(
					$destination,
					$contents
				);

				$this->status['zip'][] = [
					'destination'  => $destination,
					'partial_path' => $partial,
					'success'      => $response,
					'message'      => true === $response ? 'Successfully wrote: ' . $file : 'Failed to write: ' . $file,
				];
			}
		}

		$this->zipFS->getAdapter()->getArchive()->close();
	}

	/**
	 * Creates instance of $this->destinationFS.
	 */
	protected function destinationFSInit() {
		if ( null === $this->destinationFS ) {
			$this->destinationFS = new Filesystem( new Local( $this->destination, LOCK_EX, Local::SKIP_LINKS ) );
		}
	}

	/**
	 * Setup CLImate, Local Flysystem and Directory Paths
	 */
	protected function replicatorInit() {
		if ( version_compare( PHP_VERSION, '7.1.0', '<' ) ) {
			\WP_CLI::error( 'Replicator requires at least PHP 7.1.' );
		}
		if ( null === $this->cli ) {
			$this->cli = new CLImate();
		}
		$this->loadEnvionmentVariables();
		if ( ! defined( 'ABSPATH' ) || empty( ABSPATH ) ) {
			$this->cli->red( 'Couldn\'t find WordPress Install.' );
			$dir_input = $this->cli->radio(
				'Where should files be written?',
				[
					'.'      => 'Current Directory',
					'~'      => 'User\'s Home Directory',
					'custom' => 'Custom Path',
				]
			);
			$dir       = $dir_input->prompt();
			if ( 'custom' === $dir ) {
				$custom_input = $this->cli->input( 'Enter an absolute path:' );
				$dir          = $custom_input->prompt();
			}
			$this->destination = $dir;
		} else {

			$this->wpDirs['abspath'] = ABSPATH;

			if ( empty( $this->wpDirs['wp-content'] ) ) {
				$this->wpDirs['wp-content'] = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : $this->wpDirs['abspath'] . '/wp-content';
			}
			if ( empty( $this->wpDirs['plugins'] ) ) {
				$this->wpDirs['plugins'] = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : $this->wpDirs['wp-content'] . '/plugins';
			}
			if ( empty( $this->wpDirs['themes'] ) ) {
				$this->wpDirs['themes'] = $this->wpDirs['wp-content'] . '/themes';
			}

		}
		if ( ! empty( $pkg_dir = getenv( 'WP_CLI_PACKAGES_DIR' ) ) ) {
			$this->wpDirs['packages'] = $pkg_dir;
		}
		if ( null === $this->mustache ) {
			$this->mustache = new Mustache_Engine(
				array(
					'escape' => function ( $val ) {
						return $val;
					},
				)
			);
		}
		$this->setType();
	}

	/**
	 * Initialize Mustache and Templates Flysystem.
	 */
	protected function templatesFSInit() {
		if ( null === $this->templatesFS ) {
			$this->templatesFS = new Filesystem( new Local( $this->templates ) );
		}
	}

	/**
	 * Initialize .zip functionality
	 */
	protected function zipInit() {
		if ( null === $this->zipFS ) {
			$this->zipFS = new Filesystem( new ZipArchiveAdapter( $this->destination . "/{$this->slug}.zip" ) );
		}
	}

	/**
	 * Set $this->type and attempt to automatically set $this->destination.
	 *
	 */
	protected function setType() {

		if ( isset( $this->typeMaps[ $this->type ]['map'] ) ) {
			$this->type = $this->typeMaps[ $this->type ]['map'];
		}
		
		if ( empty( $this->destination ) && isset( $this->wpDirs[ $this->type ] ) ) {
			$this->destination = $this->wpDirs[ $this->type ];
		}
		
	}

	/**
	 * Load environment variables (or constants) and inject into $this->env without prefix.
	 * 
	 * PHP Constants supercede environment variables.
	 *
	 * @return void
	 */
	protected function loadEnvionmentVariables() {
		if ( ! class_exists( '\\Dotenv\\Dotenv' ) ) {
			return false;
		}
		if ( 
			! empty( $this->envDir ) 
			&& is_readable( $this->envDir . '/.env' )
		) {
			$dotenv = Dotenv::createImmutable( $this->envDir );
			$dotenv->load();
		} elseif ( 
			defined( 'ABSPATH' ) 
			&& is_readable( ABSPATH . '/.env' )
		) {
			$dotenv = Dotenv::createImmutable( ABSPATH );
			$dotenv->load();
		}

		foreach( getenv() as $key => $value ) {
			if ( false === stripos( $key, 'REPLICATOR_' ) ) {
				continue;
			}
			$cleaned_key = str_ireplace( 'REPLICATOR_', '', $key );
			if ( ! empty( $value ) ) {
				$this->env[ $cleaned_key ] = $value;
			}
		}
	}
}
