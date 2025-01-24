<?php

use n5s\WpCliMove\Model\Alias;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wp_cli_move_autoloader = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wp_cli_move_autoloader ) ) {
	require_once $wp_cli_move_autoloader;
}

WP_CLI::add_command(
	'move',
	n5s\WpCliMove\MoveCommand::class,
	[
		'before_invoke' => function (): void {
			$runner = WP_CLI::get_runner();

			if ( in_array( Alias::LOCAL_ALIAS, array_keys( $runner->aliases ), true ) ) {
				WP_CLI::error(
					sprintf( '"%s" is a reserved alias name by the `move` command, Please rename your alias.', Alias::LOCAL_ALIAS )
				);
			}

			if ( null !== $runner->alias ) {
				WP_CLI::error( 'You cannot use the `move` command with an alias.' );
			}
		},
	]
);
