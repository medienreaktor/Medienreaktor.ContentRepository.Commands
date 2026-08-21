<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Xml;

use Medienreaktor\ContentRepository\Commands\Xml\ManifestXmlParser;
use PHPUnit\Framework\TestCase;

final class ManifestXmlParserTest extends TestCase
{
    private ManifestXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ManifestXmlParser();
    }

    public function testAManifestIsReadWithItsAssetsAndSite(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            assets: '<crm:asset id="hero" href="images/hero.png" title="Hero"/>',
            content: '<Acme.Site:Content.Hero image="hero"/>'
        ));

        self::assertCount(1, $manifest->assets);
        self::assertSame('hero', $manifest->assets[0]->id);
        self::assertSame('images/hero.png', $manifest->assets[0]->href);
        self::assertSame('Hero', $manifest->assets[0]->title);

        $site = $manifest->site;

        self::assertNotNull($site);
        self::assertSame('site', $site->siteNodeName);
        self::assertSame('default', $site->contentRepositoryId);
        self::assertSame(['language' => 'de'], $site->dimensionSpacePoint);

        self::assertCount(1, $site->pages);
        self::assertSame('/', $site->pages[0]->path);
        self::assertSame('Acme.Site:Document.Page', $site->pages[0]->document->nodeTypeName);
        self::assertSame('Acme.Site:Content.Hero', $site->pages[0]->document->children[0]->nodeTypeName);
        self::assertSame(['image' => 'hero'], $site->pages[0]->document->children[0]->properties);
    }

    /**
     * The media library is global, so a manifest that only fills it is a legitimate thing to write.
     */
    public function testAManifestMayCarryAssetsAndNoSite(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest">
          <crm:assets>
            <crm:asset id="hero" href="images/hero.png"/>
          </crm:assets>
        </crm:manifest>
        XML;

        $manifest = $this->parser->parse($xml);

        self::assertNull($manifest->site);
        self::assertCount(1, $manifest->assets);
    }

    public function testAManifestWithNeitherAssetsNorASiteIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('describes neither assets nor a site');

        $this->parser->parse(
            '<?xml version="1.0"?><crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"/>'
        );
    }

    public function testASecondAssetsBlockIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest">
          <crm:assets><crm:asset id="a" href="a.png"/></crm:assets>
          <crm:assets><crm:asset id="b" href="b.png"/></crm:assets>
        </crm:manifest>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('takes one <assets> block');

        $this->parser->parse($xml);
    }

    public function testASecondSiteIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:site name="one"><crm:page path="/"><Acme.Site:Document.Page/></crm:page></crm:site>
          <crm:site name="two"><crm:page path="/"><Acme.Site:Document.Page/></crm:page></crm:site>
        </crm:manifest>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('describes one <site>');

        $this->parser->parse($xml);
    }

    /**
     * The package key comes out of the namespace URI, so a file that spells the prefix differently
     * is the same document — which is what XML says it is, and what lets a formatter rewrite one.
     */
    public function testThePrefixIsCosmeticAndTheUriIsThePackageKey(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:ns0="Acme.Site">
          <crm:site name="site">
            <crm:page path="/"><ns0:Document.Page/></crm:page>
          </crm:site>
        </crm:manifest>
        XML;

        self::assertSame(
            'Acme.Site:Document.Page',
            $this->parser->parse($xml)->site?->pages[0]->document->nodeTypeName
        );
    }

    public function testANamespaceThatIsNotAPackageKeyIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:x="https://acme.example/something-else">
          <crm:site name="site">
            <crm:page path="/"><x:Document.Page/></crm:page>
          </crm:site>
        </crm:manifest>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a package key');

        $this->parser->parse($xml);
    }

    /**
     * A document has to be a node, and an unqualified element is a property — so the one place a
     * property cannot stand is where a document belongs.
     */
    public function testAnUnqualifiedDocumentElementIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest">
          <crm:site name="site">
            <crm:page path="/"><Document.Page/></crm:page>
          </crm:site>
        </crm:manifest>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is in no namespace, so it reads as a property rather than a node');

        $this->parser->parse($xml);
    }

    /**
     * A property is an attribute or an unqualified element, and both arrive in the same place.
     */
    public function testAPropertyElementAndAnAttributeAreEquivalent(): void
    {
        $asAttribute = $this->parser->parse($this->manifest(content: '<Acme.Site:Content.Text text="plain"/>'));
        $asElement = $this->parser->parse($this->manifest(content: '<Acme.Site:Content.Text><text>plain</text></Acme.Site:Content.Text>'));

        self::assertSame(
            $asAttribute->site?->pages[0]->document->children[0]->properties,
            $asElement->site?->pages[0]->document->children[0]->properties
        );
    }

    /**
     * The point of the element form: markup survives as written, without the escaping an attribute
     * would need.
     */
    public function testMarkupInsideAPropertyElementIsKept(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            content: '<Acme.Site:Content.Hero><title>Example <span class="hl">headline.</span></title></Acme.Site:Content.Hero>'
        ));

        self::assertSame(
            'Example <span class="hl">headline.</span>',
            $manifest->site?->pages[0]->document->children[0]->properties['title']
        );
    }

    public function testIndentationAroundAPropertyElementIsNotPartOfTheValue(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            content: "<Acme.Site:Content.Text>\n      <text>\n        <p>Copy.</p>\n      </text>\n    </Acme.Site:Content.Text>"
        ));

        self::assertSame('<p>Copy.</p>', $manifest->site?->pages[0]->document->children[0]->properties['text']);
    }

    /**
     * A silent winner is how an edit gets ignored, so setting a property twice stops the import.
     */
    public function testAPropertySetTwiceIsRejectedRatherThanResolved(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the property "title" is already set by the attribute on line');

        $this->parser->parse($this->manifest(
            content: '<Acme.Site:Content.Hero title="one"><title>two</title></Acme.Site:Content.Hero>'
        ));
    }

    public function testTextContentIsRejectedBecauseItWouldBeDropped(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has text content ("stray copy")');

        $this->parser->parse($this->manifest(content: '<Acme.Site:Content.Text>stray copy</Acme.Site:Content.Text>'));
    }

    public function testATetheredNameIsReadFromTheManifestNamespace(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            content: '<Acme.Site:Content.Columns><Neos.Neos:ContentCollection crm:name="column1"/></Acme.Site:Content.Columns>'
        ));

        $columns = $manifest->site?->pages[0]->document->children[0];

        self::assertNotNull($columns);
        self::assertSame('column1', $columns->children[0]->tetheredName);
        self::assertSame('Neos.Neos:ContentCollection', $columns->children[0]->nodeTypeName);
    }

    public function testAnUnknownManifestAttributeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not take the manifest attribute "mode"');

        $this->parser->parse($this->manifest(content: '<Acme.Site:Content.Hero crm:mode="replace"/>'));
    }

    public function testAnAssetIdUsedTwiceIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the asset id "hero" is already used on line');

        $this->parser->parse($this->manifest(
            assets: '<crm:asset id="hero" href="a.png"/><crm:asset id="hero" href="b.png"/>',
            content: '<Acme.Site:Content.Hero/>'
        ));
    }

    public function testAnAssetWithoutAnHrefIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('an <asset> needs both an "id" and an "href" attribute');

        $this->parser->parse($this->manifest(assets: '<crm:asset id="hero"/>', content: '<Acme.Site:Content.Hero/>'));
    }

    public function testAPageHoldsExactlyOneDocument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a <page> holds exactly one document element, got 2');

        $this->parser->parse($this->manifestWithPage('<crm:page path="/"><Acme.Site:Document.Page/><Acme.Site:Document.Page/></crm:page>'));
    }

    public function testAPageNeedsAPath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a <page> needs a "path" attribute');

        $this->parser->parse($this->manifestWithPage('<crm:page><Acme.Site:Document.Page/></crm:page>'));
    }

    public function testASiteNeedsAName(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs a "name" attribute');

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:site><crm:page path="/"><Acme.Site:Document.Page/></crm:page></crm:site>
        </crm:manifest>
        XML;

        $this->parser->parse($xml);
    }

    public function testASiteWithoutPagesIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('describes no page');

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest">
          <crm:site name="site"/>
        </crm:manifest>
        XML;

        $this->parser->parse($xml);
    }

    public function testTheRootHasToBeAManifest(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has to have a <manifest> root element');

        $this->parser->parse('<?xml version="1.0"?><manifest name="site"/>');
    }

    public function testMalformedXmlIsReportedWithItsLine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not well-formed');

        $this->parser->parse(
            "<?xml version=\"1.0\"?>\n<crm:manifest xmlns:crm=\"https://medienreaktor.de/ns/contentrepository-commands/manifest\">\n<unclosed>\n</crm:manifest>"
        );
    }

    public function testADimensionIsReadAsNameValuePairs(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:site name="site" dimension="language=de, market=eu">
            <crm:page path="/"><Acme.Site:Document.Page/></crm:page>
          </crm:site>
        </crm:manifest>
        XML;

        self::assertSame(
            ['language' => 'de', 'market' => 'eu'],
            $this->parser->parse($xml)->site?->dimensionSpacePoint
        );
    }

    public function testAnUnreadableDimensionIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:site name="site" dimension="de">
            <crm:page path="/"><Acme.Site:Document.Page/></crm:page>
          </crm:site>
        </crm:manifest>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has to be written as name=value');

        $this->parser->parse($xml);
    }

    public function testACommentIsNotContent(): void
    {
        $manifest = $this->parser->parse($this->manifest(content: '<!-- why this block is here --><Acme.Site:Content.Hero/>'));

        self::assertCount(1, $manifest->site?->pages[0]->document->children ?? []);
    }

    /**
     * Nesting is how the tree is written, so it has to survive to arbitrary depth.
     */
    public function testNestingIsPreserved(): void
    {
        $manifest = $this->parser->parse($this->manifest(
            content: '<Acme.Site:Content.Grid><Acme.Site:Content.Grid.Cell><Acme.Site:Content.Teaser number="01"/></Acme.Site:Content.Grid.Cell></Acme.Site:Content.Grid>'
        ));

        $grid = $manifest->site?->pages[0]->document->children[0];

        self::assertNotNull($grid);

        $cell = $grid->children[0];

        self::assertSame('Acme.Site:Content.Grid', $grid->nodeTypeName);
        self::assertSame('Acme.Site:Content.Grid.Cell', $cell->nodeTypeName);
        self::assertSame('Acme.Site:Content.Teaser', $cell->children[0]->nodeTypeName);
        self::assertSame(['number' => '01'], $cell->children[0]->properties);
    }

    private function manifest(string $content, string $assets = ''): string
    {
        return $this->wrap(
            ($assets === '' ? '' : sprintf('<crm:assets>%s</crm:assets>', $assets))
            . sprintf(
                '<crm:site name="site" contentRepository="default" dimension="language=de">
                   <crm:page path="/">
                     <Acme.Site:Document.Page>%s</Acme.Site:Document.Page>
                   </crm:page>
                 </crm:site>',
                $content
            )
        );
    }

    private function manifestWithPage(string $page): string
    {
        return $this->wrap(sprintf('<crm:site name="site">%s</crm:site>', $page));
    }

    private function wrap(string $body): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
            <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                          xmlns:Acme.Site="Acme.Site"
                          xmlns:Neos.Neos="Neos.Neos">
              %s
            </crm:manifest>',
            $body
        );
    }
}
