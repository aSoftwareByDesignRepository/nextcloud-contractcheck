<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for ContractCheck settings sections (Bachus IA).
 */
final class SettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'deadlines';

	/** Viewer-reachable default — feed tokens are personal, support is public. */
	public const VIEWER_DEFAULT_SECTION = 'feed';

	/** @var list<string> */
	public const SECTIONS = [
		'deadlines',
		'access',
		'feed',
		'support',
	];

	/** Manager-editable sections — hidden from viewers (read-only dead ends). */
	public const MANAGER_ONLY_SECTIONS = [
		'deadlines',
		'access',
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			'deadlines' => $l->t('Deadlines'),
			'access' => $l->t('Access & roles'),
			'feed' => $l->t('Calendar feed'),
			'support' => $l->t('Support us'),
			default => $l->t('Settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return $this->label($l, $section);
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			'deadlines' => $l->t('Default reminder lead days for new contracts.'),
			'access' => $l->t('Who can use ContractCheck and which groups manage contracts and see costs.'),
			'feed' => $l->t('Subscribe to upcoming cancellation deadlines in your calendar app.'),
			'support' => $l->t('Help keep ContractCheck free — donations and enterprise contact.'),
			default => '',
		};
	}
}
