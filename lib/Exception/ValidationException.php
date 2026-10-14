<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * Server-side validation failure carrying the error code and the
 * field-keyed messages rendered next to the offending form controls
 * (family field-errors contract — a toast alone is a reject).
 */
class ValidationException extends \RuntimeException
{
	/** @var array<string, string> */
	private array $fields;

	private string $errorCode;

	/**
	 * @param array<string, string> $fields
	 */
	public function __construct(
		string $errorCode = 'VALIDATION',
		string $message = '',
		array $fields = [],
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
		$this->errorCode = $errorCode;
		$this->fields = $fields;
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}

	/** @return array<string, string> */
	public function getFields(): array
	{
		return $this->fields;
	}

	/**
	 * Convenience constructor for the common "one bad field" case.
	 */
	public static function field(string $errorCode, string $field, string $message): self
	{
		return new self($errorCode, $message, [$field => $message]);
	}
}
