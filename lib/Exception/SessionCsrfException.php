<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Exception;

/**
 * HTTP 412 — session-authenticated mutation without a valid requesttoken.
 * Serialized by SessionCsrfMiddleware::afterException.
 */
class SessionCsrfException extends \RuntimeException
{
	public function __construct()
	{
		parent::__construct('CSRF_FAILED');
	}
}
