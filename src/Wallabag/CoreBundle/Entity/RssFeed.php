<?php

namespace Wallabag\CoreBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Wallabag\UserBundle\Entity\User;

/**
 * @ORM\Entity
 * @ORM\Table(name="rss_feed", uniqueConstraints={@ORM\UniqueConstraint(name="rss_owner_url", columns={"user_id", "url_hash"})})
 */
class RssFeed
{
    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    public $id;
    /** @ORM\ManyToOne(targetEntity="Wallabag\UserBundle\Entity\User") @ORM\JoinColumn(nullable=false, onDelete="CASCADE") */
    public $user;
    /** @ORM\Column(type="text") */
    public $url;
    /** @ORM\Column(name="url_hash", type="string", length=64) */
    public $urlHash;
    /** @ORM\Column(type="string", length=255) */
    public $name = '';
    /** @ORM\Column(type="json") */
    public $tags = [];
    /** @ORM\Column(name="max_items", type="integer") */
    public $maxItems = 10;
    /** @ORM\Column(type="boolean") */
    public $enabled = true;
    /** @ORM\Column(name="next_fetch_at", type="datetime", nullable=true) */
    public $nextFetchAt;
    /** @ORM\Column(name="last_fetch_at", type="datetime", nullable=true) */
    public $lastFetchAt;
    /** @ORM\Column(name="last_error", type="text", nullable=true) */
    public $lastError;
    /** @ORM\Column(name="imported_count", type="integer") */
    public $importedCount = 0;

    public function __construct(User $user, string $url)
    {
        $this->user = $user;
        $this->url = $url;
        $this->urlHash = hash('sha256', $url);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'url' => $this->url, 'name' => $this->name, 'tags' => $this->tags,
            'max_items' => $this->maxItems, 'enabled' => $this->enabled,
            'last_fetch_at' => $this->lastFetchAt ? $this->lastFetchAt->format(DATE_ATOM) : null,
            'next_fetch_at' => $this->nextFetchAt ? $this->nextFetchAt->format(DATE_ATOM) : null,
            'last_error' => $this->lastError, 'imported_count' => $this->importedCount];
    }
}
