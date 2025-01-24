<?php

namespace n5s\WpCliMove;

use InvalidArgumentException;
use n5s\WpCliMove\Exception\ProcessFailedException;
use n5s\WpCliMove\Model\Alias;
use WP_CLI;
use WP_CLI\Configurator;
use WP_CLI\Runner;
use WP_CLI\Utils;
use function cli\menu;

/**
 * @phpstan-type alias_config array{
 *     'user'?: string,
 *     'url'?: string,
 *     'path'?: string,
 *     'ssh'?: string,
 *     'http'?: string,
 *     'proxyjump'?: string,
 *     'key'?: string,
 * }|string[]
 */
class MoveCommand {

	private const DATA_TYPE_DB      = 'db';
	private const DATA_TYPE_UPLOADS = 'uploads';
	private const DATA_TYPES        = [
		self::DATA_TYPE_DB,
		self::DATA_TYPE_UPLOADS,
	];

	public const DEFAULT_MYSQLDUMP_ASSOC_ARGS = [
		'add-drop-table'     => true,
		'all-tablespaces'    => true,
		'single-transaction' => true,
		'quick'              => true,
		'lock-tables'        => 'false',
	];

	public const DEFAULT_RSYNC_ARGS = [
		'progress'       => true,
		'recursive'      => true,
		'links'          => true,
		'perms'          => true,
		'times'          => true,
		'omit-dir-times' => true,
		'compress'       => true,
		'delete'         => true,
	];

	/**
	 * Pull content from remote alias
	 *
	 * ## OPTIONS
	 *
	 * [<alias>]
	 * : The alias you want to pull from.
	 *
	 * [--db]
	 * : Pull only the database.
	 *
	 * [--uploads]
	 * : Pull only the uploads folder.
	 *
	 * [--disable-compress]
	 * : Disable database dump compression.
	 *
	 * [--dry-run]
	 * : Print the command sequence without making any changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp move pull staging
	 *
	 * @when before_wp_load
	 *
	 * @param array<string> $args
	 * @param array<string, bool> $assoc_args
	 */
	public function pull( array $args, array $assoc_args ): void {
		$this->sync( __FUNCTION__, $args[0] ?? null, $assoc_args );
	}

	/**
	 * Push content to remote alias
	 *
	 * ## OPTIONS
	 *
	 * [<alias>]
	 * : The alias you want to push to.
	 *
	 * [--db]
	 * : Push only the database.
	 *
	 * [--uploads]
	 * : Push only the uploads folder.
	 *
	 * [--disable-compress]
	 * : Disable database dump compression.
	 *
	 * [--dry-run]
	 * : Print the command sequence without making any changes.
	 *
	 * [--v]
	 * : Print the commands being run.
	 *
	 * [--vv]
	 * : Print the commands being run and the output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp move push staging
	 *
	 * @when before_wp_load
	 *
	 * @param array<string> $args
	 * @param array<string, bool> $assoc_args
	 */
	public function push( array $args, array $assoc_args ): void {
		$this->sync( __FUNCTION__, $args[0] ?? null, $assoc_args );
	}

	/**
	 * Sync content between two aliases
	 *
	 * @param string $direction
	 * @param string|null $alias
	 * @param array<string, bool> $assoc_args
	 * @return void
	 */
	private function sync( string $direction, ?string $alias, array $assoc_args ): void {
		$disable_compress = (bool) Utils\get_flag_value( $assoc_args, 'disable-compress', false );
		$dry_run          = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$from = 'push' === $direction ? $this->get_local_alias() : $this->get_alias( $alias );
		$to   = 'push' === $direction ? $this->get_alias( $alias ) : $this->get_local_alias();

		$data_types = $this->get_data_types( $assoc_args );

		if ( $data_types['uploads'] ) {
			$this->sync_uploads( $from, $to, $dry_run );
		}

		if ( $data_types['db'] ) {
			$this->{"{$direction}_db"}( $from, $to, ! $disable_compress, $dry_run );
		}
	}

	/**
	 * Pulls the database from a remote alias
	 *
	 * @param Alias $from
	 * @param Alias $to
	 * @param bool $compress
	 * @param bool $dry_run
	 */
	private function pull_db( Alias $from, Alias $to, bool $compress = true, bool $dry_run = false ): void {
		$this->log_section( "⬇️ Pulling database from {$from}" );

		// Backup local DB
		$to->export_db( $to, $to->get_filename_backup( $compress ), $compress, $dry_run );

		// Export remote DB to local
		// TODO: compress for transfer
		$db_tmp_file = $to->get_filename_tmp( false );
		$from->export_db( $to, $db_tmp_file, false, $dry_run );

		// Import remote database
		$to->import_db( $db_tmp_file, $dry_run );

		// Replace URLs
		$this->replace_urls( $from, $to, $dry_run );

		// Delete tmp file
		$to->remove_file( $db_tmp_file, $dry_run );
	}

	/**
	 * Push the database to a remote alias
	 *
	 * @param Alias $from
	 * @param Alias $to
	 * @param bool $compress
	 * @param bool $dry_run
	 */
	private function push_db( Alias $from, Alias $to, bool $compress = true, bool $dry_run = false ): void {
		$this->log_section( "⬆️ Pushing database to {$to}" );

		// Backup remote DB
		$to->export_db( $from, $to->get_filename_backup( $compress ), $compress, $dry_run );

		// Export local DB (reimported later, store filename)
		$from_dump_tmp = $to->get_filename_tmp( false );
		$from->export_db( $from, $from_dump_tmp, false, $dry_run );

		// Search replace URLs
		$this->replace_urls( from: $from, to: $to );

		// Export local DB with replaced URLs to remote
		$to_dump_tmp = $to->get_filename_tmp( false, true );
		try {
			$from->export_db( $to, $to_dump_tmp, $compress, $dry_run );
		} catch ( ProcessFailedException $e ) {
			if ( ! $dry_run ) {
				// Delete tmp remote file in case of failure
				$to->remove_file( $to_dump_tmp, $dry_run );
			}

			WP_CLI::error( $e->getMessage() );
		}

		// Import remote DB
		$to->import_db( $to_dump_tmp, $dry_run );

		// Delete tmp remote file
		$to->remove_file( $to_dump_tmp, $dry_run );

		// Restore local DB
		$from->import_db( $from_dump_tmp, $dry_run );

		// Delete tmp local file
		$from->remove_file( $from_dump_tmp, $dry_run );
	}

	/**
	 * Search and replace URLs
	 *
	 * @param Alias $from
	 * @param Alias $to
	 * @param bool $dry_run
	 * @return void
	 */
	private function replace_urls( Alias $from, Alias $to, bool $dry_run = false ): void {
		$where = $from;
		if ( ! $where->is_local() ) {
			$where = $to;
		}

		// Replace Path/URLs
		try {
			$from_url = $from->get_url();
			$to_url   = $to->get_url();
		} catch ( ProcessFailedException $e ) {
			echo $e->getProcessResult();
			exit;
		}

		if ( ! $from_url || ! $to_url ) {
			WP_CLI::error( 'Could not get home URLs' );
		}

		if ( $from_url === $to_url ) {
			WP_CLI::warning( 'Home URLs are the same, skipping search-replace' );
			return;
		}

		$this->replace( $from, $to, $from_url, $to_url, $dry_run );
	}

	/**
	 * Run replace command
	 *
	 * @param Alias $from
	 * @param Alias $to
	 * @param string $from_string
	 * @param string $to_string
	 * @param bool $dry_run
	 * @return void
	 */
	private function replace( Alias $from, Alias $to, string $from_string, string $to_string, $dry_run = false ): void {
		$where = $from;
		if ( ! $where->is_local() ) {
			$where = $to;
		}

		$replace_command = Utils\esc_cmd( 'search-replace %s %s', $from_string, $to_string );

		$where->run_wp( $replace_command, $dry_run );
	}

	/**
	 * Rsync uploads between two aliases
	 *
	 * @param Alias $from
	 * @param Alias $to
	 * @return void
	 */
	private function sync_uploads( Alias $from, Alias $to, bool $dry_run = false ): void {
		$remote = $from->is_local() ? $to : $from;
		$local  = $from->is_local() ? $from : $to;

		// Ensure upload directories exist
		$from_path = $from->get_upload_path();
		$to_path   = $to->get_upload_path();

		if ( ! $from_path || ! $to_path ) {
			WP_CLI::error( 'Could not determine upload paths' );
		}

		$this->log_section( sprintf( '%s %s', $to->is_local() ? '⬇️ Pulling uploads from' : '⬆️ Pushing uploads to', $remote ) );

		$rsync_args        = self::DEFAULT_RSYNC_ARGS;
		$rsync_args['rsh'] = $remote->generate_ssh_command( '' );

		// Build rsync command
		$rsync_args = Utils\assoc_args_to_str( $rsync_args );

		$source = $from->is_local() ? "{$from_path}/" : ":{$from_path}/";
		$target = $to->is_local() ? "{$to_path}/" : ":{$to_path}/";

		$command = Utils\esc_cmd( "{$rsync_args} %s %s", $source, $target );

		$local->run_command( 'rsync', $command, $dry_run );
	}

	/**
	 * Get data types to sync
	 *
	 * @param array<string, bool> $args
	 * @return array<value-of<self::DATA_TYPES>, bool>
	 */
	private function get_data_types( array $args ): array {
		$data_types = array_combine( self::DATA_TYPES, array_map( fn( string $type ): bool => (bool) Utils\get_flag_value( $args, $type, false ), self::DATA_TYPES ) );

		return count( array_filter( $data_types ) ) === 0 ? array_fill_keys( self::DATA_TYPES, true ) : $data_types;
	}

	/**
	 * Get the alias
	 *
	 * @param string|null $alias_name
	 * @param bool $add_local
	 * @return Alias
	 */
	private function get_alias( ?string $alias_name = null, bool $add_local = false ): Alias {
		$aliases = $this->get_aliases( $add_local );

		if ( null === $alias_name ) {
			if ( count( $aliases ) === 0 ) {
				WP_CLI::error( 'No aliases found' );
			}

			$alias_name = menu(
				items: array_map( fn( Alias $a ): string => $a->get_menu_label(), $aliases ),
				title: 'Select an alias',
			);
		}

		if ( ! $alias_name ) {
			WP_CLI::error( 'Please provide an alias' );
		}

		$alias_name = str_starts_with( (string) $alias_name, '@' ) ? $alias_name : '@' . $alias_name;

		$alias_config = $this->get_alias_config( $alias_name );
		if ( $alias_config ) {
			try {
				$this->validate_alias( $alias_name, $alias_config, true );
			} catch ( InvalidArgumentException $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		}

		if ( ! array_key_exists( $alias_name, $aliases ) ) {
			WP_CLI::error( "Alias {$alias_name} not found" );
		}

		return $aliases[ $alias_name ];
	}

	/**
	 * Validate alias
	 *
	 * @param string $name
	 * @param alias_config|string $config
	 * @param bool $throw_error
	 * @return bool
	 */
	private function validate_alias( string $name, array|string $config, bool $throw_error = false ): bool {
		if ( Alias::ALL_ALIAS === $name ) {
			$throw_error && throw new InvalidArgumentException( sprintf( 'Alias %s is not supported', Alias::ALL_ALIAS ) );
			return false;
		}

		if ( is_array( $config ) ) {
			foreach ( $config as $maybe_alias ) {
				if ( preg_match( '#' . Configurator::ALIAS_REGEX . '#', $maybe_alias ) ) {
					$throw_error && throw new InvalidArgumentException( 'Group of aliases is not supported' );
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get available aliases
	 *
	 * @param bool $add_local
	 *
	 * @return array<string, Alias>
	 */
	private function get_aliases( bool $add_local = false ): array {
		$aliases = array_filter(
			$this->get_runner_aliases(),
			function ( array|string $config, string $name ): bool {
				if ( is_string( $config ) ) {
					return false;
				}

				return $this->validate_alias( $name, $config );
			},
			ARRAY_FILTER_USE_BOTH
		);

		$aliases_objects = [];
		if ( $add_local ) {
			$aliases_objects[ Alias::LOCAL_ALIAS ] = $this->get_local_alias();
		}

		foreach ( $aliases as $name => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			try {
				$aliases_objects[ $name ] = Alias::create( $name, $config );
			} catch ( InvalidArgumentException $e ) {
				WP_CLI::warning( $e->getMessage() );
			}
		}

		return $aliases_objects;
	}

	/**
	 * Get alias config
	 *
	 * @param string $name
	 * @return alias_config|string
	 */
	private function get_alias_config( string $name ): array|string {
		return $this->get_runner_aliases()[ $name ] ?? [];
	}

	/**
	 * Get local alias
	 *
	 * @return Alias
	 */
	private function get_local_alias(): Alias {
		return Alias::create(
			Alias::LOCAL_ALIAS,
			[
				'path' => $this->get_local_path(),
			]
		);
	}

	/**
	 * Get local path, prefer project config path to wp root
	 *
	 * @return string
	 */
	private function get_local_path(): string {
		$path = $this->get_runner()->get_project_config_path();
		return $path ? dirname( $path ) : $this->get_runner()->find_wp_root();
	}

	/**
	 * Display a section
	 *
	 * @param string $title
	 * @return void
	 */
	private function log_section( string $title ): void {
		$length       = 100;
		$title_length = mb_strlen( $title );

		$left  = str_repeat( '▬', 2 );
		$right = str_repeat( '▬', $length - $title_length - 4 );

		WP_CLI::log( WP_CLI::colorize( "\n{$left} %9{$title}%n {$right}\n" ) );
	}

	/**
	 * Get runner aliases
	 *
	 * @return array<string, alias_config|string>
	 */
	private function get_runner_aliases(): array {
		return $this->get_runner()->aliases;
	}

	private function get_runner(): Runner {
		return WP_CLI::get_runner();
	}
}
