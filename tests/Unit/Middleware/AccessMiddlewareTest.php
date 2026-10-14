<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Middleware;

use OCA\ContractCheck\Controller\FeedController;
use OCA\ContractCheck\Controller\PageController;
use OCA\ContractCheck\Exception\AppAccessDeniedException;
use OCA\ContractCheck\Middleware\AccessMiddleware;
use OCA\ContractCheck\Service\AccessService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AccessMiddlewareTest extends TestCase
{
	private AccessService&MockObject $access;
	private IUserSession&MockObject $session;
	private IRequest&MockObject $request;
	private AccessMiddleware $mw;

	protected function setUp(): void
	{
		$this->access = $this->createMock(AccessService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->mw = new AccessMiddleware(
			$this->access, $this->session, $this->request, $this->createMock(IL10N::class),
			$this->createMock(IURLGenerator::class)
		);
	}

	private function user(?string $uid): void
	{
		if ($uid === null) {
			$this->session->method('getUser')->willReturn(null);
			return;
		}
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($u);
	}

	public function testAnonymousDenied(): void
	{
		$this->user(null);
		$this->expectException(AppAccessDeniedException::class);
		$this->mw->beforeController($this->createMock(PageController::class), 'index');
	}

	public function testUserWithoutAppAccessDenied(): void
	{
		$this->user('mallory');
		$this->access->method('hasAppAccess')->with('mallory')->willReturn(false);
		$this->expectException(AppAccessDeniedException::class);
		$this->mw->beforeController($this->createMock(PageController::class), 'index');
	}

	public function testUserWithAccessPasses(): void
	{
		$this->user('alice');
		$this->access->method('hasAppAccess')->willReturn(true);
		$this->mw->beforeController($this->createMock(PageController::class), 'index');
		self::assertTrue(true);
	}

	public function testFeedControllerBypassesSessionCheck(): void
	{
		$this->user(null); // token auth — no session at all
		$this->access->expects($this->never())->method('hasAppAccess');
		$this->mw->beforeController($this->createMock(FeedController::class), 'ics');
		self::assertTrue(true);
	}

	public function testJsonRequestGetsEnvelope(): void
	{
		$this->request->method('getRequestUri')->willReturn('/apps/contractcheck/api/contracts');
		$resp = $this->mw->afterException(
			$this->createMock(PageController::class), 'index', new AppAccessDeniedException('x')
		);
		self::assertInstanceOf(JSONResponse::class, $resp);
		self::assertSame(403, $resp->getStatus());
		$data = $resp->getData();
		self::assertSame('FORBIDDEN', $data['error']);
	}

	public function testPageRequestGetsTemplate(): void
	{
		$this->request->method('getRequestUri')->willReturn('/apps/contractcheck/');
		$resp = $this->mw->afterException(
			$this->createMock(PageController::class), 'index', new AppAccessDeniedException('x')
		);
		self::assertInstanceOf(TemplateResponse::class, $resp);
		self::assertSame(403, $resp->getStatus());
	}

	public function testOtherExceptionsRethrown(): void
	{
		$this->request->method('getRequestUri')->willReturn('/apps/contractcheck/api/');
		$this->expectException(\RuntimeException::class);
		$this->mw->afterException(
			$this->createMock(PageController::class), 'index', new \RuntimeException('x')
		);
	}
}
