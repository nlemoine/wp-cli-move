<?php

namespace n5s\WpCliMove\Exception;

use n5s\WpCliMove\Model\ProcessResult;
use RuntimeException;

class ProcessFailedException extends RuntimeException {

	public function __construct(
		private readonly ProcessResult $result
	) {
		parent::__construct(
			sprintf(
				'The command "%s" failed.' . "\n\nExit Code: %d\n\n(%s)",
				$result->command,
				$result->exit_code,
				$result->stderr
			),
			$result->exit_code
		);
	}

	public function getProcessResult(): ProcessResult {
		return $this->result;
	}
}
