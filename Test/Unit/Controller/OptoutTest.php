<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Controller\Optout\Index;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\RequestInterface;
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

    private function controller(?Consent $record): Index
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key) => ['c' => 'cid', 't' => 'signed'][$key]);
        $links = $this->createMock(WithdrawalLink::class);
        $links->expects($this->once())->method('withdraw')->with('cid', 'signed')->willReturn($record);
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
        $pageFactory = $this->createStub(PageFactory::class);
        $pageFactory->method('create')->willReturn($page);
        return new Index($request, $links, $pageFactory, $redirectFactory);
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
}
