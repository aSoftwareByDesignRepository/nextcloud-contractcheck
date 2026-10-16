<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller;

use OCA\ContractCheck\Service\IcsFeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * ICS deadline feed — secret-token auth, read-only, no session.
 * Invalid tokens get a bare 404 (no hint whether the feed exists).
 */
class FeedController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IcsFeedService $feed,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ics(?string $token = null): Response
	{
		$result = $token === null ? null : $this->feed->feedForToken($token);
		if ($result === null) {
			return new TextPlainResponse('Not found.', Http::STATUS_NOT_FOUND);
		}
		// TextPlainResponse hard-overrides Content-Type to text/plain —
		// DataDisplayResponse honours the calendar subtype clients expect.
		$response = new DataDisplayResponse($result['ics'], Http::STATUS_OK, ['Content-Type' => 'text/calendar; charset=utf-8']);
		$response->addHeader('Content-Disposition', 'inline; filename="contractcheck-deadlines.ics"');
		// Calendar clients re-fetch — allow short caching, never intermediaries.
		$response->cacheFor(900, false, false);

		return $response;
	}
}
