<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Hard gates for ContractCheck localization: parity, placeholders, code
 * coverage, formal German (Sie-form, no informal du), json/js consistency.
 */
final class L10nCatalogTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	private function scriptPasses(string $name): void
	{
		$script = $this->root . '/scripts/' . $name;
		self::assertFileExists($script);
		passthru('php ' . escapeshellarg($script), $code);
		self::assertSame(0, $code, $name . ' failed');
	}

	public function testParityScriptPasses(): void
	{
		$this->scriptPasses('check-l10n-parity.php');
	}

	public function testPlaceholderScriptPasses(): void
	{
		$this->scriptPasses('check-l10n-placeholders.php');
	}

	public function testCodeKeysScriptPasses(): void
	{
		$this->scriptPasses('check-l10n-code-keys.php');
	}

	public function testGermanIsFormal(): void
	{
		$de = json_decode(
			(string)file_get_contents($this->root . '/l10n/de.json'), true, 512, JSON_THROW_ON_ERROR
		);
		$informal = [];
		foreach (($de['translations'] ?? []) as $key => $val) {
			foreach (is_array($val) ? $val : [$val] as $form) {
				if (preg_match('/\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deines|dich|euer|eure)\b/i', (string)$form)) {
					$informal[] = $key;
				}
			}
		}
		self::assertSame([], $informal, 'Informal German (du-Form) found: ' . implode(', ', $informal));
	}

	public function testEnAndDeCatalogsExist(): void
	{
		foreach (['en', 'de'] as $lang) {
			self::assertFileExists($this->root . "/l10n/{$lang}.json");
			self::assertFileExists($this->root . "/l10n/{$lang}.js");
		}
	}

	public function testNoEmptyTranslations(): void
	{
		foreach (['en', 'de'] as $lang) {
			$cat = json_decode(
				(string)file_get_contents($this->root . "/l10n/{$lang}.json"), true, 512, JSON_THROW_ON_ERROR
			);
			foreach (($cat['translations'] ?? []) as $key => $val) {
				self::assertNotSame('', is_array($val) ? implode('', $val) : $val, "empty translation for '{$key}' in {$lang}");
			}
		}
	}
}
