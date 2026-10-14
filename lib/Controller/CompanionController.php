<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller;

use OCA\ContractCheck\Service\CompanionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\ContractCheck\Service\ContractValidator;

/**
 * Companion (mobile) API — same session/app-password auth as the web API
 * (Bearer app passwords authenticate the session upstream). CSRF is not
 * required for GET routes; the API is read-only for v1 so nothing else
 * needs exemption.
 */
class CompanionController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly CompanionService $companion,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): ?string
	{
		return $this->userSession->getUser()?->getUID();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function bootstrap(): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return $this->fail('UNAUTHORIZED', 'Login required', [], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return $this->ok($this->companion->bootstrap($uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return $this->fail('UNAUTHORIZED', 'Login required', [], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return $this->ok($this->companion->dashboard($uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listContracts(?string $search = null, ?string $status = null): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return $this->fail('UNAUTHORIZED', 'Login required', [], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$filters = ContractValidator::filters($this->request->getParams());
			return $this->ok($this->companion->listContracts($uid, $search, $filters['status'], $filters));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getContract(int $id): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return $this->fail('UNAUTHORIZED', 'Login required', [], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$row = $this->companion->getContract($uid, $id);
			if ($row === null) {
				return $this->notFound();
			}

			return $this->ok($row);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listParties(): JSONResponse
	{
		$uid = $this->uid();
		if ($uid === null) {
			return $this->fail('UNAUTHORIZED', 'Login required', [], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return $this->ok($this->companion->listParties($uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}
}
