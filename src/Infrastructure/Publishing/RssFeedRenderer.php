<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use DOMDocument;
use DOMElement;

/**
 * Renders an Apple Podcasts / Spotify compatible RSS 2.0 feed with iTunes tags.
 */
final class RssFeedRenderer
{
    private const ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    private const ATOM = 'http://www.w3.org/2005/Atom';
    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * @param array<string, mixed>       $config
     * @param list<array<string, mixed>> $items
     */
    public function render(array $config, array $items, string $feedUrl): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', self::ITUNES);
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', self::ATOM);
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:content', self::CONTENT);
        $doc->appendChild($rss);
        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $title = (string) ($config['title'] ?? 'Podcast');
        $description = (string) ($config['description'] ?? $title);
        $author = (string) ($config['author'] ?? '');
        $explicit = in_array($config['explicit'] ?? '0', ['1', 1, true, 'true'], true);

        $this->text($doc, $channel, 'title', $title);
        $this->text($doc, $channel, 'link', (string) ($config['website'] ?? $feedUrl));
        $this->text($doc, $channel, 'description', $description);
        $this->text($doc, $channel, 'language', (string) ($config['language'] ?? 'de'));
        $this->text($doc, $channel, 'generator', 'audiEdiPipe');
        $this->text($doc, $channel, 'lastBuildDate', gmdate(DATE_RSS));
        $atomLink = $doc->createElementNS(self::ATOM, 'atom:link');
        $atomLink->setAttribute('href', $feedUrl);
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atomLink);

        $this->text($doc, $channel, 'itunes:author', $author, self::ITUNES);
        $this->text($doc, $channel, 'itunes:summary', $description, self::ITUNES);
        $this->text($doc, $channel, 'itunes:explicit', $explicit ? 'true' : 'false', self::ITUNES);
        $this->text($doc, $channel, 'itunes:type', 'episodic', self::ITUNES);
        $owner = $doc->createElementNS(self::ITUNES, 'itunes:owner');
        $this->text($doc, $owner, 'itunes:name', $author, self::ITUNES);
        $this->text($doc, $owner, 'itunes:email', (string) ($config['owner_email'] ?? ''), self::ITUNES);
        $channel->appendChild($owner);
        $image = (string) ($config['image_url'] ?? '');
        if ($image !== '') {
            $img = $doc->createElementNS(self::ITUNES, 'itunes:image');
            $img->setAttribute('href', $image);
            $channel->appendChild($img);
        }
        $category = (string) ($config['category'] ?? '');
        if ($category !== '') {
            $cat = $doc->createElementNS(self::ITUNES, 'itunes:category');
            $cat->setAttribute('text', $category);
            $channel->appendChild($cat);
        }

        foreach ($items as $item) {
            $node = $doc->createElement('item');
            $this->text($doc, $node, 'title', (string) $item['title']);
            $this->text($doc, $node, 'description', (string) ($item['description'] ?? ''));
            $guid = $this->text($doc, $node, 'guid', (string) $item['guid']);
            $guid->setAttribute('isPermaLink', 'false');
            $published = strtotime((string) $item['published_at'] . ' UTC');
            $this->text($doc, $node, 'pubDate', gmdate(DATE_RSS, $published === false ? time() : $published));
            $enclosure = $doc->createElement('enclosure');
            $enclosure->setAttribute('url', (string) $item['file_url']);
            $enclosure->setAttribute('length', (string) $item['file_size']);
            $enclosure->setAttribute('type', (string) $item['mime_type']);
            $node->appendChild($enclosure);
            $this->text($doc, $node, 'itunes:duration', (string) (int) $item['duration'], self::ITUNES);
            $this->text($doc, $node, 'itunes:explicit', $explicit ? 'true' : 'false', self::ITUNES);
            if (($item['image_url'] ?? '') !== '' && $item['image_url'] !== null) {
                $img = $doc->createElementNS(self::ITUNES, 'itunes:image');
                $img->setAttribute('href', (string) $item['image_url']);
                $node->appendChild($img);
            }
            $channel->appendChild($node);
        }

        return (string) $doc->saveXML();
    }

    private function text(DOMDocument $doc, DOMElement $parent, string $name, string $value, ?string $ns = null): DOMElement
    {
        $element = $ns === null ? $doc->createElement($name) : $doc->createElementNS($ns, $name);
        $element->appendChild($doc->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }
}
