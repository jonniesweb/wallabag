<?php

namespace Tests\Wallabag\CoreBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\CoreBundle\Entity\Entry;
use Wallabag\CoreBundle\Helper\PublicHttpClient;

class XEntryControllerTest extends WallabagCoreTestCase
{
    public function testNormalNewEntryFormSavesPublicXSnapshot(): void
    {
        $this->logInAs('admin');
        $client = $this->getTestClient();
        $client->disableReboot();
        $http = $this->createMock(PublicHttpClient::class);
        $http->method('get')->willReturn(['body' => '{"author_name":"lauren","html":"<blockquote><p>Public form snapshot</p></blockquote>"}']);
        $client->getContainer()->set(PublicHttpClient::class, $http);
        $crawler = $client->request('GET', '/new-entry');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name=entry]')->form();
        $client->submit($form, ['entry[url]' => 'https://x.com/poteto/status/2069824386283319343']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $entry = $this->getEntityManager()->getRepository(Entry::class)->findByUrlAndUserId('https://x.com/poteto/status/2069824386283319343', $this->getLoggedInUserId());
        self::assertNotFalse($entry);
        self::assertSame(206, (int) $entry->getHttpStatus());
        self::assertStringContainsString('Partial X capture', $entry->getContent());
        self::assertStringContainsString('Public form snapshot', $entry->getContent());
    }
}
