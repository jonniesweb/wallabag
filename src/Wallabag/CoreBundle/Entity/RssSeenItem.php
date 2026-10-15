<?php

namespace Wallabag\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="rss_seen_item", uniqueConstraints={@ORM\UniqueConstraint(name="rss_feed_item", columns={"feed_id", "url_hash"})})
 */
class RssSeenItem
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    public $id;
    /** @ORM\ManyToOne(targetEntity="RssFeed") @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    public $feed;
    /** @ORM\Column(name="url_hash", type="string", length=64) */
    public $urlHash;
    /** @ORM\Column(type="text") */
    public $url;

    public function __construct(RssFeed $feed, string $url)
    {
        $this->feed = $feed;
        $this->url = $url;
        $this->urlHash = hash('sha256', $url);
    }
}
