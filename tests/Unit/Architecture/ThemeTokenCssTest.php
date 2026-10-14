<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Static CSS contracts for theme / WCAG design-system rules.
 */
final class ThemeTokenCssTest extends TestCase
{
	private string $cssRoot;

	protected function setUp(): void
	{
		$this->cssRoot = dirname(__DIR__, 3) . '/css';
	}

	private function readAllCss(): string
	{
		$blob = '';
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->cssRoot));
		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.css')) {
				$blob .= (string)file_get_contents($f->getPathname()) . "\n";
			}
		}
		return $blob;
	}

	public function testTokensDefineRequiredAliases(): void
	{
		$tokens = (string)file_get_contents($this->cssRoot . '/common/tokens.css');
		foreach ([
			'--cc-danger-fill:', '--cc-primary:', '--cc-focus:', '--cc-touch-lg:',
			'color-element-error', 'data-theme-dark',
			'--cc-radius-sm: var(--border-radius',
			'--cc-radius-pill: var(--border-radius-pill',
		] as $needle) {
			self::assertStringContainsString($needle, $tokens, "tokens.css missing {$needle}");
		}
	}

	public function testEveryUsedContractCheckAliasIsDefined(): void
	{
		$blob = $this->readAllCss();
		preg_match_all('/--cc-[a-z0-9-]+/', $blob, $used);
		preg_match_all('/(--cc-[a-z0-9-]+)\s*:/', $blob, $defined);
		$missing = array_values(array_diff(array_unique($used[0] ?? []), array_unique($defined[1] ?? [])));
		self::assertSame([], $missing, 'Undefined --cc-* tokens: ' . implode(', ', $missing));
	}

	public function testNoSiblingAppPrefixLeaks(): void
	{
		$blob = $this->readAllCss();
		foreach (['lck-', 'dc-', 'tkc-', 'snk-', 'cuc-', 'mc-', 'hc-', 'ic-', 'pc-', 'bc-', 'wc-', 'tc-', 'azc-'] as $prefix) {
			self::assertStringNotContainsString('--' . $prefix, $blob, "foreign token prefix {$prefix} leaked");
		}
	}

	public function testFocusVisibleIsStyled(): void
	{
		$blob = $this->readAllCss();
		self::assertMatchesRegularExpression('/:focus-visible/', $blob, 'no :focus-visible styling — WCAG 2.4.7');
	}

	public function testStatusNotColorOnly(): void
	{
		// Status chips/badges must carry non-color cues (icon or text) — WCAG 1.4.1.
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/templates')
		);
		$found = false;
		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
				$src = (string)file_get_contents($f->getPathname());
				if (str_contains($src, 'cc-badge') || str_contains($src, 'status')) {
					$found = true;
				}
			}
		}
		self::assertTrue($found, 'no status badge markup found — verify status is not color-only');
	}

	public function testScreenReaderOnlyUtilityExists(): void
	{
		$blob = $this->readAllCss();
		self::assertMatchesRegularExpression('/\.cc-sr-only/', $blob, 'missing .cc-sr-only utility for live regions');
	}
}
