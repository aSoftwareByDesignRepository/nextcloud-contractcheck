<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Design-system chrome contract — ContractCheck must match the family shell
 * pattern (skip links, landmarks, breadcrumb, live regions, feedback footer).
 */
final class DesignSystemChromeTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 3);
	}

	public function testPageStartHasLandmarksAndChrome(): void
	{
		$src = (string)file_get_contents($this->root . '/templates/common/page-start.php');
		foreach ([
			'cc-page-header__main',
			'cc-page-header__icon',
			'aria-labelledby="cc-page-title"',
			'cc-breadcrumb__item--current',
			'aria-current="page"',
			'cc-skip-link',
			'id="cc-main-content"',
			'role="status" aria-live="polite"',
			'role="alert" aria-live="assertive"',
		] as $needle) {
			self::assertStringContainsString($needle, $src, "page-start missing {$needle}");
		}
	}

	public function testNavigationHasSkipLinkAndFeedbackFooter(): void
	{
		$nav = (string)file_get_contents($this->root . '/templates/common/navigation.php');
		foreach (['cc-skip-link--nav', 'feedback-nav-footer.php', 'cc-brand', 'cc-nav__link', 'IconCatalog::render'] as $needle) {
			self::assertStringContainsString($needle, $nav, "navigation missing {$needle}");
		}
		foreach ([
			'templates/parts/feedback-nav-footer.php',
			'js/common/app-feedback.js',
			'lib/Support/AppFeedbackLinks.php',
			'lib/Service/IconCatalog.php',
			'css/common/navigation.css',
		] as $file) {
			self::assertFileExists($this->root . '/' . $file);
		}
	}

	public function testNoSiblingPrefixInTemplates(): void
	{
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root . '/templates')
		);
		foreach ($it as $f) {
			if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
				$src = (string)file_get_contents($f->getPathname());
				foreach (['lck-', 'azc-', 'tkc-', 'snk-', 'cuc-'] as $prefix) {
					self::assertStringNotContainsString(
						'"' . $prefix, $src, "{$f->getFilename()} uses foreign prefix {$prefix}"
					);
				}
			}
		}
	}

	public function testMainLayoutOpensAndClosesCommonChrome(): void
	{
		$main = (string)file_get_contents($this->root . '/templates/main.php');
		self::assertStringContainsString('page-start', $main, 'main.php must open the common chrome');
		self::assertStringContainsString('page-end', $main, 'main.php must close the common chrome');
	}

	public function testEveryPartialUsesContractCheckPrefix(): void
	{
		$pages = glob($this->root . '/templates/*.php') ?: [];
		self::assertNotEmpty($pages);
		foreach ($pages as $page) {
			if (str_ends_with($page, 'main.php')) {
				continue; // layout shell — markup lives in common/* and partials
			}
			$src = (string)file_get_contents($page);
			self::assertStringContainsString(
				'cc-', $src, basename($page) . ' has no cc-* markup — dead or wrongly-named partial?'
			);
		}
	}
}
