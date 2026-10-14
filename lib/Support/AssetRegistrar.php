<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Support;

use OCA\ContractCheck\AppInfo\Application;
use OCP\Util;

/**
 * Single source for the app's shell asset set (family pattern).
 * The access-denied surface renders via middleware before the controller —
 * it must register the same styles or it renders unstyled.
 */
final class AssetRegistrar
{
	public static function registerStyles(): void
	{
		// Make l10n/{lang}.json available to OC.L10N.translate() in page JS.
		Util::addTranslations(Application::APP_ID);
		foreach ([
			'common/tokens', 'common/shell-chrome', 'common/app-layout', 'common/navigation',
			'common/mobile-nav', 'common/form-controls', 'common/page-patterns',
			'common/notification-surfaces', 'common/dialogs', 'common/switch-field',
			'common/badges', 'common/field-errors', 'app',
		] as $style) {
			Util::addStyle(Application::APP_ID, $style);
		}
	}

	public static function registerScripts(): void
	{
		foreach (['common/toasts', 'common/field-errors', 'common/app-feedback', 'common/mobile-nav', 'app'] as $script) {
			Util::addScript(Application::APP_ID, $script);
		}
	}

	/** Register the shell + a page script (js/pages/<name>.js). */
	public static function register(string $pageScript): void
	{
		self::registerStyles();
		self::registerScripts();
		if ($pageScript !== '') {
			Util::addScript(Application::APP_ID, 'pages/' . $pageScript);
		}
	}
}
