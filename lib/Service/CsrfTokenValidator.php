<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;

/**
 * Thin wrapper around the core CSRF token manager so middleware can validate a
 * raw token string without the `OCS-APIRequest` short-circuit baked into
 * IRequest::passesCSRFCheck(). Keeps the private-core dependency in one place.
 */
class CsrfTokenValidator
{
	public function __construct(
		private CsrfTokenManager $csrfTokenManager,
	) {
	}

	public function isValid(string $token): bool
	{
		return $this->csrfTokenManager->isTokenValid(new CsrfToken($token));
	}
}
