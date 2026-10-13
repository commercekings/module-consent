<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Controller\Optout\Index;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

class OptoutTest extends TestCase
{
    private array $page = [];
    private ?string $path = null;

    private function controller(
        ?Consent $record,
        string $method = 'GET',
        mixed $id = null,
        mixed $token = null,
        string $browserHash = 'original',
        ?string $recordHash = 'original'
    ): Index {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParam')->willReturnCallback(fn ($key) => ['c' => $id ?? str_repeat('a', 32), 't' => $token ?? str_repeat('b', 64)][$key]);
        $links = $this->createMock(WithdrawalLink::class);
        $allowed = $method === 'GET' && $id === null && $token === null;
        $links->expects($allowed ? $this->once() : $this->never())->method('withdraw')
            ->with(str_repeat('a', 32), str_repeat('b', 64))->willReturn($record);
        $store = $this->createMock(ConsentStore::class);
        $store->expects($allowed && $record ? $this->once() : $this->never())->method('load')
            ->with(str_repeat('a', 32))->willReturn($recordHash === null ? null : ['browser_hash' => $recordHash]);
        $browser = $this->createMock(BrowserIdentityInterface::class);
        $browser->expects($allowed && $record ? $this->once() : $this->never())->method('hash')
            ->willReturn($browserHash);
        $browser->expects($this->never())->method('ensure');
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->path = $path;
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
        $showsPage = $allowed && $record && $browserHash !== '' && $recordHash === $browserHash;
        $pageFactory->expects($showsPage ? $this->once() : $this->never())->method('create')->willReturn($page);
        return new Index($request, $links, $pageFactory, $redirectFactory, $browser, $store);
    }

    public function testSignedConsentShowsGenericConfirmation(): void
    {
        $record = new Consent('cid', 'some_feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $this->assertInstanceOf(Page::class, $this->controller($record)->execute());
        $this->assertSame(['title' => 'Consent withdrawn', 'robots' => 'NOINDEX,NOFOLLOW'], $this->page);
        $this->assertNull($this->path);
    }

    public function testInvalidOrDeletedConsentGoesHome(): void
    {
        $this->assertInstanceOf(Redirect::class, $this->controller(null)->execute());
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->page);
    }

    /** @dataProvider otherBrowsers */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherBrowsers')]
    public function testSignedLinkWithdrawsButRedirectsWithoutItsOriginalBrowser(
        string $browserHash,
        ?string $recordHash
    ): void {
        $record = new Consent('cid', 'some_feature', 1, 'source', 'choice', '2020-01-01 00:00:00',
            '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        $result = $this->controller($record, browserHash: $browserHash, recordHash: $recordHash)->execute();
        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->page);
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
        $this->controller(null, 'GET', $id, $token)->execute();
        $this->assertSame('', $this->path);
        $this->assertSame([], $this->page);
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
}
