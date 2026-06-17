<?php

namespace n5s\WpCliMove\Tests;

use n5s\WpCliMove\Model\Alias;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SSH transport / rsync command building in Alias.
 */
final class AliasTest extends TestCase {

	/**
	 * Build a remote alias without touching the filesystem or WP-CLI.
	 *
	 * @param integer|null $port
	 * @param string|null  $key
	 * @param string|null  $user
	 * @param string       $host
	 * @return Alias
	 */
	private function remote_alias( ?int $port = null, ?string $key = null, ?string $user = 'deploy', string $host = 'example.com' ): Alias {
		return new Alias(
			name: '@prod',
			path: '/var/www/html',
			ssh_bits: [
				'scheme' => 'ssh',
				'host'   => $host,
			],
			user: $user,
			host: $host,
			port: $port,
			key: $key,
		);
	}

	public function test_rsync_rsh_minimal_is_ssh_without_a_tty(): void {
		$this->assertSame( 'ssh -T', $this->remote_alias()->get_rsync_rsh() );
	}

	public function test_rsync_rsh_carries_port_and_key(): void {
		$this->assertSame(
			"ssh -T -p 2222 -i '/home/me/id_rsa'",
			$this->remote_alias( 2222, '/home/me/id_rsa' )->get_rsync_rsh()
		);
	}

	public function test_rsync_rsh_quotes_a_key_path_containing_spaces(): void {
		$this->assertSame(
			"ssh -T -i '/home/My Key/id_rsa'",
			$this->remote_alias( null, '/home/My Key/id_rsa' )->get_rsync_rsh()
		);
	}

	public function test_rsync_rsh_never_requests_a_tty(): void {
		$rsh = $this->remote_alias( 22, '/home/me/id_rsa' )->get_rsync_rsh();
		$this->assertStringContainsString( '-T', $rsh );
		$this->assertStringNotContainsString( ' -t ', " {$rsh} " );
	}

	public function test_rsync_location_includes_user_host_and_path(): void {
		$this->assertSame(
			'deploy@example.com:/var/www/html/wp-content/uploads/',
			$this->remote_alias()->get_rsync_location( '/var/www/html/wp-content/uploads/' )
		);
	}

	public function test_rsync_location_omits_an_empty_user(): void {
		$this->assertSame(
			'example.com:/srv/uploads/',
			$this->remote_alias( null, null, null )->get_rsync_location( '/srv/uploads/' )
		);
	}

	public function test_rsync_location_never_emits_an_empty_remote_host(): void {
		// Regression: the old ':path' form (empty host, host baked into --rsh) is
		// rejected by openrsync with "empty remote host". The location must always
		// carry an explicit host.
		$this->assertStringStartsWith(
			'example.com:',
			$this->remote_alias( null, null, null )->get_rsync_location( '/srv/uploads/' )
		);
	}

	public function test_force_no_tty_replaces_the_interactive_flag(): void {
		$this->assertSame(
			"ssh -T -q 'deploy@example.com' 'wp option get home'",
			Alias::force_no_tty( "ssh -t -q 'deploy@example.com' 'wp option get home'" )
		);
	}

	public function test_force_no_tty_keeps_connection_flags(): void {
		$this->assertSame(
			"ssh -p 22 -i '/home/me/key' -T -q 'host' 'rm -f dump.sql'",
			Alias::force_no_tty( "ssh -p 22 -i '/home/me/key' -t -q 'host' 'rm -f dump.sql'" )
		);
	}

	public function test_force_no_tty_leaves_a_non_tty_command_untouched(): void {
		$this->assertSame(
			"ssh -T -q 'host' 'cmd'",
			Alias::force_no_tty( "ssh -T -q 'host' 'cmd'" )
		);
	}

	public function test_force_no_tty_only_flips_the_flag_not_quoted_arguments(): void {
		$this->assertSame(
			"ssh -T -q 'host' 'echo -t keep'",
			Alias::force_no_tty( "ssh -t -q 'host' 'echo -t keep'" )
		);
	}

	public function test_force_no_tty_ignores_non_ssh_transports(): void {
		$docker = "docker exec -t  'container' sh -c 'cmd'";
		$this->assertSame( $docker, Alias::force_no_tty( $docker ) );
	}
}
