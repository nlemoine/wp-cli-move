<?php

namespace n5s\WpCliMove\Model;

use InvalidArgumentException;
use n5s\WpCliMove\Exception\ProcessFailedException;
use n5s\WpCliMove\MoveCommand;
use ReflectionClass;
use Stringable;
use WP_CLI;
use WP_CLI\ProcessRun;
use WP_CLI\Runner;
use WP_CLI\Utils;

/**
 * @phpstan-type ssh_bits array{
 *      scheme?: string,
 *      user?: string,
 *      host?: string,
 *      port?: string,
 *      path?: string,
 * }
 * /**
 * @phpstan-import-type alias_config from MoveCommand
 */
final class Alias implements Stringable {

	public const ALL_ALIAS   = '@all';
	public const LOCAL_ALIAS = '@local';

	private const FILE_TMP_DUMP    = 'dump';
	private const COMMAND_TYPE_WP  = 'wp';
	private const COMMAND_TYPE_RAW = 'raw';

	private readonly string $slug;
	private readonly bool $is_local;

	/**
	 * @param string $name
	 * @param string $path
	 * @param string|null $url
	 * @param ssh_bits|null $ssh_bits
	 * @param string|null $user
	 * @param string|null $host
	 * @param integer|null $port
	 * @param string|null $key
	 */
	public function __construct(
		private readonly string $name,
		private string $path,
		private ?string $url = null,
		private readonly ?array $ssh_bits = null,
		private readonly ?string $user = null,
		private readonly ?string $host = null,
		private readonly ?int $port = null,
		private readonly ?string $key = null
	) {
		$this->slug     = ltrim( $this->name, '@' );
		$this->path     = rtrim( $this->path, '/' );
		$this->is_local = file_exists( $this->path ) && null === $this->ssh_bits;
	}

	public function __toString(): string {
		return $this->name;
	}

	public function is_local(): bool {
		return $this->is_local;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_name_colorized( string $prefix = '', string $suffix = '' ): string {
		return WP_CLI::colorize( sprintf( '%s%s%s%s%%N', $this->is_local() ? '%G' : '%R', $prefix, $this->name, $suffix ) );
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_path(): string {
		return $this->path;
	}

	/**
	 * @return array{
	 *      scheme?: string,
	 *      user?: string,
	 *      host?: string,
	 *      port?: string,
	 *      path?: string,
	 *      key?: string,
	 *      proxyjump?: string
	 * }|null
	 */
	public function get_ssh_bits(): ?array {
		return $this->ssh_bits;
	}

	public function get_user(): ?string {
		return $this->user;
	}

	public function get_host(): ?string {
		return $this->host;
	}

	public function get_port(): ?int {
		return $this->port;
	}

	public function get_key(): ?string {
		return $this->key;
	}

	public function get_ssh_url(): ?string {
		if ( null === $this->user && null === $this->host ) {
			return null;
		}

		$ssh_url  = $this->ssh_bits['scheme'] ?? 'ssh';
		$ssh_url .= '://';
		$ssh_url .= $this->user ?? '';
		$ssh_url .= $this->user ? '@' : '';
		$ssh_url .= $this->host ?? '';
		$ssh_url .= $this->port ? ':' . $this->port : '';
		$ssh_url .= $this->path ? '/' . ltrim( $this->path, '/' ) : '';

		return $ssh_url;
	}

	/**
	 * Get the home URL
	 */
	public function get_url(): string {
		if ( ! isset( $this->url ) ) {
			$result = $this->run_wp( command: 'config get WP_HOME', quiet: true );
			if ( ! $result->is_successful() ) {
				$result = $this->run_wp( command: 'option get home', quiet: true );
			}
			$this->url = $result->stdout;
		}

		return $this->url;
	}

	/**
	 * Get the upload path
	 *
	 * @return string
	 */
	public function get_upload_path(): string {
		$result = $this->run_wp( command: 'eval "echo wp_get_upload_dir()[\'basedir\'] ?? \'\';"', quiet: true );
		return $result->stdout;
	}

	/**
	 * Get random hash
	 *
	 * @return string
	 */
	protected function get_random_hash(): string {
		return substr( hash( 'xxh3', random_bytes( 256 ) ), 0, 7 );
	}

	/**
	 * Get the path to a file for the alias
	 *
	 * @param string $file
	 * @return string
	 */
	public function get_file_path( string $file ): string {
		return $this->path . '/' . $file;
	}

	/**
	 * Get default dump file namen eventually randomize it for "anonymous" dumps
	 *
	 * @param boolean $compress
	 * @param boolean $randomize
	 * @return string
	 */
	public function get_filename_tmp( $compress = true, bool $randomize = false ): string {
		return sprintf(
			'%s%s.sql%s',
			self::FILE_TMP_DUMP,
			$randomize ? '-' . $this->get_random_hash() : '',
			$compress ? '.gz' : ''
		);
	}

	/**
	 * Generate a filename for backups
	 *
	 * @return string
	 */
	public function get_filename_backup( bool $compress = true ): string {
		return sprintf(
			'%s_%s.sql%s',
			$this->get_filename_backup_prefix(),
			gmdate( 'Y-m-d_H-i-s' ),
			$compress ? '.gz' : ''
		);
	}

	/**
	 * Get the prefix for backup files
	 *
	 * @return string
	 */
	public function get_filename_backup_prefix(): string {
		return sprintf(
			'%s-backup',
			$this->slug
		);
	}

	/**
	 * Get menu label
	 *
	 * @return string
	 */
	public function get_menu_label(): string {
		$detail = $this->is_local() ? $this->get_path() : $this->get_ssh_url();
		return WP_CLI::colorize( "{$this->get_name_colorized()} - %y({$detail})%n" );
	}

	/**
	 * Import a database dump
	 *
	 * @param string $file
	 * @param boolean $dry_run
	 * @return void
	 */
	public function import_db( string $file, bool $dry_run = false ): void {
		$command = Utils\esc_cmd( 'db import %s', $this->get_file_path( $file ) );
		$this->run_wp( $command, $dry_run );
	}

	/**
	 * Export a database dump
	 *
	 * @param Alias $to
	 * @param string $file
	 * @param boolean $compress
	 * @param boolean $dry_run
	 * @return ProcessResult
	 */
	public function export_db( Alias $to, string $file, bool $compress = true, bool $dry_run = false ): ProcessResult {
		$command = 'db export %s -';
		if ( $compress ) {
			$command .= ' | gzip -9';
		}

		if ( ! $to->is_local() ) {
			$command .= ' | ' . $to->generate_ssh_command(
				sprintf(
					'%s%s > %s',
					$compress ? 'gzip' : 'cat',
					$compress ? ' -d' : '',
					escapeshellarg( $to->get_file_path( $file ) )
				)
			);
		} else {
			$command .= Utils\esc_cmd( ' > %s', $to->get_file_path( $file ) );
		}

		return $this->run_wp( sprintf( $command, trim( Utils\assoc_args_to_str( MoveCommand::DEFAULT_MYSQLDUMP_ASSOC_ARGS ) ) ), $dry_run );
	}

	/**
	 * Remove a file
	 *
	 * @param string $file Relative path to file
	 * @param boolean $dry_run
	 * @return void
	 */
	public function remove_file( string $file, bool $dry_run = false ): void {
		$this->run_command( 'rm', escapeshellarg( $this->get_file_path( $file ) ), $dry_run );
	}

	/**
	 * Generate SSH command
	 *
	 * @param string $command
	 * @return string
	 */
	public function generate_ssh_command( string $command = '' ): string {
		if ( $this->is_local() ) {
			throw new \RuntimeException( 'Cannot generate SSH command for local alias' );
		}

		/** @var Runner $runner */
		$runner       = WP_CLI::get_runner();
		$ref          = new ReflectionClass( $runner );
		$generate_ssh = $ref->getMethod( 'generate_ssh_command' );
		$generate_ssh->setAccessible( true );

		$placeholder = '' === $command ? $this->get_random_hash() : '';

		// Get SSH bits without path
		// Because https://github.com/wp-cli/wp-cli/blob/3ca317ad8b50ebaf9580373a383490a82b768f4a/php/WP_CLI/Runner.php#L654-L657
		// introduced in 2.12 https://github.com/wp-cli/wp-cli/commit/82772d90cfe12af1002df1312239cd61ebbb7dab
		// breaks rsh
		$ssh_bits = $this->get_ssh_bits();
		unset( $ssh_bits['path'] );

		/** @var string $ssh_command */
		$ssh_command = $generate_ssh->invoke( $runner, $ssh_bits, '' !== $placeholder ? $placeholder : $command );

		if ( $placeholder ) {
			$ssh_command = trim( str_replace( escapeshellarg( $placeholder ), '', $ssh_command ) );
		}

		return $ssh_command;
	}

	/**
	 * Run a WP-CLI command
	 *
	 * @param string $command
	 * @param boolean $dry_run
	 * @return ProcessResult
	 */
	public function run_wp( string $command, bool $dry_run = false, bool $quiet = false ): ProcessResult {
		$command = trim( $command );
		if ( ! $this->is_local() ) {
			$command = "{$this} {$command}";
		}

		return $this->dispatch_command( $command, self::COMMAND_TYPE_WP, $dry_run, $quiet );
	}

	/**
	 * Run an arbitrary command
	 *
	 * @param string $binary
	 * @param string $command
	 * @param boolean $dry_run
	 * @return ProcessResult
	 */
	public function run_command( string $binary, string $command, bool $dry_run = false ): ProcessResult {
		$command = $binary . ' ' . trim( $command );

		if ( ! $this->is_local() ) {
			$command = $this->generate_ssh_command( $command );
		}

		return $this->dispatch_command( $command, self::COMMAND_TYPE_RAW, $dry_run );
	}

	/**
	 * Dispatch command
	 *
	 * @param string $command
	 * @param string $type
	 * @return ProcessResult
	 * @throws ProcessFailedException
	 */
	private function dispatch_command( string $command, string $type = self::COMMAND_TYPE_WP, bool $dry_run = false, bool $quiet = false ): ProcessResult {
		$result = (object) [
			'command'     => $command,
			'stdout'      => '',
			'stderr'      => '',
			'return_code' => 0,
		];

		if ( ! $dry_run ) {
			switch ( $type ) {
				case self::COMMAND_TYPE_WP:
					/** @var object{'command': string, 'stdout': string, 'stderr': string, 'return_code': int} $result */
					$result = WP_CLI::runcommand(
						$command,
						[
							'return'     => 'all',
							'exit_error' => false,
						]
					);

					// @phpstan-ignore assign.propertyReadOnly
					$result->command = $command;
					break;
				case self::COMMAND_TYPE_RAW:
					/** @var ProcessRun $result */
					$result = WP_CLI::launch(
						command: $command,
						exit_on_error: false,
						return_detailed: true
					);
					break;
				default:
					throw new InvalidArgumentException( 'Invalid command type' );
			}
		}

		$process_result = new ProcessResult(
			command: sprintf( '%s%s', self::COMMAND_TYPE_WP === $type ? 'wp ' : '', $result->command ),
			exit_code: $result->return_code,
			stdout: $result->stdout,
			stderr: $result->stderr,
		);

		if ( ! $quiet ) {
			$where   = $this->get_name_colorized( '[', ']' );
			$message = WP_CLI::colorize( "{$process_result->command}" );
			WP_CLI::log( "{$where} {$message}\n" );
		}

		if ( ! $process_result->is_successful() ) {
			throw new ProcessFailedException( $process_result );
		}

		return $process_result;
	}

	/**
	 * Create an alias
	 *
	 * @param alias_config $config
	 */
	public static function create( string $name, array $config = [] ): self {
		$ssh = $config['ssh'] ?? null;
		if ( null !== $ssh ) {
			/** @var ssh_bits $ssh_bits */
			$ssh_bits = Utils\parse_ssh_url( $ssh );
		}

		$path = $ssh_bits['path'] ?? $config['path'] ?? null;

		if ( ! $path ) {
			throw new InvalidArgumentException( 'A path is required' );
		}

		return new self(
			name: $name,
			path: $path,
			url: $config['url'] ?? null,
			ssh_bits: $ssh_bits ?? null,
			user: $ssh_bits['user'] ?? null,
			host: $ssh_bits['host'] ?? null,
			port: isset( $ssh_bits['port'] ) ? (int) $ssh_bits['port'] : null,
			key: $config['key'] ?? null,
		);
	}
}
