<?php

namespace App\Tests\Controller;

use App\Tests\AppTestCase;
use Symfony\Bridge\Twig\ErrorRenderer\TwigErrorRenderer;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/**
 * The error pages only show when debug is off, and the test kernel runs in debug, so these render
 * them through the Twig error renderer the way the production error controller does.
 */
final class ErrorPageTest extends AppTestCase
{
    public function testForbiddenPageNamesTheAccountAndOffersAWayOut(): void
    {
        $user = $this->createUser('admin');
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $page = $this->renderError(Request::create('/cards'));

        self::assertStringContainsString('access to this page', $page->filter('h1')->text());
        self::assertStringContainsString('admin (User Administrator)', $page->filter('main')->text());
        self::assertStringContainsString('GET /cards', $page->filter('main')->text());
        self::assertSame('/account/start', $page->selectLink('Go to my start page')->attr('href'));
        self::assertStringStartsWith('/logout', $page->selectLink('Sign out')->attr('href'));
    }

    public function testForbiddenPageForAVisitorOffersSignIn(): void
    {
        $page = $this->renderError(Request::create('/logout'));

        self::assertSame('/login', $page->selectLink('Sign in')->attr('href'));
        self::assertCount(0, $page->selectLink('Sign out'));
    }

    private function renderError(Request $request): Crawler
    {
        $container = static::getContainer();
        $request->setSession($container->get('session.factory')->createSession());
        $container->get('request_stack')->push($request);

        $renderer = new TwigErrorRenderer($container->get(Environment::class), debug: false);
        $exception = $renderer->render(new AccessDeniedHttpException());

        self::assertSame(403, $exception->getStatusCode());

        return new Crawler($exception->getAsString(), 'http://localhost/');
    }
}
