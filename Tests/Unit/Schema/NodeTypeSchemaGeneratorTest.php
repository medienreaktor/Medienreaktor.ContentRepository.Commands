<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Schema;

use Medienreaktor\ContentRepository\Commands\Schema\NodeTypeSchemaGenerator;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use PHPUnit\Framework\TestCase;

/**
 * The generator is checked by using its output: the schemas are written next to the real
 * manifest.xsd and manifests are validated against them.
 *
 * Asserting on the XSD text would test the serializer; validating a manifest tests what the schema
 * is for. It also exercises the manifest schema itself, and the substitution group that joins the
 * two — which no unit under test owns on its own.
 */
final class NodeTypeSchemaGeneratorTest extends TestCase
{
    private NodeTypeSchemaGenerator $generator;

    /** @var array<string,string> */
    private array $schemas;

    private string $directory;

    protected function setUp(): void
    {
        $this->generator = new NodeTypeSchemaGenerator();
        $this->schemas = $this->generator->generate(
            NodeTypeManager::createFromArrayConfiguration(self::nodeTypes()),
            'manifest.xsd'
        );

        $this->directory = sys_get_temp_dir() . '/crm-schema-' . bin2hex(random_bytes(6));
        mkdir($this->directory);

        foreach ($this->schemas as $name => $schema) {
            file_put_contents($this->directory . '/' . $name, $schema);
        }

        copy(dirname(__DIR__, 3) . '/Resources/Private/Schema/manifest.xsd', $this->directory . '/manifest.xsd');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testOneSchemaIsWrittenPerPackagePlusAnAggregate(): void
    {
        self::assertSame(['Acme.Site.xsd', 'Neos.Neos.xsd', 'all.xsd'], array_keys($this->schemas));
    }

    public function testEverySchemaIsWellFormedAndCompiles(): void
    {
        // Compiling the aggregate pulls in every generated schema and the manifest schema, so a
        // broken reference anywhere fails here.
        self::assertSame([], $this->validate('<crm:manifest xmlns:crm="' . self::NS . '"><crm:assets/></crm:manifest>')['schemaErrors']);
    }

    public function testAValidManifestValidates(): void
    {
        $errors = $this->validate(<<<'XML'
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:assets>
            <crm:asset id="hero" href="hero.png" title="Hero"/>
          </crm:assets>
          <crm:site name="site" contentRepository="default" dimension="language=de">
            <crm:page path="/">
              <Acme.Site:Document.Page.Homepage title="Acme">
                <Acme.Site:Content.Hero image="hero" headingLevel="h1">
                  <title>Example <span class="highlight">headline.</span></title>
                  <showDash>true</showDash>
                </Acme.Site:Content.Hero>
                <Acme.Site:Content.Grid>
                  <Acme.Site:Content.Grid.Cell>
                    <Acme.Site:Content.Teaser number="01"/>
                  </Acme.Site:Content.Grid.Cell>
                </Acme.Site:Content.Grid>
              </Acme.Site:Document.Page.Homepage>
            </crm:page>
          </crm:site>
        </crm:manifest>
        XML);

        self::assertSame([], $errors['documentErrors']);
    }

    /**
     * A document type enrols itself into the manifest schema's substitution group, so <crm:page>
     * accepts it without the manifest schema knowing it exists.
     */
    public function testADocumentTypeIsAcceptedInsideAPageAndAContentTypeIsNot(): void
    {
        self::assertNotSame([], $this->validate(<<<'XML'
        <crm:manifest xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
                      xmlns:Acme.Site="Acme.Site">
          <crm:site name="site"><crm:page path="/"><Acme.Site:Content.Hero/></crm:page></crm:site>
        </crm:manifest>
        XML)['documentErrors']);
    }

    public function testAnUndeclaredPropertyElementIsRejected(): void
    {
        $errors = $this->validate($this->manifest('<Acme.Site:Content.Hero><titel>typo</titel></Acme.Site:Content.Hero>'));

        self::assertStringContainsString('titel', implode(' ', $errors['documentErrors']));
    }

    public function testAWronglyTypedPropertyElementIsRejected(): void
    {
        $errors = $this->validate($this->manifest('<Acme.Site:Content.Hero><showDash>maybe</showDash></Acme.Site:Content.Hero>'));

        self::assertStringContainsString('boolean', implode(' ', $errors['documentErrors']));
    }

    public function testAnOutOfEnumerationAttributeIsRejected(): void
    {
        $errors = $this->validate($this->manifest('<Acme.Site:Content.Hero headingLevel="h9"/>'));

        self::assertStringContainsString('h9', implode(' ', $errors['documentErrors']));
    }

    /**
     * The two ways of writing a property have to accept the same values, so a select box constrains
     * the element form as well as the attribute form.
     */
    public function testAnOutOfEnumerationPropertyElementIsRejectedToo(): void
    {
        self::assertSame([], $this->validate($this->manifest(
            '<Acme.Site:Content.Hero><headingLevel>h2</headingLevel></Acme.Site:Content.Hero>'
        ))['documentErrors']);

        self::assertStringContainsString('h9', implode(' ', $this->validate($this->manifest(
            '<Acme.Site:Content.Hero><headingLevel>h9</headingLevel></Acme.Site:Content.Hero>'
        ))['documentErrors']));
    }

    /**
     * Neos.Neos:Content declares constraints.nodeTypes {'*': false}, inherited by every content type,
     * so a leaf really does take no children — which is what the Neos UI shows.
     */
    public function testALeafContentTypeTakesNoChildNodes(): void
    {
        $errors = $this->validate($this->manifest(
            '<Acme.Site:Content.Teaser><Acme.Site:Content.Hero/></Acme.Site:Content.Teaser>'
        ));

        self::assertNotSame([], $errors['documentErrors']);
    }

    /**
     * A grid constrains its children to cells, so anything else in one is an error rather than a
     * rendering surprise later.
     */
    public function testACollectionHonoursItsOwnConstraints(): void
    {
        self::assertSame([], $this->validate($this->manifest(
            '<Acme.Site:Content.Grid><Acme.Site:Content.Grid.Cell/></Acme.Site:Content.Grid>'
        ))['documentErrors']);

        self::assertNotSame([], $this->validate($this->manifest(
            '<Acme.Site:Content.Grid><Acme.Site:Content.Hero/></Acme.Site:Content.Grid>'
        ))['documentErrors']);
    }

    public function testAPropertyIsWritableAsAnAttributeOrAnElement(): void
    {
        self::assertSame([], $this->validate($this->manifest('<Acme.Site:Content.Hero title="plain"/>'))['documentErrors']);
        self::assertSame([], $this->validate($this->manifest('<Acme.Site:Content.Hero><title>plain</title></Acme.Site:Content.Hero>'))['documentErrors']);
    }

    public function testTheOutputIsOrderedSoThatItDiffsCleanly(): void
    {
        self::assertSame(
            $this->schemas,
            $this->generator->generate(NodeTypeManager::createFromArrayConfiguration(self::nodeTypes()), 'manifest.xsd')
        );
    }

    public function testAReferenceIsNotEmittedBecauseTheFormatCannotSetOne(): void
    {
        self::assertStringNotContainsString('footerItems', $this->schemas['Acme.Site.xsd']);
    }

    /**
     * @return array{schemaErrors:array<int,string>,documentErrors:array<int,string>}
     */
    private function validate(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            $document->loadXML('<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml);
            libxml_clear_errors();

            $document->schemaValidate($this->directory . '/all.xsd');

            $schemaErrors = [];
            $documentErrors = [];

            foreach (libxml_get_errors() as $error) {
                $message = trim($error->message);

                // A parser-level complaint is about the schemas themselves; a validity error is
                // about the manifest. Keeping them apart is what lets a broken schema fail loudly
                // rather than look like a rejected document.
                if (str_contains($message, 'Schemas parser error') || str_contains($message, 'failed to compile')) {
                    $schemaErrors[] = $message;
                    continue;
                }

                $documentErrors[] = $message;
            }

            return ['schemaErrors' => $schemaErrors, 'documentErrors' => $documentErrors];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private const string NS = 'https://medienreaktor.de/ns/contentrepository-commands/manifest';

    private function manifest(string $content): string
    {
        return sprintf(
            '<crm:manifest xmlns:crm="%s" xmlns:Acme.Site="Acme.Site">
               <crm:site name="site">
                 <crm:page path="/">
                   <Acme.Site:Document.Page.Homepage>%s</Acme.Site:Document.Page.Homepage>
                 </crm:page>
               </crm:site>
             </crm:manifest>',
            self::NS,
            $content
        );
    }

    /**
     * A miniature of the Neos core hierarchy, because the constraints under test are declared there
     * rather than in a site package.
     *
     * @return array<string,mixed>
     */
    private static function nodeTypes(): array
    {
        return [
            'Neos.Neos:Node' => ['abstract' => true],
            'Neos.Neos:Document' => [
                'abstract' => true,
                'superTypes' => ['Neos.Neos:Node' => true],
                'constraints' => ['nodeTypes' => ['*' => false, 'Neos.Neos:Document' => true]],
                'properties' => ['title' => ['type' => 'string'], 'uriPathSegment' => ['type' => 'string']],
            ],
            'Neos.Neos:Content' => [
                'abstract' => true,
                'superTypes' => ['Neos.Neos:Node' => true],
                'constraints' => ['nodeTypes' => ['*' => false]],
            ],
            'Neos.Neos:ContentCollection' => [
                'superTypes' => ['Neos.Neos:Node' => true],
                'constraints' => ['nodeTypes' => ['Neos.Neos:Document' => false, '*' => true]],
            ],
            'Acme.Site:Document.Page.Homepage' => [
                'superTypes' => ['Neos.Neos:Document' => true],
                'childNodes' => ['main' => ['type' => 'Neos.Neos:ContentCollection']],
                'references' => ['footerItems' => []],
            ],
            'Acme.Site:Content.Hero' => [
                'superTypes' => ['Neos.Neos:Content' => true],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'image' => ['type' => 'Neos\Media\Domain\Model\ImageInterface'],
                    'showDash' => ['type' => 'boolean'],
                    'headingLevel' => [
                        'type' => 'string',
                        'ui' => ['inspector' => ['editorOptions' => [
                            'allowEmpty' => false,
                            'values' => ['h1' => ['label' => 'H1'], 'h2' => ['label' => 'H2']],
                        ]]],
                    ],
                ],
            ],
            'Acme.Site:Content.Teaser' => [
                'superTypes' => ['Neos.Neos:Content' => true],
                'properties' => ['number' => ['type' => 'string']],
            ],
            'Acme.Site:Content.Grid' => [
                'superTypes' => ['Neos.Neos:Content' => true, 'Neos.Neos:ContentCollection' => true],
                'constraints' => ['nodeTypes' => ['*' => false, 'Acme.Site:Content.Grid.Cell' => true]],
            ],
            'Acme.Site:Content.Grid.Cell' => [
                'superTypes' => ['Neos.Neos:Content' => true, 'Neos.Neos:ContentCollection' => true],
            ],
        ];
    }
}
