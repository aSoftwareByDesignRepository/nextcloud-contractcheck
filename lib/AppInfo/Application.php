<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\AppInfo;

use OCA\ContractCheck\Dashboard\DeadlineWidget;
use OCA\ContractCheck\Middleware\AccessMiddleware;
use OCA\ContractCheck\Notification\Notifier;
use OCA\ContractCheck\Repair\BackupBeforeUpdate;
use OCA\ContractCheck\Repair\EnsureContractCheckSchema;
use OCA\ContractCheck\Repair\UninstallDropTables;
use OCA\ContractCheck\Service\UpgradeBackupService;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Support\NavGate;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'contractcheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		// Repair steps — every FQCN in info.xml <repair-steps> MUST be
		// registered here with all required constructor args, or
		// `occ upgrade` stalls resolving the step (family DI rule).
		$context->registerService(EnsureContractCheckSchema::class, function ($c): EnsureContractCheckSchema {
			return new EnsureContractCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate($c->get(UpgradeBackupService::class));
		});

		// Session-CSRF guard: ambient cookie-session mutations must prove a
		// valid requesttoken even when the client sets `OCS-APIRequest`
		// (core passesCSRFCheck short-circuits on that client-controlled
		// header — dutycheck 2026-09-29 lesson).
		$context->registerService(\OCA\ContractCheck\Service\CsrfTokenValidator::class, function ($c): \OCA\ContractCheck\Service\CsrfTokenValidator {
			return new \OCA\ContractCheck\Service\CsrfTokenValidator(
				$c->get(\OC\Security\CSRF\CsrfTokenManager::class),
			);
		});
		$context->registerService(\OCA\ContractCheck\Middleware\SessionCsrfMiddleware::class, function ($c): \OCA\ContractCheck\Middleware\SessionCsrfMiddleware {
			return new \OCA\ContractCheck\Middleware\SessionCsrfMiddleware(
				$c->get(\OCP\IRequest::class),
				$c->get(\OCP\IUserSession::class),
				$c->get(\OCA\ContractCheck\Service\CsrfTokenValidator::class),
			);
		});
		$context->registerMiddleware(\OCA\ContractCheck\Middleware\SessionCsrfMiddleware::class);

		$context->registerMiddleware(AccessMiddleware::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(DeadlineWidget::class);
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		// Menu entry only for users with app access — a static <navigation>
		// entry would advertise the app to users the door denies.
		NavGate::register(
			$app,
			'contractcheck.page.index',
			'app.svg',
			'ContractCheck',
			10,
			static fn(string $uid): bool => $app->get(AccessService::class)->hasAppAccess($uid),
		);
	}
}
