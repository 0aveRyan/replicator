<?php

namespace PressCloud\Replicator;

use \WP_CLI\Utils;
use \WP_CLI_Command;
use \League\CLImate\CLImate;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\ZipArchive\ZipArchiveAdapter;
use \League\Flysystem\Filesystem;
use \Mustache_Engine;

/**
 * Base Class for Custom WP-CLI Commands To Scaffold WordPress Projects to the Local Filesystem.
 */
abstract class Base extends WP_CLI_Command {

	/**
	 * Instance of CLImate.
	 *
	 * @var null|CLImate
	 * @link https://climate.thephpleague.com
	 */
	protected $cli = null;

	/**
	 * Keys and values injected into Mustache Templates.
	 *
	 * @var array
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
	protected $destination_fs = null;

	/**
	 * Built files to be written to $this->destination_fs.
	 *
	 * Keys are the relative path to the file inside the output folder ($this->slug).
	 * Values are the file contents to insert into the file.
	 *
	 * Built in $this->replicate(). Sideload dynamic files in $this->sideload().
	 *
	 * @var array
	 */
	protected $files = [];

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
	protected $templates_fs = null;

	/**
	 * Set the type of product being replicated.
	 *
	 * Valid values: plugin, theme, package, other
	 *
	 * @var null|string
	 */
	protected $type = null;

	/**
	 * Accepted Type Strings for Automatic Destination Directory Selection.
	 *
	 * @var array
	 */
	protected $types = [
		'plugin'   => [ 'map' => 'plugins' ],
		'plugins'  => [ 'slug' => 'plugins' ],
		'theme'    => [ 'map' => 'themes' ],
		'themes'   => [ 'slug' => 'themes' ],
		'abspath'  => [ 'slug' => 'abspath' ],
		'root'     => [ 'map' => 'abspath' ],
		'package'  => [ 'map' => 'packages' ],
		'packages' => [ 'slug' => 'packages' ],
		'wpcli'    => [ 'map' => 'packages' ],
		'cmd'      => [ 'map' => 'packages' ],
	];

	/**
	 * Directory paths for WordPress
	 *
	 * Ex. array(
	 *      'abspath'       => $, - root
	 *      'wp-content'    => $, - root/wp-content
	 *      'plugins'       => $, - root/wp-content/plugins
	 *      'themes'        => $, - root/wp-content/themes
	 *      'packages'      => $, - ../wp-cli/packages/local
	 * )
	 *
	 * @var array
	 */
	protected $wp_dirs = [];

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
	protected $zip_fs = null;

	/**
	 * Use this method to set class variables, instantiate code, etc.
	 */
	abstract protected function init();

	/**
	 * Use this method to set $this->structure.
	 */
	abstract protected function setup();

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
	protected function run() {
		$this->exists();
		$this->setup();
		$this->replicate();
		$this->sideload();
		$this->write();
		if ( $this->zip ) {
			$this->zip();
		}
	}

	/**
	 * Handles existing directory/files and whether to backup, overwrite or delete.
	 */
	protected function exists() {

		$this->destination_fs_init();

		$contents = $this->destination_fs->listContents( $this->slug );

		if ( empty( $contents ) ) {
			return false;
		} else {
			$this->cli->error( sprintf( '%s already exists.', Utils\trailingslashit( $this->destination ) . $this->slug ) );

			$backup_types = [
				'backup'    => 'Backup - duplicates directory & deletes original',
				'overwrite' => 'Overwrite - preserves custom files.',
				'delete'    => 'Delete',
			];

			$this->cli->dump( $backup_types );

			$proceed_input = $this->cli->input( 'How would you like to proceed?' );
			$proceed       = $proceed_input->accept( array_keys( $backup_types ), true )->prompt();

			if ( 'delete' === $proceed ) {
				$delete = $this->cli->confirm( sprintf( 'Delete %1$s?', $this->slug ) );
				if ( true === $delete->confirmed() ) {
					$this->cli->info( 'Deleting...' );
					$this->destination_fs->deleteDir( $this->slug );
				} else {
					exit;
				}
			} elseif ( 'backup' === $proceed ) {
				$this->cli->bold( 'Starting backup...' );
				$full_contents = $this->destination_fs->listContents( $this->slug, true );
				$backup_dir    = $this->slug . '-' . \uniqid( 'backup_' );
				$files         = [];
				foreach ( $full_contents as $file ) {
					if ( 'file' !== $file['type'] ) {
						continue;
					}
					$file['new_path'] = str_ireplace( $this->slug, $backup_dir, $file['path'] );
					$files[]          = $file;
				}
				$this->cli->info( sprintf( 'Found %d files to backup...', count( $files ) ) );
				foreach ( $files as $file ) {
					$this->destination_fs->copy( $file['path'], $file['new_path'] );
				}

				$this->destination_fs->deleteDir( $this->slug );
			}
			// overwrite is the default behavior
		}
	}

	/**
	 * Load new patterns into the food replicator.
	 */
	protected function replicate() {
		if ( empty( $this->structure )
			|| empty( $this->destination )
		) {
			$this->cli->error( 'Didn\'t have structure or destination.' );
		}

		$structure_count = count( $this->structure );
		$this->cli->bold( sprintf( 'Replicating %d files from Mustache Templates...', $structure_count ) );
		foreach ( $this->structure as $path => $template ) {
			$contents = $this->replicate_file_contents( $template );
			if ( is_string( $contents ) ) {
				$this->files[ $path ] = $contents;
				$this->cli->sucess( sprintf( '🖨  %s replicated.', $path ) )->br();
			} else {
				$this->cli->error( sprintf( '%s couldn\'t be rendered.', $template ) );
			}
		}
		$this->cli->bold( sprintf( '... replicated %d files.', $structure_count ) );

		

	}

	/**
	 * Render Mustache Template
	 *
	 * @param string $contents - Mustache Template File Contents (with variable tags)
	 * @param array  $data - Associative array of data, matching tags in Mustache Templates.
	 * @return string
	 */
	protected function render( $contents, $data = array() ) {
		return $this->mustache->render( $contents, ! empty( $data ) ? $data : $this->data );
	}

	/**
	 * Renders a single file's contents. Uses Flysystem to retrieve template file and Mustache to render contents.
	 *
	 * @param string $template - Filename for template inside $this->templates directory. The .mustache extension is automatically added.
	 * @param array  $data      - Optionally override $this->data with a different array.
	 * @return false|string
	 */
	protected function replicate_file_contents( $template, $data = array() ) {
		if ( empty( $this->templates ) ) {
			$this->templates = $this->cli->input( 'No template directory specified, provide absolute path:' )->prompt();
		}

		$this->templates_fs_init();

		$template = ( false === stripos( $template, '.mustache' ) ) ? $template . '.mustache' : $template;

		if ( $this->templates_fs->has( $template ) ) {
			$template_contents = $this->templates_fs->read( $template );
		} else {
			return false;
		}

		return $this->render( $template_contents, $data );
	}

	/**
	 * Optional function for use in extending class to sideload files or make final adjustments.
	 */
	protected function sideload() {
		// optional
	}

	/**
	 * Energize! Meet you in Transporter Room 3.
	 *
	 * Takes built file paths and built file contents and writes them
	 * to the filesystem using Flysystem.
	 */
	protected function write() {
		if ( empty( $this->files ) ) {
			$this->cli->to( 'error' )->red( 'Couldn\'t write empty $this->files.' );
		}

		$this->destination_fs_init();

		$this->cli->bold( 'Writing files...' );
		foreach ( $this->files as $destination => $contents ) {
			$file    = array_pop( explode( $this->slug, $destination ) );
			$partial = Utils\trailingslashit( $this->slug ) . $file;

			$response = $this->destination_fs->put(
				Utils\trailingslashit( $this->slug ) . $destination,
				$contents
			);

			$msg = true === $response ? '✅ Successfully wrote: ' . $file : '⚠️ Failed to write: ' . $file;
			$this->cli->success( $msg )->br();
			$this->status['local'][] = [
				'destination'  => $destination,
				'partial_path' => $partial,
				'success'      => $response,
				'message'      => $msg,
			];
		}

		$this->zip();
	}

	/**
	 * Make .zip of generated product.
	 */
	protected function zip() {
		$this->zip_init();

		if ( ! empty( $this->files ) ) {
			foreach ( $this->files as $destination => $conents ) {
				$file    = array_pop( explode( $this->slug, $destination ) );
				$partial = Utils\trailingslashit( $this->slug ) . $file;

				$response = $this->zip_fs->put(
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

		$this->zip_fs->getAdapter()->getArchive()->close();
	}

	/**
	 * Creates instance of $this->destination_fs.
	 */
	protected function destination_fs_init() {
		if ( null === $this->destination_fs ) {
			$this->destination_fs = new Filesystem( new Local( $this->destination, LOCK_EX, Local::SKIP_LINKS ) );
		}
	}

	/**
	 * Setup CLImate, Local Flysystem and Directory Paths
	 */
	protected function replicator_init() {
		if ( null === $this->cli ) {
			$this->cli = new CLImate();
		}
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
			if ( empty( $this->wp_dirs['abspath'] ) ) {
				$this->wp_dirs['abspath'] = ABSPATH;
			}
			if ( empty( $this->wp_dirs['wp-content'] ) ) {
				$this->wp_dirs['wp-content'] = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : $this->wp_root_dir . '/wp-content';
			}
			if ( empty( $this->wp_dirs['plugins'] ) ) {
				$this->wp_dirs['plugins'] = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : $this->wp_root_dir . '/wp-content/plugins';
			}
			if ( empty( $this->wp_dirs['themes'] ) ) {
				$this->wp_dirs['themes'] = $this->wp_content . '/themes';
			}
		}
		if ( empty( $this->wp_dirs['packages'] ) && defined( 'WP_CLI_PACKAGES_DIR' ) ) {
			$this->wp_dirs['packages'] = WP_CLI_PACKAGES_DIR;
		}
		if ( null === $this->mustache ) {
			$this->mustache = new \Mustache_Engine(
				array(
					'escape' => function( $val ) {
						return $val;
					},
				)
			);
		}
	}

	/**
	 * Initialize Mustache and Templates Flysystem.
	 */
	protected function templates_fs_init() {
		if ( null === $this->templates_fs ) {
			$this->templates_fs = new Filesystem( new Local( $this->templates ) );
		}
	}

	/**
	 * Initialize .zip functionality
	 */
	protected function zip_init() {
		if ( null === $this->zip_fs ) {
			$this->zip_fs = new Filesystem( new ZipArchiveAdapter( $this->destination . "/{$this->slug}.zip" ) );
		}
	}

	/**
	 * Set $this->type and attempt to automatically set $this->destination.
	 *
	 * @param null|string|false $input - user-input string for confirmation/setting.
	 */
	protected function set_type( $input ) {
		if ( empty( $input ) ) {
			return null;
		}
		if ( isset( $this->types[ $input ] ) ) {
			$set_type = [];
			if ( ! empty( $this->types[ $input ] )
				&& is_array( $this->types[ $input ] )
			) {
				if ( isset( $this->types[ $input ]['map'] ) ) {
					$set_type = $this->types[ $input ]['map'];
				} else {
					$set_type = $this->types[ $input ]['slug'];
				}
				$this->type = $set_type;
				if ( isset( $this->wp_dirs[ $set_type ] ) ) {
					$this->destination = $this->wp_dirs[ $set_type ];
				}
			}
		}

		return false;
	}
}
