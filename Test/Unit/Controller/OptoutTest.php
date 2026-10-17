<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Controller\Optout\Index;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\App\Action\Context;
use PHPUnit\Framework\TestCase;

class OptoutTest extends TestCase
{
    private array $pathParams = [];
    private array $messages = [];
    private array $page = [];
    private ?string $path = null;
    private ?int $status = null;

    private function controller(
        ?Consent $record,
        string $method = 'GET',
        mixed $id = null,
        mixed $token = null,
        string $browserHash = 'original',
        ?string $recordHash = 'original',
        mixed $key = 'valid',
        bool $keyValid = true,
        ?array $oneClickBody = null,
        string $contentType = 'application/x-www-form-urlencoded',
        bool $busy = false
    ): Index {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParam')->willReturnCallback(fn ($name) => ['c' => $id ?? str_repeat('a', 32), 't' => $token ?? str_repeat('b', 64), 'form_key' => $key][$name]);
        $request->method('getQuery')->willReturnCallback(fn ($name) => ['c' => $id ?? str_repeat('a', 32), 't' => $token ?? str_repeat('b', 64)][$name] ?? null);
        $request->method('getPost')->willReturnCallback(fn ($name) => $oneClickBody[$name] ?? null);
        $request->method('getPostValue')->willReturn($oneClickBody ?? []);
        $request->method('getHeader')->willReturn($contentType);
        $machine = $method === 'POST' && isset($oneClickBody['List-Unsubscribe']);
        $validMachine = $machine && $oneClickBody === ['List-Unsubscribe' => 'One-Click']
            && in_array(explode(';', $contentType)[0], ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            && $id === null && ($token === null || is_string($token));
        $links = $this->createMock(WithdrawalLink::class);
        $allowed = !$machine && in_array($method, ['GET', 'POST'], true) && $id === null && $token === null
            && ($method !== 'POST' || (is_string($key) && $keyValid));
        $links->expects(($allowed && $method === 'POST') || $validMachine ? $this->once() : $this->never())->method('withdraw')
            ->with($id ?? str_repeat('a', 32), $token ?? str_repeat('b', 64))
            ->willReturnCallback(fn () => $busy
                ? throw new \CommerceKings\Consent\Exception\WithdrawalBusyException('busy') : $record);
        $links->expects($allowed && $method === 'GET' ? $this->once() : $this->never())->method('getConsent')
            ->with(str_repeat('a', 32), str_repeat('b', 64))->willReturn($record);
        $raw = $this->createStub(\Magento\Framework\Controller\Result\Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($status) use ($raw) { $this->status = $status; return $raw; });
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createMock(\Magento\Framework\Controller\Result\RawFactory::class);
        $rawFactory->expects($machine ? $this->once() : $this->never())->method('create')->willReturn($raw);
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn($keyValid);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->path = $path;
            $this->pathParams = $params;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $title = $this->createStub(Title::class);
        $title->method('set')->willReturnCallback(function ($value) { $this->page['title'] = (string)$value; });
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($title);
        $config->method('setRobots')->willReturnCallback(function ($value) { $this->page['robots'] = $value; });
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $pageFactory = $this->createMock(PageFactory::class);
        // every browser sees a page - the result, or that the link is no longer valid; never HEAD or a bad form key
        $showsPage = !$machine && !$busy && in_array($method, ['GET', 'POST'], true)
            && ($method !== 'POST' || (is_string($key) && $keyValid));
        $pageFactory->expects($showsPage ? $this->once() : $this->never())->method('create')->willReturn($page);
        $messages = $this->createStub(\Magento\Framework\Message\ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($text) use ($messages) {
            $this->messages[] = (string)$text;
            return $messages;
        });
        $context = $this->createStub(Context::class);
        $context->method('getMessageManager')->willReturn($messages);
        return new Index($context, $request, $links, $pageFactory, $redirectFactory, $validator, $rawFactory);
    }

    public function testSignedGetShowsConfirmationWithoutWithdrawing(): void
    {
        $record = new Consent('cid', 'some_feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $this->assertInstanceOf(Page::class, $this->controller($record)->execute());
        $this->assertSame(['title' => 'Withdraw consent', 'robots' => 'NOINDEX,NOFOLLOW'], $this->page);
        $this->assertNull($this->path);
    }

    public function testInvalidOrDeletedConsentSaysTheLinkIsNoLongerValid(): void
    {
        // e.g. signed with an encryption key that was rotated since, or a consent already cleaned up
        foreach (['GET', 'POST'] as $method) {
            $this->page = [];
            $this->assertInstanceOf(Page::class, $this->controller(null, $method)->execute());
            $this->assertSame(['title' => 'Link expired', 'robots' => 'NOINDEX,NOFOLLOW'], $this->page);
            $this->assertNull($this->path);
        }
    }

    /** @dataProvider otherBrowsers */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherBrowsers')]
    public function testConfirmedPostShowsTheResultAlsoWithoutItsOriginalBrowser(
        string $browserHash,
        ?string $recordHash
    ): void {
        // e.g. consented on the laptop, withdrew from the email on the phone: the shopper sees it worked
        $record = new Consent('cid', 'some_feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $result = $this->controller($record, 'POST', browserHash: $browserHash, recordHash: $recordHash)->execute();
        $this->assertInstanceOf(Page::class, $result);
        $this->assertSame('Consent withdrawn', $this->page['title']);
        $this->assertNull($this->path);
    }

    public static function otherBrowsers(): array
    {
        return [
            'different identity' => ['different', 'original'],
            'fresh browser' => ['', 'original'],
            'record removed after withdrawal' => ['original', null],
        ];
    }

    public function testHeadDoesNotWithdrawOrRenderBannerSync(): void
    {
        $this->assertInstanceOf(Redirect::class, $this->controller(null, 'HEAD')->execute());
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->page);
    }

    /** @dataProvider malformedLinks */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedLinks')]
    public function testMalformedLinkDoesNotReachWithdrawal(mixed $id, mixed $token): void
    {
        // a link damaged by a mail client: no withdrawal, but the shopper learns it did not work
        $this->assertInstanceOf(Page::class, $this->controller(null, 'GET', $id, $token)->execute());
        $this->assertSame('Link expired', $this->page['title']);
        $this->assertNull($this->path);
    }

    public static function malformedLinks(): array
    {
        return [
            'array id' => [['id'], null], 'array token' => [null, ['token']],
            'integer id' => [42, null], 'empty id' => ['', null],
            'oversized id' => [str_repeat('a', 33), null],
            'non-hex id' => [str_repeat('z', 32), null],
            'short token' => [null, 'forged'], 'oversized token' => [null, str_repeat('b', 65)],
        ];
    }
    public function testGetFromAnotherBrowserShowsGenericConfirmationWithoutWithdrawal(): void
    {
        $record = new Consent('cid', 'feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $this->assertInstanceOf(Page::class, $this->controller($record, browserHash: 'different')->execute());
        $this->assertSame('Withdraw consent', $this->page['title']);
        $this->assertNull($this->path);
    }

    public function testConfirmedPostInTheOriginalBrowserRendersTheResult(): void
    {
        $record = new Consent('cid', 'feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $this->assertInstanceOf(Page::class, $this->controller($record, 'POST')->execute());
        $this->assertSame('Consent withdrawn', $this->page['title']);
    }

    /** @dataProvider invalidFormKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFormKeys')]
    public function testPostRequiresAStringValidFormKey(mixed $key, bool $valid): void
    {
        $this->assertInstanceOf(Redirect::class, $this->controller(null, 'POST', key: $key, keyValid: $valid)->execute());
        $this->assertSame('', $this->path);
    }

    /** @dataProvider oneClickRequests */
    #[\PHPUnit\Framework\Attributes\DataProvider('oneClickRequests')]
    public function testOneClickPostUsesNoBrowserOrRedirectAndLeavesConfirmationSeparate(array $body, mixed $token, string $type, bool $valid): void
    {
        $record = $valid ? new Consent('cid', 'feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00') : null;
        $this->assertInstanceOf(\Magento\Framework\Controller\Result\Raw::class,
            $this->controller($record, 'POST', token: $token, key: null, oneClickBody: $body, contentType: $type)->execute());
        $this->assertSame($valid ? 200 : 400, $this->status);
        $this->assertNull($this->path);
        $this->assertSame([], $this->page);
    }

    public static function oneClickRequests(): array
    {
        $body = ['List-Unsubscribe' => 'One-Click'];
        $token = str_repeat('b', 64);
        return [
            'urlencoded without form key' => [$body, $token, 'application/x-www-form-urlencoded', true],
            'multipart without form key' => [$body, $token, 'multipart/form-data; boundary=test', true],
            'invalid signature' => [$body, 'forged', 'application/x-www-form-urlencoded', false],
            'array token' => [$body, ['token'], 'application/x-www-form-urlencoded', false],
            'array marker' => [['List-Unsubscribe' => ['One-Click']], $token, 'application/x-www-form-urlencoded', false],
            'extra body credentials' => [$body + ['c' => 'override'], $token, 'application/x-www-form-urlencoded', false],
            'json' => [$body, $token, 'application/json', false],
        ];
    }

    public static function invalidFormKeys(): array
    {
        return ['missing' => [null, true], 'array' => [['key'], true], 'invalid' => ['bad', false]];
    }

    public function testADoubleClickWhileTheFirstConfirmationIsWrittenReturnsToTheConfirmation(): void
    {
        $record = new Consent('cid', 'feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $this->assertInstanceOf(Redirect::class, $this->controller($record, 'POST', busy: true)->execute());
        $this->assertSame('ckconsent/optout', $this->path, 'not an error page; nothing changed, confirm again');
        $this->assertSame(['_query' => ['c' => str_repeat('a', 32), 't' => str_repeat('b', 64)]], $this->pathParams);
        $this->assertSame(['We could not save this right now. Please try again.'], $this->messages);
    }

    public function testABusyOneClickUnsubscribeAsksTheMailProviderToRetry(): void
    {
        $this->controller(null, 'POST', oneClickBody: ['List-Unsubscribe' => 'One-Click'], busy: true)->execute();
        $this->assertSame(503, $this->status);
    }
}
