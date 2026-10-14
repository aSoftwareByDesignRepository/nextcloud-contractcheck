<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * Optimistic-concurrency conflict — the caller must reload and retry
 * (fail closed; never merge silently).
 */
class ConflictException extends \RuntimeException
{
	public function __construct(string $message = 'The record changed in the meantime. Reload and try again.')
	{
		parent::__construct($message);
	}
}
