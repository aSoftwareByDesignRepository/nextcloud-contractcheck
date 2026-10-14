<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Middleware;

use OCA\ContractCheck\Exception\AppAccessDeniedException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Support\AssetRegistrar;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The single access door for every app route.
 *
 *  - API routes      → canonical JSON 403
 *  - Page routes     → friendly access-denied page (HTTP 403)
 *  - Feed            → token auth handled in-controller; the door
 *                      still applies to the resolved user
 *
 * Runs BEFORE the controller so no business code ever executes for a
 * locked-out user.
 */
class AccessMiddleware extends Middleware
{
	public function __construct(
		private readonly AccessService $access,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void
	{
		if ($controller instanceof \OCA\ContractCheck\Controller\FeedController) {
			return; // token auth, no session
		}
		$user = $this->userSession->getUser();
		$uid = $user === null ? null : $user->getUID();
		if ($uid === null || !$this->access->hasAppAccess($uid)) {
			throw new AppAccessDeniedException($this->l10n->t('ContractCheck is not enabled for this account'));
		}
	}

	public function afterException(Controller $controller, string $methodName, \Exception $exception): JSONResponse|TemplateResponse
	{
		if ($exception instanceof AppAccessDeniedException) {
			if ($this->wantsJson()) {
				return new JSONResponse([
					'success' => false,
					'error' => 'FORBIDDEN',
					'message' => $this->l10n->t('Access denied.'),
					'fields' => new \stdClass(),
				], Http::STATUS_FORBIDDEN);
			}

			// Rendered before the controller — register the shell styles or
			// the denied page renders unstyled.
			AssetRegistrar::registerStyles();

			return new TemplateResponse('contractcheck', 'access-denied', [
				'homeUrl' => $this->urlGenerator->linkToRouteAbsolute('contractcheck.page.index'),
			], 'user', Http::STATUS_FORBIDDEN);
		}
		throw $exception;
	}

	private function wantsJson(): bool
	{
		$path = (string)$this->request->getRequestUri();

		return str_contains($path, '/api/');
	}
}
