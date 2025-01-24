<?php

namespace n5s\WpCliMove\Model;

use Stringable;
use WP_CLI;

final class ProcessResult implements Stringable {

	public function __construct(
		public readonly string $command,
		public readonly int $exit_code,
		public readonly string $stdout,
		public readonly string $stderr,
	) {
	}

	public function is_successful(): bool {
		return 0 === $this->exit_code;
	}

	public function __toString(): string {
		return "\n" . implode(
			"\n",
			array_filter(
				[
					$this->command,
					$this->stdout,
					$this->stderr,
				],
				fn( string $line ): bool => ! empty( trim( $line ) )
			)
		);
	}
}
