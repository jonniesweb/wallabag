<?php

namespace Tests\Wallabag\CoreBundle\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Wallabag\CoreBundle\Entity\RssFeed;
use Wallabag\CoreBundle\Entity\RssSeenItem;
use Wallabag\UserBundle\Entity\User;

class RssImportCommandTest extends WallabagCoreTestCase
{
    public function testOperatorImportIsIdempotentAndConsentIsExplicit(): void
    {
        $em = $this->getEntityManager();
        $owner = $em->getRepository(User::class)->findOneByUsername('admin');
        $application = new Application($this->getTestClient()->getKernel());
        $tester = new CommandTester($application->find('wallabag:rss:import'));
        $path = tempnam(sys_get_temp_dir(), 'rss-import-');
        file_put_contents($path, json_encode(['feeds' => [['url' => 'https://1.1.1.1/feed', 'name' => 'Synthetic import', 'enabled' => false]], 'seen_items' => ['https://1.1.1.1/feed' => [['url' => 'https://1.1.1.1/story']]]]));
        try {
            self::assertSame(0, $tester->execute(['user-id' => $owner->getId(), 'file' => $path]));
            self::assertFalse($owner->getConfig()->isXArticleReaderEnabled());
            self::assertSame(0, $tester->execute(['user-id' => $owner->getId(), 'file' => $path, '--enable-x-article-reader' => true]));
            self::assertTrue($owner->getConfig()->isXArticleReaderEnabled());
            self::assertCount(1, $em->getRepository(RssFeed::class)->findBy(['user' => $owner]));
            self::assertCount(1, $em->getRepository(RssSeenItem::class)->findAll());
        } finally {
            unlink($path);
        }
    }
}
