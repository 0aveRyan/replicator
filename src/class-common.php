<?php

namespace PressCloud\Replicator;

use \PressCloud\Replicator\Base;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\Filesystem;
use \Mustache_Engine;

/**
 * Basic prompts for universal data and license handling.
 */
abstract class Common extends Base {

	/**
	 * If you're generating a WP-CLI Command (in a package or plugin), this toggle
	 * will ask for the command name (i.e. wp [command-name]) and attempt to generate
	 * the Plugin/Pkg Name, Directory slug, PHP Namespace, PHP Package string.
	 *
	 * It will show generated strings in a Table and allow custom override.
	 *
	 * @var boolean
	 */
	protected $is_wpcli_cmd = false;

	/**
	 * Run License Handler
	 *
	 * @var boolean
	 */
	protected $handle_license = true;

	/**
	 * Flysystem Filesystem for this package.
	 *
	 * @var null|Flilesystem
	 */
	protected $common_fs = null;

	/**
	 * Types of licenses
	 *
	 * @var array
	 */
	protected $licenses = [
		'gpl2'    => [],
		'gpl3'    => [],
		'mit'     => [
			'label' => 'MIT',
			'spdx'  => 'mit',
		],
		'private' => [
			'label' => 'Private. Not for distribution.',
			'spdx'  => '',
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
	protected $gpl_types = [
		'gpl2only'    => [
			'spdx'  => 'GPL-2.0-only',
			'label' => 'GNU General Public License v2.0 only',
		],
		'gpl2greater' => [
			'spdx'  => 'GPL-2.0-or-later',
			'label' => 'GNU General Public License v2.0 or later',
		],
		'gpl3only'    => [
			'spdx'  => 'GPL-3.0-only',
			'label' => 'GNU General Public License v3.0 only',
		],
		'gpl3greater' => [
			'spdx'  => 'GPL-3.0-or-later',
			'label' => 'GNU General Public License v3.0 or later',
		],
	];

	/**
	 * Use this method to set class variables, instantiate code, etc.
	 */
	abstract protected function init();

	/**
	 * Use this method to set $this->structure.
	 *
	 * @return void
	 */
	abstract protected function setup();

	/**
	 * Universal prompts often needed in a scaffold generator.
	 *
	 * @param boolean $is_cmd - Toggle for is WP-CLI Command.
	 *
	 * @return void
	 */
	protected function generic_prompts( $is_cmd = false ) {
		$this->common_init();

		$label_type = ! empty( $this->type ) ? ucwords( $this->type ) : 'Product';

		if ( $this->is_wpcli_cmd || $is_cmd ) {
			$this->cli->dim( 'Set the command/subcommand to be registered with WP-CLI' );
			$cmd_name               = $this->cli->input( 'WP-CLI Command Name: wp ' );
			$this->data['cmd_name'] = $cmd_name->prompt();

			$cmd_data              = [];
			$cmd_data['label']     = ucwords( $this->data['cmd_name'] ) . ' Command';
			$cmd_data['slug']      = strtolower( str_ireplace( [ ' ', '_' ], '-', preg_replace( '/[^\da-z- ]/i', '', $cmd_data['label'] ) ) );
			$cmd_data['namespace'] = ucwords( str_ireplace( [ ' ', '-' ], '_', $this->data['cmd_name'] ) );

			$this->cli->br()->out( 'Slugs are used for directory names and translations, namespaces are used for package names and PHP namespaces.' )->br();
			$this->cli->json( $cmd_data )->br();

			$defaults    = $this->cli->confirm( 'Use defaults above?' );
			$use_default = $defaults->confirmed();
			if ( true === $use_default ) {
				foreach ( $cmd_data as $key => $value ) {
					$this->data[ $key ] = $value;
				}
			}
		}

		if ( false === $use_default ) {
			$label                   = $this->cli->input( sprintf( '%s Name:', $label_type ) );
			$this->data['label']     = $label->prompt();
			$slug                    = $this->cli->input( sprintf( '%s Slug:', $label_type ) );
			$this->data['slug']      = $slug->prompt();
			$namespace               = $this->cli->input( sprintf( '%s Namespace:', $label_type ) );
			$this->data['namespace'] = $namespace->prompt();
		}

		$this->slug = $this->data['slug'];

		$desc               = $this->cli->input( sprintf( '%s Description:', $label_type ) );
		$this->data['desc'] = $desc->prompt();

		$this->cli->bold( 'If multiple, separate Author Names and Emails with commas' );
		$author_name                = $this->cli->input( sprintf( '%s Author Name(s):', $label_type ) );
		$this->data['author_name']  = $author_name->prompt();
		$author_email               = $this->cli->input( sprintf( '%s Author Email(s):', $label_type ) );
		$this->data['author_email'] = $author_email->prompt();

		if ( $this->handle_license ) {
			$this->data['copyright_year'] = date( 'Y' );
			$this->license_handler();
		}

		if ( $this->common_fs->has( 'templates/README.md.mustache' ) ) {
			$this->files['README.md'] = $this->render( $this->common_fs->read( '/templates/README.md.mustache' ), $this->data );
		}
	}

	/**
	 * Handle License Selection
	 *
	 * @return void
	 */
	protected function license_handler() {
		$license_type = $this->cli->input( 'License Type:' );
		$selected     = $license_type->accept( array_keys( $this->licenses ), true )->defaultTo( 'gplv3' )->prompt();

		if ( 'gpl2' === $selected || 'gpl3' === $selected ) {
			unset( $this->licenses['gpl2'] );
			unset( $this->licenses['gpl3'] );
			$this->licenses = $this->licenses + $this->licenses;
			$all_licenses   = $this->cli->input( sprintf( 'Use %s only or %s and later versions?', $selected, $selected ) );
			$selected       = $all_licenses->accept( [ 'only', 'later' ], true )->prompt();
		}

		if ( 'other' !== $selected ) {
			$this->data['license_spdx']  = $this->licenses[ $selected ]['spdx'];
			$this->data['license_label'] = $this->licenses[ $selected ]['label'];

			if ( $this->common_fs->has( "licenses/{$selected}.mustache" ) ) {
				$license_template       = $this->common_fs->read( "licenses/{$selected}.mustache" );
				$this->files['LICENSE'] = $this->render( $license_template, $this->data );
			}

			if ( $this->common_fs->has( "licenses/{$selected}-readme.mustache" ) ) {
				$readme_template                     = $this->common_fs->read( "licenses/{$selected}-readme.mustache" );
				$this->data['license_readme_insert'] = $this->render( $readme_template, $this->data );
			}
		}
	}

	/**
	 * OPTIONAL: Function for handling $this->type selection.
	 *
	 * @param array       $assoc_args - Flags & Associative Arguments from WP-CLI
	 * @param null|string $passed_arg - Passed Positional String Argument from Main Callback.
	 *
	 * @return void
	 */
	protected function type_prompts( $assoc_args, $passed_arg = null ) {
		if ( empty( $this->type )
			&& ( ! empty( Utils\get_flag_value( $assoc_args, 'type' ) ) || ! empty( $passed_arg ) )
		) {
			$user_input_type = ! empty( $passed_arg ) ? $passed_arg : Utils\get_flag_value( $assoc_args, 'type', null );
			if ( in_array( $user_input_type, $this->types, true ) ) {
				$this->type = $user_input_type;
			} else {
				$this->cli->bold( sprintf( '"%s" isn\'t a valid type.' ) )->br()->out( '' );
			}
		}
	}

	/**
	 * Initialize Filesystem to read /licenses and /templates in this folder.
	 *
	 * @return void
	 */
	protected function common_init() {
		if ( null === $this->common_fs ) {
			$this->common_fs = new Filesystem( new Local( dirname( __FILE__ ) ) );
		}
	}
}
