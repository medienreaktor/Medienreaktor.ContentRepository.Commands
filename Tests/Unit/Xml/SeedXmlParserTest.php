<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Xml;

use Medienreaktor\ContentRepository\Commands\Xml\SeedXmlParser;
use PHPUnit\Framework\TestCase;

final class SeedXmlParserTest extends TestCase
{
    private SeedXmlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SeedXmlParser();
    }

    public function testASiteIsReadWithItsPagesAndManifest(): void
    {
        $site = $this->parser->parse($this->seed(
            manifest: '<seed:asset id="hero" href="images/hero.png" title="Hero"/>',
            content: '<Acme.Site:Content.Hero image="hero"/>'
        ));

        self::assertSame('site', $site->siteNodeName);
        self::assertSame('default', $site->contentRepositoryId);
        self::assertSame(['language' => 'de'], $site->dimensionSpacePoint);

        self::assertCount(1, $site->assets);
        self::assertSame('hero', $site->assets[0]->id);
        self::assertSame('images/hero.png', $site->assets[0]->href);
        self::assertSame('Hero', $site->assets[0]->title);

        self::assertCount(1, $site->pages);
        self::assertSame('/', $site->pages[0]->path);
        self::assertSame('Acme.Site:Document.Page', $site->pages[0]->document->nodeTypeName);
        self::assertSame('Acme.Site:Content.Hero', $site->pages[0]->document->children[0]->nodeTypeName);
        self::assertSame(['image' => 'hero'], $site->pages[0]->document->children[0]->properties);
    }

    /**
     * The package key comes out of the namespace URI, so a file that spells the prefix differently
     * is the same document — which is what XML says it is, and what lets a formatter rewrite one.
     */
    public function testThePrefixIsCosmeticAndTheUriCarriesThePackageKey(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:ns0="https://acme.example/ns/nodetypes/Acme.Site"
                   name="site">
          <seed:page path="/"><ns0:Document.Page/></seed:page>
        </seed:site>
        XML;

        self::assertSame('Acme.Site:Document.Page', $this->parser->parse($xml)->pages[0]->document->nodeTypeName);
    }

    public function testANamespaceThatIsNotANodeTypeNamespaceIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:x="https://acme.example/something-else"
                   name="site">
          <seed:page path="/"><x:Document.Page/></seed:page>
        </seed:site>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('it has to end in /ns/nodetypes/<PackageKey>');

        $this->parser->parse($xml);
    }

    /**
     * A property is an attribute or a prop: element, and both arrive in the same place.
     */
    public function testAPropertyElementAndAnAttributeAreEquivalent(): void
    {
        $asAttribute = $this->parser->parse($this->seed(content: '<Acme.Site:Content.Text text="plain"/>'));
        $asElement = $this->parser->parse($this->seed(content: '<Acme.Site:Content.Text><prop:text>plain</prop:text></Acme.Site:Content.Text>'));

        self::assertSame(
            $asAttribute->pages[0]->document->children[0]->properties,
            $asElement->pages[0]->document->children[0]->properties
        );
    }

    /**
     * The point of the prop: element: markup survives as written, without the escaping an attribute
     * would need.
     */
    public function testMarkupInsideAPropertyElementIsKept(): void
    {
        $site = $this->parser->parse($this->seed(
            content: '<Acme.Site:Content.Hero><prop:title>Example <span class="hl">headline.</span></prop:title></Acme.Site:Content.Hero>'
        ));

        self::assertSame(
            'Example <span class="hl">headline.</span>',
            $site->pages[0]->document->children[0]->properties['title']
        );
    }

    public function testIndentationAroundAPropertyElementIsNotPartOfTheValue(): void
    {
        $site = $this->parser->parse($this->seed(
            content: "<Acme.Site:Content.Text>\n      <prop:text>\n        <p>Copy.</p>\n      </prop:text>\n    </Acme.Site:Content.Text>"
        ));

        self::assertSame('<p>Copy.</p>', $site->pages[0]->document->children[0]->properties['text']);
    }

    /**
     * A silent winner is how an edit gets ignored, so setting a property twice stops the import.
     */
    public function testAPropertySetTwiceIsRejectedRatherThanResolved(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the property "title" is already set by the attribute on line');

        $this->parser->parse($this->seed(
            content: '<Acme.Site:Content.Hero title="one"><prop:title>two</prop:title></Acme.Site:Content.Hero>'
        ));
    }

    public function testTextContentIsRejectedBecauseItWouldBeDropped(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has text content ("stray copy")');

        $this->parser->parse($this->seed(content: '<Acme.Site:Content.Text>stray copy</Acme.Site:Content.Text>'));
    }

    public function testATetheredNameIsReadFromTheSeedNamespace(): void
    {
        $site = $this->parser->parse($this->seed(
            content: '<Acme.Site:Content.Columns><Neos.Neos:ContentCollection seed:name="column1"/></Acme.Site:Content.Columns>'
        ));

        $columns = $site->pages[0]->document->children[0];

        self::assertSame('column1', $columns->children[0]->tetheredName);
        self::assertSame('Neos.Neos:ContentCollection', $columns->children[0]->nodeTypeName);
    }

    public function testAnUnknownSeedAttributeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not take the seed attribute "mode"');

        $this->parser->parse($this->seed(content: '<Acme.Site:Content.Hero seed:mode="replace"/>'));
    }

    public function testAnAssetIdUsedTwiceIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the asset id "hero" is already used on line');

        $this->parser->parse($this->seed(
            manifest: '<seed:asset id="hero" href="a.png"/><seed:asset id="hero" href="b.png"/>',
            content: '<Acme.Site:Content.Hero/>'
        ));
    }

    public function testAnAssetWithoutAnHrefIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('an <asset> needs both an "id" and an "href" attribute');

        $this->parser->parse($this->seed(manifest: '<seed:asset id="hero"/>', content: '<Acme.Site:Content.Hero/>'));
    }

    public function testAPageHoldsExactlyOneDocument(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
                   name="site">
          <seed:page path="/"><Acme.Site:Document.Page/><Acme.Site:Document.Page/></seed:page>
        </seed:site>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a <page> holds exactly one document element, got 2');

        $this->parser->parse($xml);
    }

    public function testAPageNeedsAPath(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
                   name="site">
          <seed:page><Acme.Site:Document.Page/></seed:page>
        </seed:site>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a <page> needs a "path" attribute');

        $this->parser->parse($xml);
    }

    public function testASiteNeedsAName(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"/>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs a "name" attribute');

        $this->parser->parse($xml);
    }

    public function testASiteWithoutPagesIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0" name="site"/>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('describes no page');

        $this->parser->parse($xml);
    }

    public function testTheRootHasToBeASeedSite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has to have a <site> root element');

        $this->parser->parse('<?xml version="1.0"?><site name="site"/>');
    }

    public function testMalformedXmlIsReportedWithItsLine(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not well-formed');

        $this->parser->parse("<?xml version=\"1.0\"?>\n<seed:site xmlns:seed=\"https://medienreaktor.de/ns/neos-seed/1.0\">\n<unclosed>\n</seed:site>");
    }

    public function testADimensionIsReadAsNameValuePairs(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
                   name="site" dimension="language=de, market=eu">
          <seed:page path="/"><Acme.Site:Document.Page/></seed:page>
        </seed:site>
        XML;

        self::assertSame(['language' => 'de', 'market' => 'eu'], $this->parser->parse($xml)->dimensionSpacePoint);
    }

    public function testAnUnreadableDimensionIsRejected(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                   xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
                   name="site" dimension="de">
          <seed:page path="/"><Acme.Site:Document.Page/></seed:page>
        </seed:site>
        XML;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has to be written as name=value');

        $this->parser->parse($xml);
    }

    public function testACommentIsNotContent(): void
    {
        $site = $this->parser->parse($this->seed(content: '<!-- why this block is here --><Acme.Site:Content.Hero/>'));

        self::assertCount(1, $site->pages[0]->document->children);
    }

    /**
     * Nesting is how the tree is written, so it has to survive to arbitrary depth.
     */
    public function testNestingIsPreserved(): void
    {
        $site = $this->parser->parse($this->seed(
            content: '<Acme.Site:Content.Grid><Acme.Site:Content.Grid.Cell><Acme.Site:Content.Teaser number="01"/></Acme.Site:Content.Grid.Cell></Acme.Site:Content.Grid>'
        ));

        $grid = $site->pages[0]->document->children[0];
        $cell = $grid->children[0];

        self::assertSame('Acme.Site:Content.Grid', $grid->nodeTypeName);
        self::assertSame('Acme.Site:Content.Grid.Cell', $cell->nodeTypeName);
        self::assertSame('Acme.Site:Content.Teaser', $cell->children[0]->nodeTypeName);
        self::assertSame(['number' => '01'], $cell->children[0]->properties);
    }

    private function seed(string $content, string $manifest = ''): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>
            <seed:site xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
                       xmlns:prop="https://medienreaktor.de/ns/neos-seed/1.0/property"
                       xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
                       xmlns:Neos.Neos="https://neos.io/ns/nodetypes/Neos.Neos"
                       name="site" contentRepository="default" dimension="language=de">
              %s
              <seed:page path="/">
                <Acme.Site:Document.Page>%s</Acme.Site:Document.Page>
              </seed:page>
            </seed:site>',
            $manifest === '' ? '' : sprintf('<seed:manifest>%s</seed:manifest>', $manifest),
            $content
        );
    }
}
