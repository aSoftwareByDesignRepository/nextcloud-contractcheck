<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller;

use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\SettingsSectionCatalog;
use OCA\ContractCheck\Support\AssetRegistrar;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Page controller — server-rendered app shell. All pages share main.php;
 * the active section is selected via $page and hydrated by page JS from
 * initial-state boot data.
 */
class PageController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IInitialState $initialState,
		private readonly IURLGenerator $urlGenerator,
		private readonly IL10N $l,
		private readonly AccessService $access,
		private readonly SettingsSectionCatalog $sections,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->page('dashboard');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		return $this->page('dashboard');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contracts(): TemplateResponse
	{
		return $this->page('contracts');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contractNew(): TemplateResponse
	{
		return $this->page('contract-form');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contractShow(int $id): TemplateResponse
	{
		return $this->page('contract-detail', ['contractId' => $id]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function contractEdit(int $id): TemplateResponse
	{
		return $this->page('contract-form', ['contractId' => $id]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function parties(): TemplateResponse
	{
		return $this->page('parties');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): TemplateResponse
	{
		return $this->page('reports');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function audit(): TemplateResponse
	{
		return $this->page('audit');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function import(): TemplateResponse
	{
		return $this->page('import');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsIndex(): Response
	{
		$user = $this->userSession->getUser();
		$manager = $user !== null && $this->access->isManager($user->getUID());
		return new RedirectResponse($this->urlGenerator->linkToRoute('contractcheck.page.settings', [
			'section' => $manager
				? SettingsSectionCatalog::DEFAULT_SECTION
				: SettingsSectionCatalog::VIEWER_DEFAULT_SECTION,
		]));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(string $section): Response
	{
		if (!$this->sections->isSection($section)) {
			$section = SettingsSectionCatalog::DEFAULT_SECTION;
		}
		// Manager-only panels are hidden for viewers — deep links redirect to
		// the calendar feed instead of rendering controls that can never save.
		$user = $this->userSession->getUser();
		$manager = $user !== null && $this->access->isManager($user->getUID());
		if (!$manager && in_array($section, SettingsSectionCatalog::MANAGER_ONLY_SECTIONS, true)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('contractcheck.page.settings', [
				'section' => SettingsSectionCatalog::VIEWER_DEFAULT_SECTION,
			]));
		}

		return $this->page('settings', ['section' => $section]);
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function page(string $page, array $extra = []): TemplateResponse
	{
		$user = $this->userSession->getUser();
		$uid = $user === null ? '' : $user->getUID();
		$manager = $this->access->isManager($uid);

		$this->initialState->provideInitialState('boot', [
			'page' => $page,
			'extra' => $extra,
			'uid' => $uid,
			'role' => [
				'manager' => $this->access->isManager($uid),
				'costViewer' => $this->access->canViewCosts($uid),
			],
			'routes' => [
				'base' => $this->urlGenerator->linkToRoute('contractcheck.page.index'),
			],
			'i18n' => [
				'loading' => $this->l->t('Loading…'),
				'error' => $this->l->t('Something went wrong.'),
				'saved' => $this->l->t('Saved.'),
				'conflict' => $this->l->t('This record changed in the meantime. Reload and try again.'),
			],
		]);

		AssetRegistrar::register($page === 'contract-detail' || $page === 'contract-form' ? 'contract' : $page);

		$section = (string)($extra['section'] ?? '');
		$roleLabel = $manager ? $this->l->t('Manager') : $this->l->t('Viewer');

		return new TemplateResponse('contractcheck', 'main', [
			'page' => $page,
			'extra' => $extra,
			'nav' => $this->nav($page),
			'settingsNav' => $this->settingsNav($section, $manager),
			'settingsSection' => $section,
			'roleLabel' => $roleLabel,
			'pageTitle' => $this->pageTitle($page, $section),
			'pageHelp' => $this->pageHelp($page),
			'urls' => [
				'home' => $this->urlGenerator->linkToRoute('contractcheck.page.dashboard'),
				'newContract' => $this->urlGenerator->linkToRoute('contractcheck.page.contractNew'),
				'exportCsv' => $this->urlGenerator->linkToRoute('contractcheck.api.exportCsv'),
				'exportPdf' => $this->urlGenerator->linkToRoute('contractcheck.api.exportPdf'),
				'import' => $this->urlGenerator->linkToRoute('contractcheck.page.import'),
				'contracts' => $this->urlGenerator->linkToRoute('contractcheck.page.contracts'),
				'reports' => $this->urlGenerator->linkToRoute('contractcheck.page.reports'),
			],
			'clientHints' => [
				'htmlLang' => 'en',
				'locale' => 'en',
				'timezone' => date_default_timezone_get(),
			],
			'contractId' => $extra['contractId'] ?? null,
		]);
	}

	private function pageTitle(string $page, string $section): string
	{
		return match ($page) {
			'dashboard' => $this->l->t('Dashboard'),
			'contracts' => $this->l->t('Contracts'),
			'contract-form' => $this->l->t('Contract'),
			'contract-detail' => $this->l->t('Contract'),
			'parties' => $this->l->t('Parties'),
			'reports' => $this->l->t('Reports'),
			'audit' => $this->l->t('History'),
			'import' => $this->l->t('Import'),
			'settings' => $this->sections->label($this->l, $section !== '' ? $section : SettingsSectionCatalog::DEFAULT_SECTION),
			default => $this->l->t('ContractCheck'),
		};
	}

	private function pageHelp(string $page): string
	{
		return match ($page) {
			'dashboard' => $this->l->t('Upcoming cancellation deadlines at a glance.'),
			'contracts' => $this->l->t('Every contract, one register.'),
			'parties' => $this->l->t('Contract partners and vendors.'),
			'reports' => $this->l->t('Costs and deadlines as CSV or PDF.'),
			'audit' => $this->l->t('Every change, recorded.'),
			'import' => $this->l->t('Bring in contracts from a CSV file.'),
			default => '',
		};
	}

	/** @return list<array{id:string,label:string,hint:string,href:string,icon:string,active:bool}> */
	private function settingsNav(string $active, bool $manager = true): array
	{
		$hints = [
			'deadlines' => $this->l->t('Reminder lead days'),
			'access' => $this->l->t('Who can use the app'),
			'feed' => $this->l->t('Calendar subscription'),
			'support' => $this->l->t('Donations & enterprise'),
		];
		$icons = ['deadlines' => 'bell', 'access' => 'users', 'feed' => 'calendar', 'support' => 'heart-handshake'];
		$out = [];
		foreach (SettingsSectionCatalog::SECTIONS as $section) {
			if (!$manager && in_array($section, SettingsSectionCatalog::MANAGER_ONLY_SECTIONS, true)) {
				continue;
			}
			$out[] = [
				'id' => $section,
				'label' => $this->sections->navLabel($this->l, $section),
				'hint' => $hints[$section],
				'href' => $this->urlGenerator->linkToRoute('contractcheck.page.settings', ['section' => $section]),
				'icon' => $icons[$section],
				'active' => $section === $active,
			];
		}

		return $out;
	}

	/** @return list<array{id:string,label:string,href:string,icon:string,active:bool}> */
	private function nav(string $active): array
	{
		$l = $this->l;
		$items = [
			['dashboard', $l->t('Dashboard'), 'contractcheck.page.dashboard', 'home'],
			['contracts', $l->t('Contracts'), 'contractcheck.page.contracts', 'file-text'],
			['parties', $l->t('Parties'), 'contractcheck.page.parties', 'users'],
			['reports', $l->t('Reports'), 'contractcheck.page.reports', 'activity'],
			['audit', $l->t('History'), 'contractcheck.page.audit', 'list'],
			['import', $l->t('Import'), 'contractcheck.page.import', 'upload'],
			['settings', $l->t('Settings'), 'contractcheck.page.settingsIndex', 'settings'],
		];
		$out = [];
		foreach ($items as [$id, $label, $route, $icon]) {
			$out[] = [
				'id' => $id,
				'label' => $label,
				'href' => $this->urlGenerator->linkToRoute($route),
				'icon' => $icon,
				'active' => $id === $active
					|| ($active === 'contract-form' && $id === 'contracts')
					|| ($active === 'contract-detail' && $id === 'contracts'),
			];
		}

		return $out;
	}
}
