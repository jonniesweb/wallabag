<?php

namespace Wallabag\Helper;

use enshrined\svgSanitize\Sanitizer;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class DownloadImages
{
    public const REGENERATE_PICTURES_QUALITY = 80;
    private $mimeTypes;
    private $wallabagUrl;

    public function __construct(
        private readonly HttpClientInterface $client,
        private $baseFolder,
        $wallabagUrl,
        private readonly LoggerInterface $logger,
    ) {
        $this->wallabagUrl = rtrim((string) $wallabagUrl, '/');
        $this->mimeTypes = new MimeTypes();

        $this->setFolder();
    }

    public function getBaseFolder()
    {
        return $this->baseFolder;
    }

    /**
     * Process the html and extract images URLs from it.
     *
     * @param string $html
     *
     * @return string[]
     */
    public static function extractImagesUrlsFromHtml($html)
    {
        $crawler = new Crawler($html);
        $imagesCrawler = $crawler->filterXpath('//img');
        $imagesUrls = $imagesCrawler->extract(['src']);
        $imagesSrcsetUrls = self::getSrcsetUrls($imagesCrawler);

        return array_unique(array_merge($imagesUrls, $imagesSrcsetUrls));
    }

    /**
     * Resolve image URLs in extracted article HTML and remove blocked-image placeholders.
     */
    public static function normalizeImageSources(string $html, string $sourceUrl): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = false;

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="wallabag-article-root">' . $html . '</div></body></html>',
                \LIBXML_NONET | \LIBXML_HTML_NODEFDTD
            );
        } catch (\Throwable) {
            // Keep the original HTML if libxml cannot parse a malformed fragment.
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlState);
        }

        if (!$loaded) {
            return $html;
        }

        $root = (new \DOMXPath($document))->query('//*[@id="wallabag-article-root"]')->item(0);
        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $images = [];
        foreach ($root->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }

        $changed = false;
        foreach ($images as $image) {
            $src = trim($image->getAttribute('src'));
            $srcset = self::normalizeSrcset($image->getAttribute('srcset'), $sourceUrl);
            $isDeniedSource = 1 === preg_match('/^denied:/i', $src);

            if ($isDeniedSource && [] === $srcset) {
                $image->parentNode?->removeChild($image);
                $changed = true;

                continue;
            }

            if ('' !== $src && !$isDeniedSource) {
                $absoluteSrc = self::makeAbsoluteImageUrl($sourceUrl, $src);
                if ($absoluteSrc !== $src) {
                    $image->setAttribute('src', $absoluteSrc);
                    $changed = true;
                }
            } elseif ([] !== $srcset) {
                $image->setAttribute('src', $srcset[0]['url']);
                $changed = true;
            }

            if ([] !== $srcset) {
                $absoluteSrcset = implode(', ', array_map(static function (array $candidate): string {
                    return $candidate['url'] . ('' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '');
                }, $srcset));

                if ($absoluteSrcset !== $image->getAttribute('srcset')) {
                    $image->setAttribute('srcset', $absoluteSrcset);
                    $changed = true;
                }
            } elseif ($image->hasAttribute('srcset')) {
                $image->removeAttribute('srcset');
                $changed = true;
            }
        }

        if (!$changed) {
            return $html;
        }

        $normalizedHtml = '';
        foreach ($root->childNodes as $child) {
            $normalizedHtml .= $document->saveHTML($child);
        }

        return $normalizedHtml;
    }

    /**
     * @return array<int, array{url: string, descriptor: string}>
     */
    private static function normalizeSrcset(string $srcset, string $sourceUrl): array
    {
        $candidates = [];
        $length = \strlen($srcset);
        $position = 0;

        while ($position < $length) {
            while ($position < $length && (',' === $srcset[$position] || ctype_space($srcset[$position]))) {
                ++$position;
            }

            if ($position >= $length) {
                break;
            }

            $urlStart = $position;
            while ($position < $length && !ctype_space($srcset[$position])) {
                ++$position;
            }

            $candidateUrl = substr($srcset, $urlStart, $position - $urlStart);
            $endsWithComma = str_ends_with($candidateUrl, ',');
            while (str_ends_with($candidateUrl, ',')) {
                $candidateUrl = substr($candidateUrl, 0, -1);
            }

            if ('' === $candidateUrl) {
                continue;
            }

            $descriptor = '';
            if (!$endsWithComma) {
                while ($position < $length && ctype_space($srcset[$position])) {
                    ++$position;
                }

                $descriptorStart = $position;
                while ($position < $length && ',' !== $srcset[$position]) {
                    ++$position;
                }
                $descriptor = trim(substr($srcset, $descriptorStart, $position - $descriptorStart));

                if ($position < $length && ',' === $srcset[$position]) {
                    ++$position;
                }
            }

            if (1 === preg_match('/^denied:/i', $candidateUrl)) {
                continue;
            }

            $candidates[] = [
                'url' => self::makeAbsoluteImageUrl($sourceUrl, $candidateUrl),
                'descriptor' => $descriptor,
            ];
        }

        return $candidates;
    }

    private static function makeAbsoluteImageUrl(string $sourceUrl, string $imageUrl): string
    {
        if ('' === $imageUrl || 1 === preg_match('/^[a-z][a-z0-9+.-]*:/i', $imageUrl)) {
            return $imageUrl;
        }

        try {
            $base = new Uri($sourceUrl);
            if ('' === $base->getScheme() || '' === $base->getAuthority()) {
                return $imageUrl;
            }

            return (string) UriResolver::resolve($base, new Uri($imageUrl));
        } catch (\Throwable) {
            return $imageUrl;
        }
    }

    /**
     * Process the html and extract image from it, save them to local and return the updated html.
     *
     * @param int    $entryId ID of the entry
     * @param string $html
     * @param string $url     Used as a base path for relative image and folder
     *
     * @return string
     */
    public function processHtml($entryId, $html, $url)
    {
        $imagesUrls = self::extractImagesUrlsFromHtml($html);

        // ensure images aren't overlapping
        arsort($imagesUrls);

        $relativePath = $this->getRelativePath($entryId);

        // download and save the image to the folder
        foreach ($imagesUrls as $image) {
            $newImage = $this->processSingleImage($entryId, $image, $url, $relativePath);

            if (false === $newImage) {
                continue;
            }

            $html = str_replace($image, $newImage, $html);
            // if image contains "&" and we can't find it in the html it might be because it's encoded as &amp; or unicode
            if (false !== stripos($image, '&') && false === stripos($html, $image)) {
                $imageAmp = str_replace('&', '&amp;', $image);
                $html = str_replace($imageAmp, $newImage, $html);
                $imageUnicode = str_replace('&', '&#038;', $image);
                $html = str_replace($imageUnicode, $newImage, $html);
            }
        }

        return $html;
    }

    /**
     * Process a single image:
     *     - retrieve it
     *     - re-saved it (for security reason)
     *     - return the new local path.
     *
     * @param int         $entryId      ID of the entry
     * @param string|null $imagePath    Path to the image to retrieve
     * @param string      $url          Url from where the image were found
     * @param string      $relativePath Relative local path to saved the image
     *
     * @return string|false Relative url to access the image from the web
     */
    public function processSingleImage($entryId, $imagePath, $url, $relativePath = null)
    {
        if (null === $imagePath) {
            return false;
        }

        if (null === $relativePath) {
            $relativePath = $this->getRelativePath($entryId);
        }

        $this->logger->debug('DownloadImages: working on image: ' . $imagePath);

        $folderPath = $this->baseFolder . '/' . $relativePath;

        // build image path
        $absolutePath = $this->getAbsoluteLink($url, $imagePath);
        if (false === $absolutePath) {
            $this->logger->error('DownloadImages: Can not determine the absolute path for that image, skipping.');

            return false;
        }

        try {
            $res = $this->client->request(Request::METHOD_GET, $absolutePath);
        } catch (\Exception $e) {
            $this->logger->error('DownloadImages: Can not retrieve image, skipping.', ['exception' => $e]);

            return false;
        }

        $ext = $this->getExtensionFromResponse($res, $imagePath);
        if (false === $ext) {
            return false;
        }

        $hashImage = hash('crc32', $absolutePath);
        $localPath = $folderPath . '/' . $hashImage . '.' . $ext;
        $urlPath = $this->wallabagUrl . '/assets/images/' . $relativePath . '/' . $hashImage . '.' . $ext;

        // custom case for SVG (because GD doesn't support SVG)
        if ('svg' === $ext) {
            try {
                $sanitizer = new Sanitizer();
                $sanitizer->minify(true);
                $sanitizer->removeRemoteReferences(true);
                $cleanSVG = $sanitizer->sanitize($res->getContent());

                // add an extra validation by checking about `<svg `
                if (false === $cleanSVG || !str_contains($cleanSVG, '<svg ')) {
                    $this->logger->error('DownloadImages: Bad SVG given', ['path' => $imagePath]);

                    return false;
                }

                file_put_contents($localPath, $cleanSVG);

                return $urlPath;
            } catch (\Exception $e) {
                $this->logger->error('DownloadImages: Error while sanitize SVG', ['path' => $imagePath, 'message' => $e->getMessage()]);

                return false;
            }
        }

        try {
            $im = imagecreatefromstring($res->getContent());
        } catch (\Exception) {
            $im = false;
        }

        if (false === $im) {
            $this->logger->error('DownloadImages: Error while regenerating image', ['path' => $localPath]);

            return false;
        }

        switch ($ext) {
            case 'gif':
                // use Imagick if available to keep GIF animation
                if (class_exists(\Imagick::class)) {
                    try {
                        $imagick = new \Imagick();
                        $imagick->readImageBlob($res->getContent());
                        $imagick->setImageFormat('gif');
                        $imagick->writeImages($localPath, true);
                    } catch (\Exception) {
                        // if Imagick fail, fallback to the default solution
                        imagegif($im, $localPath);
                    }
                } else {
                    imagegif($im, $localPath);
                }

                $this->logger->debug('DownloadImages: Re-creating gif');
                break;
            case 'jpeg':
            case 'jpg':
                imagejpeg($im, $localPath, self::REGENERATE_PICTURES_QUALITY);
                $this->logger->debug('DownloadImages: Re-creating jpg');
                break;
            case 'png':
                imagealphablending($im, false);
                imagesavealpha($im, true);
                imagepng($im, $localPath, (int) ceil(self::REGENERATE_PICTURES_QUALITY / 100 * 9));
                $this->logger->debug('DownloadImages: Re-creating png');
                break;
            case 'webp':
                imagewebp($im, $localPath, self::REGENERATE_PICTURES_QUALITY);
                $this->logger->debug('DownloadImages: Re-creating webp');
        }

        imagedestroy($im);

        return $urlPath;
    }

    /**
     * Remove all images for the given entry id.
     *
     * @param int $entryId ID of the entry
     */
    public function removeImages($entryId): void
    {
        $relativePath = $this->getRelativePath($entryId);
        $folderPath = $this->baseFolder . '/' . $relativePath;

        $finder = new Finder();
        $finder
            ->files()
            ->ignoreDotFiles(true)
            ->in($folderPath);

        foreach ($finder as $file) {
            @unlink($file->getRealPath());
        }

        @rmdir($folderPath);
    }

    /**
     * Generate the folder where we are going to save images based on the entry url.
     *
     * @param int  $entryId      ID of the entry
     * @param bool $createFolder Should we create the folder for the given id?
     *
     * @return string
     */
    public function getRelativePath($entryId, $createFolder = true)
    {
        $hashId = hash('crc32', (string) $entryId);
        $relativePath = $hashId[0] . '/' . $hashId[1] . '/' . $hashId;
        $folderPath = $this->baseFolder . '/' . $relativePath;

        if (!file_exists($folderPath) && $createFolder) {
            mkdir($folderPath, 0777, true);
        }

        $this->logger->debug('DownloadImages: Folder used for that Entry id', ['folder' => $folderPath, 'entryId' => $entryId]);

        return $relativePath;
    }

    /**
     * Get images urls from the srcset image attribute.
     *
     * @return array An array of urls
     */
    private static function getSrcsetUrls(Crawler $imagesCrawler)
    {
        $urls = [];
        $iterator = $imagesCrawler->getIterator();

        while ($iterator->valid()) {
            $node = $iterator->current();
            \assert($node instanceof \DOMElement);

            $srcsetAttribute = $node->getAttribute('srcset');

            if ('' !== $srcsetAttribute) {
                // Couldn't start with " OR ' OR a white space
                // Could be one or more white space
                // Must be one or more digits followed by w OR x
                $pattern = "/(?:[^\"'\s]+\s*(?:\d+[wx])+)/";
                preg_match_all($pattern, $srcsetAttribute, $matches);

                $srcset = \call_user_func_array('array_merge', $matches);
                $srcsetUrls = array_map(static fn ($src) => trim(explode(' ', (string) $src, 2)[0]), $srcset);
                $urls = array_merge($srcsetUrls, $urls);
            }

            $iterator->next();
        }

        return $urls;
    }

    /**
     * Setup base folder where all images are going to be saved.
     */
    private function setFolder(): void
    {
        // if folder doesn't exist, attempt to create one and store the folder name in property $folder
        if (!file_exists($this->baseFolder)) {
            mkdir($this->baseFolder, 0755, true);
        }
    }

    /**
     * Make an $url absolute based on the $base.
     *
     * @see Graby->makeAbsoluteStr
     *
     * @param string $base Base url
     * @param string $url  Url to make it absolute
     *
     * @return false|string
     */
    private function getAbsoluteLink($base, $url)
    {
        if (preg_match('!^https?://!i', $url)) {
            // already absolute
            return $url;
        }

        $base = new Uri($base);

        // in case the url has no scheme & host
        if ('' === $base->getAuthority() || '' === $base->getScheme()) {
            $this->logger->error('DownloadImages: Can not make an absolute link', ['base' => $base, 'url' => $url]);

            return false;
        }

        return (string) UriResolver::resolve($base, new Uri($url));
    }

    /**
     * Retrieve and validate the extension from the response of the url of the image.
     *
     * @param ResponseInterface $res       Http Response
     * @param string            $imagePath Path from the src image from the content (used for log only)
     *
     * @return string|false Extension name or false if validation failed
     */
    private function getExtensionFromResponse(ResponseInterface $res, $imagePath)
    {
        if (200 !== $res->getStatusCode()) {
            return false;
        }

        $ext = current($this->mimeTypes->getExtensions(current($res->getHeaders()['content-type'] ?? [])));
        $this->logger->debug('DownloadImages: Checking extension', ['ext' => $ext, 'header' => $res->getHeaders()['content-type'] ?? []]);

        // ok header doesn't have the extension, try a different way
        if (empty($ext)) {
            $types = [
                'jpeg' => "\xFF\xD8\xFF",
                'gif' => 'GIF',
                'png' => "\x89\x50\x4e\x47\x0d\x0a",
                'webp' => "\x52\x49\x46\x46",
            ];
            $bytes = substr($res->getContent(), 0, 8);

            foreach ($types as $type => $header) {
                if (str_starts_with($bytes, $header)) {
                    $ext = $type;
                    break;
                }
            }

            $this->logger->debug('DownloadImages: Checking extension (alternative)', ['ext' => $ext]);
        }

        if (!\in_array($ext, ['jpeg', 'jpg', 'gif', 'png', 'webp', 'svg'], true)) {
            $this->logger->error('DownloadImages: Processed image with not allowed extension. Skipping: ' . $imagePath);

            return false;
        }

        return $ext;
    }
}
