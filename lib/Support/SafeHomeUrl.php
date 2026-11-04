<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Support;

use OCP\IURLGenerator;

/**
 * Resolve a safe "go home" URL for access-denied pages.
 *
 * The recovery CTA must never point at a route the same door denies — a
 * linkToRoute(app.index) homeUrl sends the locked-out user straight back
 * to the 403 they just hit. The platform default page (dashboard/files)
 * is always reachable for any authenticated account.
 *
 * NC35 (and some PHPUnit boots) can throw from linkToDefaultPageUrl() when
 * the default navigation entry lacks an href — that must never turn a 403
 * into a 500.
 */
final class SafeHomeUrl
{
	public static function resolve(IURLGenerator $urlGenerator, string $fallback = '/'): string
	{
		try {
			$url = $urlGenerator->linkToDefaultPageUrl();
			if (trim($url) !== '') {
				return $url;
			}
		} catch (\Throwable) {
			// Fall through to absolute web root fallback.
		}

		try {
			$absolute = $urlGenerator->getAbsoluteURL('/');
			if (trim($absolute) !== '') {
				return $absolute;
			}
		} catch (\Throwable) {
			// Last resort relative root.
		}

		return $fallback;
	}
}
