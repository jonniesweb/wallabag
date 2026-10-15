<?php

namespace Tests\Wallabag\CoreBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;

class GrabberControllerTest extends WallabagCoreTestCase
{
    public function testAuthenticationAndCsrf(): void
    {
        $client = $this->getTestClient();
        $client->request('GET', '/grabbers');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->request('GET', '/api/grabbers.json');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
        $this->logInAs('admin');
        $client->request('GET', '/grabbers');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Medium public stories', $client->getResponse()->getContent());
        $client->request('POST', '/grabbers', ['id' => 'medium', 'token' => 'invalid']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
