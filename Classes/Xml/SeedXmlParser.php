<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

use Neos\Flow\Annotations as Flow;

/**
 * Reads a seed XML file into {@see ParsedSite}, without touching the Content Repository.
 *
 * The format describes the content tree it wants to exist, with node types as element names:
 *
 *     <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
 *                xmlns:prop="https://medienreaktor.de/ns/neos-seed/1.0/property"
 *                xmlns:Medienreaktor.Site="https://medienreaktor.de/ns/nodetypes/Medienreaktor.Site"
 *                name="site" contentRepository="default" dimension="language=de">
 *       <seed:manifest>
 *         <seed:asset id="hero" href="images/hero.png" title="Hero"/>
 *       </seed:manifest>
 *       <seed:page path="/">
 *         <Medienreaktor.Site:Document.Page.Homepage>
 *           <Medienreaktor.Site:Content.Hero image="hero" alternativeText="Rectangle 85">
 *             <prop:title>We power <span class="highlight">freedom.</span></prop:title>
 *           </Medienreaktor.Site:Content.Hero>
 *         </Medienreaktor.Site:Document.Page.Homepage>
 *       </seed:page>
 *     </seed:site>
 *
 * **A node type name is a QName.** A QName holds at most one colon, and a node type name holds
 * exactly one — so the package key becomes the namespace prefix and the rest the local name, which
 * makes the element name read as the node type name does everywhere else. Dots are legal in an
 * NCName, so both halves survive intact.
 *
 * **The namespace URI carries the package key, not the prefix.** XML treats a prefix as arbitrary
 * and reassignable: a tool may rewrite `Medienreaktor.Site:` to `ns0:` and mean the same document.
 * So the package key is read out of the URI, which has to end in `/ns/nodetypes/<PackageKey>`, and
 * the prefix is only a choice the file makes for its reader. Writing the prefix to match the package
 * key is the convention, not a requirement.
 *
 * **A property is an attribute or a prop: element, interchangeably.** Both end up in the same place.
 * An attribute suits a short scalar; a prop: element holds markup literally, which an attribute can
 * only do escaped beyond legibility. Giving the same property both ways is an error rather than a
 * precedence rule, because a silent winner is how an edit gets ignored.
 *
 * What this parser does *not* do is check any of it against a node type: whether the node type
 * exists, whether a property is declared, whether a child is allowed. That needs the Content
 * Repository and belongs to the importer. This stage is about the file being well-formed and
 * unambiguous, which is worth failing on without a database.
 */
#[Flow\Scope('singleton')]
final class SeedXmlParser
{
    public const string SEED_NAMESPACE = 'https://medienreaktor.de/ns/neos-seed/1.0';
    public const string PROPERTY_NAMESPACE = 'https://medienreaktor.de/ns/neos-seed/1.0/property';

    /**
     * The tail every node type namespace URI has to end with, capturing the package key.
     *
     * Anchored at a path segment so that the host is free: Neos' own types are declared under
     * neos.io and a site package under its vendor's domain, and neither should have to pretend to
     * live somewhere else.
     */
    private const string NODE_TYPE_NAMESPACE_PATTERN = '#^https?://[^\s]+/ns/nodetypes/(?<packageKey>[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)$#';

    /**
     * @throws \RuntimeException if the file cannot be read, is not well-formed, or is ambiguous
     */
    public function parseFile(string $file): ParsedSite
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(sprintf('The seed file "%s" does not exist or cannot be read.', $file), 1787097620);
        }

        $xml = file_get_contents($file);

        if ($xml === false) {
            throw new \RuntimeException(sprintf('The seed file "%s" could not be read.', $file), 1787097621);
        }

        return $this->parse($xml);
    }

    /**
     * @throws \RuntimeException
     */
    public function parse(string $xml): ParsedSite
    {
        return $this->parseDocument($this->load($xml));
    }

    /**
     * libxml collects errors on a global stack, so the previous state is saved and restored rather
     * than cleared — this runs inside a Flow process that has its own uses for it.
     */
    private function load(string $xml): \DOMDocument
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();

            // LIBXML_NOCDATA folds CDATA into text, so that a property written either way arrives
            // the same. LIBXML_NONET refuses to fetch anything the document points at.
            $loaded = $document->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET);
            $errors = libxml_get_errors();

            if ($loaded === false) {
                throw new \RuntimeException(
                    sprintf('The seed XML is not well-formed: %s', self::describe($errors)),
                    1787097622
                );
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function parseDocument(\DOMDocument $document): ParsedSite
    {
        $root = $document->documentElement;

        if ($root === null || $root->namespaceURI !== self::SEED_NAMESPACE || $root->localName !== 'site') {
            throw new \RuntimeException(
                sprintf('A seed file has to have a <site> root element in the namespace %s.', self::SEED_NAMESPACE),
                1787097623
            );
        }

        $siteNodeName = $root->getAttribute('name');

        if ($siteNodeName === '') {
            throw new \RuntimeException('The <site> element needs a "name" attribute naming the site node.', 1787097624);
        }

        $contentRepositoryId = $root->getAttribute('contentRepository');
        $dimension = $root->getAttribute('dimension');

        $assets = [];
        $pages = [];

        foreach (self::elementChildrenOf($root) as $child) {
            if ($child->namespaceURI !== self::SEED_NAMESPACE) {
                throw new \RuntimeException(
                    sprintf('Line %d: <site> takes <manifest> and <page> children, got <%s>.', $child->getLineNo(), $child->nodeName),
                    1787097625
                );
            }

            match ($child->localName) {
                'manifest' => $assets = [...$assets, ...$this->parseManifest($child)],
                'page' => $pages[] = $this->parsePage($child),
                default => throw new \RuntimeException(
                    sprintf('Line %d: <site> takes <manifest> and <page> children, got <%s>.', $child->getLineNo(), $child->nodeName),
                    1787097626
                ),
            };
        }

        if ($pages === []) {
            throw new \RuntimeException('The seed file describes no page, so there is nothing to import.', 1787097627);
        }

        return new ParsedSite(
            $siteNodeName,
            $contentRepositoryId === '' ? 'default' : $contentRepositoryId,
            self::parseDimension($dimension),
            $assets,
            $pages,
        );
    }

    /**
     * @return array<int,ParsedAsset>
     */
    private function parseManifest(\DOMElement $manifest): array
    {
        $assets = [];
        $seenIds = [];

        foreach (self::elementChildrenOf($manifest) as $child) {
            if ($child->namespaceURI !== self::SEED_NAMESPACE || $child->localName !== 'asset') {
                throw new \RuntimeException(
                    sprintf('Line %d: <manifest> takes <asset> children, got <%s>.', $child->getLineNo(), $child->nodeName),
                    1787097628
                );
            }

            $id = $child->getAttribute('id');
            $href = $child->getAttribute('href');

            if ($id === '' || $href === '') {
                throw new \RuntimeException(
                    sprintf('Line %d: an <asset> needs both an "id" and an "href" attribute.', $child->getLineNo()),
                    1787097629
                );
            }

            // Two assets under one id would make a reference to it mean whichever came last, and
            // the content that reads wrong is somewhere else entirely.
            if (isset($seenIds[$id])) {
                throw new \RuntimeException(
                    sprintf('Line %d: the asset id "%s" is already used on line %d.', $child->getLineNo(), $id, $seenIds[$id]),
                    1787097630
                );
            }

            $seenIds[$id] = $child->getLineNo();
            $title = $child->getAttribute('title');
            $assets[] = new ParsedAsset($id, $href, $title === '' ? null : $title, $child->getLineNo());
        }

        return $assets;
    }

    private function parsePage(\DOMElement $page): ParsedPage
    {
        $path = $page->getAttribute('path');

        if ($path === '') {
            throw new \RuntimeException(
                sprintf('Line %d: a <page> needs a "path" attribute, "/" for the site node itself.', $page->getLineNo()),
                1787097631
            );
        }

        $children = self::elementChildrenOf($page);

        if (count($children) !== 1) {
            throw new \RuntimeException(
                sprintf('Line %d: a <page> holds exactly one document element, got %d.', $page->getLineNo(), count($children)),
                1787097632
            );
        }

        return new ParsedPage($path, $this->parseNode($children[0]), $page->getLineNo());
    }

    private function parseNode(\DOMElement $element): ParsedNode
    {
        $nodeTypeName = self::nodeTypeNameOf($element);
        $tetheredName = null;
        $properties = [];
        $propertySources = [];

        foreach ($element->attributes ?? [] as $attribute) {
            /** @var \DOMAttr $attribute */
            if ($attribute->namespaceURI === self::SEED_NAMESPACE) {
                if ($attribute->localName !== 'name') {
                    throw new \RuntimeException(
                        sprintf('Line %d: <%s> does not take the seed attribute "%s".', $element->getLineNo(), $element->nodeName, $attribute->localName),
                        1787097633
                    );
                }

                $tetheredName = $attribute->value;
                continue;
            }

            // A namespaced attribute that is neither seed: nor plain is a mistake worth naming: it
            // would otherwise be read as a property under its local name and land somewhere odd.
            if ($attribute->namespaceURI !== null && !self::isXmlnsDeclaration($attribute)) {
                throw new \RuntimeException(
                    sprintf('Line %d: the attribute "%s" on <%s> is in a namespace this format does not use.', $element->getLineNo(), $attribute->nodeName, $element->nodeName),
                    1787097634
                );
            }

            if (self::isXmlnsDeclaration($attribute)) {
                continue;
            }

            $properties[$attribute->localName] = $attribute->value;
            $propertySources[$attribute->localName] = sprintf('the attribute on line %d', $element->getLineNo());
        }

        $children = [];

        foreach (self::elementChildrenOf($element) as $child) {
            if ($child->namespaceURI === self::PROPERTY_NAMESPACE) {
                $propertyName = (string)$child->localName;

                if (isset($propertySources[$propertyName])) {
                    throw new \RuntimeException(
                        sprintf('Line %d: the property "%s" is already set by %s. Set it once, either way.', $child->getLineNo(), $propertyName, $propertySources[$propertyName]),
                        1787097635
                    );
                }

                $properties[$propertyName] = self::innerXmlOf($child);
                $propertySources[$propertyName] = sprintf('the element on line %d', $child->getLineNo());
                continue;
            }

            if ($child->namespaceURI === self::SEED_NAMESPACE) {
                throw new \RuntimeException(
                    sprintf('Line %d: <%s> is not allowed inside a node.', $child->getLineNo(), $child->nodeName),
                    1787097636
                );
            }

            $children[] = $this->parseNode($child);
        }

        self::requireNoLooseText($element);

        return new ParsedNode($nodeTypeName, $tetheredName, $properties, $children, $element->getLineNo());
    }

    /**
     * The node type name an element stands for, from its namespace URI and local name.
     */
    private static function nodeTypeNameOf(\DOMElement $element): string
    {
        if ($element->namespaceURI === null) {
            throw new \RuntimeException(
                sprintf('Line %d: <%s> is in no namespace, so no node type can be resolved for it. Declare the package namespace and use it as the element prefix.', $element->getLineNo(), $element->nodeName),
                1787097637
            );
        }

        if (preg_match(self::NODE_TYPE_NAMESPACE_PATTERN, $element->namespaceURI, $matches) !== 1) {
            throw new \RuntimeException(
                sprintf('Line %d: the namespace "%s" of <%s> is not a node type namespace; it has to end in /ns/nodetypes/<PackageKey>.', $element->getLineNo(), $element->namespaceURI, $element->nodeName),
                1787097638
            );
        }

        return $matches['packageKey'] . ':' . $element->localName;
    }

    /**
     * Text between elements is whitespace in a hand-indented file, and a mistake otherwise —
     * most likely a property value written as element content instead of into a prop: element,
     * where it would be silently dropped.
     *
     * A CDATA section arrives as a DOMText, both because DOMCdataSection extends it and because
     * LIBXML_NOCDATA folds the two together on load.
     */
    private static function requireNoLooseText(\DOMElement $element): void
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMText) {
                continue;
            }

            if (trim($child->textContent) !== '') {
                throw new \RuntimeException(
                    sprintf('Line %d: <%s> has text content ("%s"). A property value belongs in an attribute or a prop: element.', $element->getLineNo(), $element->nodeName, self::shorten(trim($child->textContent))),
                    1787097639
                );
            }
        }
    }

    /**
     * The markup inside a prop: element, serialized as written.
     *
     * Child elements are in no namespace — the seed file declares no default namespace — so they
     * serialize as the plain HTML they are, without a namespace declaration being added.
     */
    private static function innerXmlOf(\DOMElement $element): string
    {
        $document = $element->ownerDocument;
        $inner = '';

        foreach ($element->childNodes as $child) {
            $inner .= $document?->saveXML($child) ?? '';
        }

        // A prop: element written across lines for legibility should not carry the indentation into
        // the property, but inner whitespace is content and stays.
        return trim($inner);
    }

    /**
     * @return array<string,string>
     */
    private static function parseDimension(string $dimension): array
    {
        if (trim($dimension) === '') {
            return [];
        }

        $dimensionSpacePoint = [];

        foreach (explode(',', $dimension) as $pair) {
            if (!str_contains($pair, '=')) {
                throw new \RuntimeException(
                    sprintf('The dimension "%s" has to be written as name=value, separated by commas for more than one.', $dimension),
                    1787097640
                );
            }

            [$name, $value] = explode('=', $pair, 2);
            $dimensionSpacePoint[trim($name)] = trim($value);
        }

        return $dimensionSpacePoint;
    }

    /**
     * @return array<int,\DOMElement>
     */
    private static function elementChildrenOf(\DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * A namespace declaration reaches the attribute list like any other attribute, but declares
     * rather than sets something.
     */
    private static function isXmlnsDeclaration(\DOMAttr $attribute): bool
    {
        return $attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/' || $attribute->nodeName === 'xmlns';
    }

    /**
     * @param array<int,\LibXMLError> $errors
     */
    private static function describe(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $messages[] = sprintf('line %d: %s', $error->line, trim($error->message));
        }

        return $messages === [] ? 'no further detail available' : implode('; ', $messages);
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 39) . '…' : $text;
    }
}
