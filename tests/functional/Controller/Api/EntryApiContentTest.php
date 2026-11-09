<?php

namespace Wallabag\Tests\Functional\Controller\Api;

use Graby\Graby;
use Wallabag\Entity\Entry;

class EntryApiContentTest extends RssApiTestCase
{
    /**
     * @dataProvider entryContent
     */
    public function testApiSavePreservesFetchedContent(array $provided, string $expectedTitle, string $expectedHtml): void
    {
        $url = 'https://example.com/api-content-regression';
        $this->client->disableReboot();
        $graby = $this->getMockBuilder(Graby::class)->onlyMethods(['fetchContent'])->getMock();
        $graby->expects($this->once())->method('fetchContent')->with($url)->willReturn([
            'html' => '<p>Fetched article content.</p>',
            'title' => 'Fetched title',
            'url' => $url,
            'language' => 'en',
            'date' => '2026-11-02T12:00:02+00:00',
            'authors' => ['Article author'],
            'headers' => ['content-type' => 'text/html'],
            'status' => 200,
        ]);
        $this->client->getContainer()->set(Graby::class, $graby);

        $this->client->request('POST', '/api/entries.json', ['url' => $url] + $provided);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($expectedTitle, $response['title']);
        self::assertSame($expectedHtml, $response['content']);
        self::assertSame('en', $response['language']);
        self::assertSame(['Article author'], $response['published_by']);

        $em = $this->getEntityManager();
        $em->clear();
        $entry = $em->getRepository(Entry::class)->find($response['id']);
        self::assertSame($expectedTitle, $entry->getTitle());
        self::assertSame($expectedHtml, $entry->getContent());
        self::assertSame($this->user->getId(), $entry->getUser()->getId());
        self::assertFalse($entry->isNotParsed());
    }

    public static function entryContent(): array
    {
        return [
            'URL only' => [[], 'Fetched title', '<p>Fetched article content.</p>'],
            'blank fields' => [['title' => '', 'content' => '', 'language' => ''], 'Fetched title', '<p>Fetched article content.</p>'],
            'supplied title' => [['title' => 'My title'], 'My title', '<p>Fetched article content.</p>'],
            'supplied content' => [['title' => 'My title', 'content' => '<p>My article content.</p>'], 'My title', '<p>My article content.</p>'],
        ];
    }
}
