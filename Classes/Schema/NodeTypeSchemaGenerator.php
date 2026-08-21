<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Schema;

use Medienreaktor\ContentRepository\Commands\Xml\ManifestXmlParser;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;

/**
 * Turns the installed node types into XML schemas, so that an IDE can validate and complete a
 * manifest file.
 *
 * One schema per package, because a node type name is a QName and its package key is the namespace.
 * Each declares one global element per non-abstract node type it owns, with that node type's
 * properties as attributes *and* as unqualified local elements — the format accepts either — and a
 * reference to every node type allowed under it.
 *
 * **Nothing here is a policy decision.** The Neos constraint model is self-describing:
 * `Neos.Neos:Content` declares `constraints.nodeTypes: {'*': false}` and `Neos.Neos:ContentCollection`
 * declares `{'Neos.Neos:Document': false, '*': true}`, both merged down through the supertype chain.
 * So asking the node type gives the same answer the Neos UI gives, and a leaf content type correctly
 * reports that nothing may go inside it.
 *
 * **The schema does not have to replicate Neos exactly.** It and the parser only have to produce
 * valid trees between them while each protects itself from error. Where this is looser than Neos —
 * the widening in {@see allowedChildrenOf} for a node type with several content collections — the
 * importer and the Content Repository still refuse. Where it is stricter, as with references, it
 * flags something that would not have worked anyway.
 *
 * Output is ordered — packages, node types and properties all sorted by name — because the schemas
 * are meant to be committed, and an unstable diff is one nobody reads.
 */
#[Flow\Scope('singleton')]
final class NodeTypeSchemaGenerator
{
    /**
     * The node type the manifest schema's substitution group stands for, and the one that says a
     * node's children are content.
     *
     * Written out rather than taken from Neos.Neos' NodeTypeNameFactory, which would make this
     * package depend on the whole of Neos.Neos for two strings it can spell.
     */
    private const string DOCUMENT_NODE_TYPE = 'Neos.Neos:Document';
    private const string CONTENT_COLLECTION_NODE_TYPE = 'Neos.Neos:ContentCollection';

    /** The manifest schema's substitution group head for content node types. */
    private const string CONTENT_GROUP_REFERENCE = 'crm:content';

    private const string XML_SCHEMA_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';
    private const string XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    /** The complex type each string property's element form gets: text with inline markup. */
    public const string PROPERTY_VALUE_TYPE = 'PropertyValue';

    /**
     * Property types that map onto an XSD built-in. Anything else — an asset interface, an array, a
     * class name — is carried as a string, which is what the manifest holds anyway.
     */
    private const array SIMPLE_TYPES = [
        'boolean' => 'xs:boolean',
        'integer' => 'xs:integer',
        'float' => 'xs:decimal',
        'DateTime' => 'xs:dateTime',
        'DateTimeImmutable' => 'xs:dateTime',
        '\DateTime' => 'xs:dateTime',
        '\DateTimeImmutable' => 'xs:dateTime',
        '\DateTimeInterface' => 'xs:dateTime',
    ];

    /**
     * An NCName, as far as a node type or property name ever needs. Anything failing this cannot be
     * an element or attribute name, so it is left out rather than emitted as broken XML.
     */
    private const string NC_NAME_PATTERN = '#^[A-Za-z_][A-Za-z0-9_.\-]*$#';

    /**
     * @param string $manifestSchemaLocation Where all.xsd should point for the manifest schema,
     *        relative to the target directory
     * @return array<string,string> File name => schema, ready to be written side by side
     */
    public function generate(NodeTypeManager $nodeTypeManager, string $manifestSchemaLocation): array
    {
        $candidates = [];

        foreach ($nodeTypeManager->getNodeTypes(false) as $name => $nodeType) {
            if (self::split((string)$name) === null) {
                continue;
            }

            // A root node type is created by its own command and is nobody's child, so it can appear
            // nowhere in a manifest. Its constraints would otherwise let it in: a content collection
            // allows everything but documents, and a root is not a document.
            if ($nodeType->isOfType(NodeTypeName::ROOT_NODE_TYPE_NAME)) {
                continue;
            }

            $candidates[(string)$name] = $nodeType;
        }

        $byPackage = [];

        foreach ($candidates as $name => $nodeType) {
            [$packageKey, $localName] = self::split($name) ?? ['', ''];
            $byPackage[$packageKey][$localName] = $nodeType;
        }

        ksort($byPackage);

        $contentGroup = self::contentGroupOf($candidates, $nodeTypeManager);
        $files = [];

        foreach ($byPackage as $packageKey => $nodeTypes) {
            ksort($nodeTypes);
            $files[$packageKey . '.xsd'] = $this->packageSchema($packageKey, $nodeTypes, $candidates, $contentGroup, $manifestSchemaLocation, $nodeTypeManager);
        }

        $files['all.xsd'] = $this->aggregateSchema(array_keys($byPackage), $manifestSchemaLocation);

        return $files;
    }

    /**
     * @param array<string,NodeType> $nodeTypes local name => node type, this package's own
     * @param array<string,NodeType> $candidates every usable node type, for the child lookups
     * @param array<string,true> $contentGroup what crm:content stands for
     * @param string $manifestSchemaLocation relative path to the manifest schema this package ships
     */
    private function packageSchema(
        string $packageKey,
        array $nodeTypes,
        array $candidates,
        array $contentGroup,
        string $manifestSchemaLocation,
        NodeTypeManager $nodeTypeManager,
    ): string {
        // Children are resolved first, because which other packages this schema has to declare a
        // prefix for and import follows from them.
        $children = [];
        $referenced = [$packageKey => true];

        foreach ($nodeTypes as $localName => $nodeType) {
            $children[$localName] = self::childReferences(
                $this->allowedChildrenOf($nodeType, $candidates, $nodeTypeManager),
                $contentGroup
            );

            foreach ($children[$localName] as $reference) {
                if ($reference === self::CONTENT_GROUP_REFERENCE) {
                    continue;
                }

                [$childPackageKey] = self::split($reference) ?? [''];
                $referenced[$childPackageKey] = true;
            }
        }

        ksort($referenced);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $schema = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:schema');
        $document->appendChild($schema);

        $schema->setAttribute('targetNamespace', $packageKey);

        // Every QName in here is prefixed, including references into this schema's own namespace, so
        // that nothing depends on a default namespace being in scope.
        foreach (array_keys($referenced) as $prefix) {
            $schema->setAttributeNS(self::XMLNS_NAMESPACE, 'xmlns:' . $prefix, (string)$prefix);
        }

        $schema->setAttributeNS(self::XMLNS_NAMESPACE, 'xmlns:crm', ManifestXmlParser::MANIFEST_NAMESPACE);

        // Properties are unqualified and node types are not: that is the whole distinction the
        // format rests on, and it is this one attribute.
        $schema->setAttribute('elementFormDefault', 'unqualified');

        $schema->appendChild($document->createComment(sprintf(
            "\n    GENERATED by cr:exportxsd from the installed node types of %s. Do not edit.\n"
            . "    Re-run the command after changing a NodeTypes.yaml or installing a package.\n  ",
            $packageKey
        )));

        // Every import carries a schemaLocation. Namespace *discovery* covers the document's own
        // declarations, but an IDE does not follow a location-less xs:import — so without these a
        // substitution group member declared in another package is never seen, and a manifest using
        // one is reported as invalid against a schema that in fact allows it.
        $schema->appendChild(self::import($document, ManifestXmlParser::MANIFEST_NAMESPACE, $manifestSchemaLocation));

        foreach (array_keys($referenced) as $importedPackageKey) {
            if ($importedPackageKey !== $packageKey) {
                $schema->appendChild(self::import($document, (string)$importedPackageKey, $importedPackageKey . '.xsd'));
            }
        }

        $schema->appendChild(self::propertyValueType($document));

        foreach ($nodeTypes as $localName => $nodeType) {
            $schema->appendChild($this->element($document, $packageKey, $localName, $nodeType, $children[$localName], $contentGroup));
        }

        return (string)$document->saveXML();
    }

    /**
     * What crm:content stands for: every node type a plain content collection accepts.
     *
     * Derived rather than declared, so it follows whatever the installed packages say a collection
     * takes. Documents are not in it, because a collection's own constraints exclude them.
     *
     * @param array<string,NodeType> $candidates
     * @return array<string,true>
     */
    private static function contentGroupOf(array $candidates, NodeTypeManager $nodeTypeManager): array
    {
        $collection = $nodeTypeManager->getNodeType(NodeTypeName::fromString(self::CONTENT_COLLECTION_NODE_TYPE));

        if ($collection === null) {
            return [];
        }

        $group = [];

        foreach ($candidates as $name => $candidate) {
            if ($collection->allowsChildNodeType($candidate)) {
                $group[(string)$name] = true;
            }
        }

        return $group;
    }

    /**
     * The child references to emit, collapsing the content group into one where it applies.
     *
     * A container that accepts everything a collection accepts says so with crm:content instead of
     * listing every content type installed — the same statement, some hundreds of lines shorter.
     * Anything it accepts beyond the group is still listed, and nothing in the group is listed
     * alongside the head: an element reachable both ways would make the content model ambiguous,
     * which XSD rejects outright.
     *
     * @param array<int,string> $allowed
     * @param array<string,true> $contentGroup
     * @return array<int,string>
     */
    private static function childReferences(array $allowed, array $contentGroup): array
    {
        if ($contentGroup === []) {
            return $allowed;
        }

        $remaining = [];

        foreach ($allowed as $name) {
            if (!isset($contentGroup[$name])) {
                $remaining[] = $name;
            }
        }

        // Only a container accepting the whole group can use the head; one accepting most of it has
        // to list what it takes, since XSD cannot subtract a member from a substitution group.
        if (count($allowed) - count($remaining) !== count($contentGroup)) {
            return $allowed;
        }

        return [self::CONTENT_GROUP_REFERENCE, ...$remaining];
    }

    private static function import(\DOMDocument $document, string $namespace, ?string $schemaLocation = null): \DOMElement
    {
        $import = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:import');
        $import->setAttribute('namespace', $namespace);

        if ($schemaLocation !== null) {
            $import->setAttribute('schemaLocation', $schemaLocation);
        }

        return $import;
    }

    /**
     * A property's element form: text with whatever inline markup it carries.
     *
     * The markup is unqualified, because a manifest declares no default namespace, so `##local`
     * matches it and `skip` means no declaration is needed for it.
     */
    private static function propertyValueType(\DOMDocument $document): \DOMElement
    {
        $type = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:complexType');
        $type->setAttribute('name', self::PROPERTY_VALUE_TYPE);
        $type->setAttribute('mixed', 'true');

        $sequence = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:sequence');
        $any = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:any');
        $any->setAttribute('namespace', '##local');
        $any->setAttribute('processContents', 'skip');
        $any->setAttribute('minOccurs', '0');
        $any->setAttribute('maxOccurs', 'unbounded');

        $sequence->appendChild($any);
        $type->appendChild($sequence);

        return $type;
    }

    /**
     * @param array<int,string> $children
     * @param array<string,true> $contentGroup
     */
    private function element(
        \DOMDocument $document,
        string $packageKey,
        string $localName,
        NodeType $nodeType,
        array $children,
        array $contentGroup,
    ): \DOMElement {
        $element = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:element');
        $element->setAttribute('name', $localName);

        // A node type enrols itself into one of the manifest schema's substitution groups, which is
        // what lets <crm:page> accept a document, and a container accept the content group, without
        // the manifest schema knowing either exists. XSD 1.0 allows one group per element, and the
        // two never overlap: a collection's constraints exclude documents.
        if ($nodeType->isOfType(self::DOCUMENT_NODE_TYPE)) {
            $element->setAttribute('substitutionGroup', 'crm:document');
        } elseif (isset($contentGroup[$nodeType->name->value])) {
            $element->setAttribute('substitutionGroup', self::CONTENT_GROUP_REFERENCE);
        }

        $element->appendChild(self::annotation($document, self::describe($nodeType)));

        $complexType = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:complexType');
        $properties = self::propertiesOf($nodeType);

        // One repeating choice over properties and children together. Order is free, and each
        // property may appear more than once — xs:all would cap it at one but in XSD 1.0 cannot
        // coexist with repeating children, and the parser rejects a duplicate property anyway.
        if ($properties !== [] || $children !== []) {
            $choice = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:choice');
            $choice->setAttribute('minOccurs', '0');
            $choice->setAttribute('maxOccurs', 'unbounded');

            foreach ($properties as $propertyName => $declaredType) {
                $child = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:element');
                $child->setAttribute('name', $propertyName);
                $child->appendChild(self::annotation($document, sprintf('%s (%s)', $propertyName, $declaredType)));

                $values = self::selectBoxValuesOf($nodeType, $propertyName);

                if ($values === null) {
                    $child->setAttribute('type', self::typeOf($declaredType, $packageKey));
                } else {
                    // A property with a select box holds one of a few tokens, never markup, so its
                    // element form is the same restriction its attribute form gets. The two ways of
                    // writing a property should accept the same values.
                    $child->appendChild(self::enumeration($document, $values));
                }

                $choice->appendChild($child);
            }

            foreach ($children as $childNodeTypeName) {
                $ref = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:element');
                $ref->setAttribute('ref', $childNodeTypeName);
                $choice->appendChild($ref);
            }

            $complexType->appendChild($choice);
        }

        foreach ($properties as $propertyName => $declaredType) {
            $complexType->appendChild(self::attribute($document, $propertyName, $declaredType, $nodeType));
        }

        $nameAttribute = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:attribute');
        $nameAttribute->setAttribute('ref', 'crm:name');
        $complexType->appendChild($nameAttribute);

        $element->appendChild($complexType);

        return $element;
    }

    /**
     * A property as an attribute, with its select box values as an enumeration where it has them.
     *
     * The enumeration is the part that turns validation into completion: an editor offers h1..h5
     * rather than accepting anything.
     */
    private static function attribute(\DOMDocument $document, string $propertyName, string $declaredType, NodeType $nodeType): \DOMElement
    {
        $attribute = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:attribute');
        $attribute->setAttribute('name', $propertyName);
        $attribute->appendChild(self::annotation($document, sprintf('%s (%s)', $propertyName, $declaredType)));

        $values = self::selectBoxValuesOf($nodeType, $propertyName);

        if ($values === null) {
            // An attribute cannot hold markup, so a string property is a plain string here even
            // though its element form carries inline HTML.
            $attribute->setAttribute('type', self::SIMPLE_TYPES[$declaredType] ?? 'xs:string');

            return $attribute;
        }

        $attribute->appendChild(self::enumeration($document, $values));

        return $attribute;
    }

    /**
     * @param array<int,string> $values
     */
    private static function enumeration(\DOMDocument $document, array $values): \DOMElement
    {
        $simpleType = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:simpleType');
        $restriction = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:restriction');
        $restriction->setAttribute('base', 'xs:string');

        foreach ($values as $value) {
            $enumeration = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:enumeration');
            $enumeration->setAttribute('value', $value);
            $restriction->appendChild($enumeration);
        }

        $simpleType->appendChild($restriction);

        return $simpleType;
    }

    /**
     * The values a select box offers, or null where the property is not one.
     *
     * An empty value is allowed unless the editor says otherwise, because that is the editor's own
     * default and a property left blank is how "no choice" is written.
     *
     * @return array<int,string>|null
     */
    private static function selectBoxValuesOf(NodeType $nodeType, string $propertyName): ?array
    {
        $configured = $nodeType->getConfiguration(sprintf('properties.%s.ui.inspector.editorOptions.values', $propertyName));

        if (!is_array($configured) || $configured === []) {
            return null;
        }

        $values = [];

        if ($nodeType->getConfiguration(sprintf('properties.%s.ui.inspector.editorOptions.allowEmpty', $propertyName)) !== false) {
            $values[] = '';
        }

        foreach (array_keys($configured) as $value) {
            $values[] = (string)$value;
        }

        return $values;
    }

    /**
     * The node types allowed as children, as QNames, sorted.
     *
     * Which collection the children of a node land in follows from the node type — `main` is not a
     * Neos fact — so this asks the same questions the importer does:
     *
     * 1. the node type *is* a content collection: its own constraints decide;
     * 2. it has exactly one tethered content collection: that collection's constraints decide;
     * 3. it has none: its own constraints decide;
     * 4. it has several: the union of theirs, because a global element declaration carries one type
     *    and cannot vary by crm:name. A widening, so no valid manifest is rejected.
     *
     * Every tethered node type is allowed too, whatever it is, because crm:name may address one
     * explicitly.
     *
     * @param array<string,NodeType> $candidates
     * @return array<int,string>
     */
    private function allowedChildrenOf(NodeType $nodeType, array $candidates, NodeTypeManager $nodeTypeManager): array
    {
        $allowed = [];
        $collections = [];

        foreach ($nodeType->tetheredNodeTypeDefinitions as $definition) {
            // A tethered node can always be addressed by name.
            if (self::split($definition->nodeTypeName->value) !== null) {
                $allowed[$definition->nodeTypeName->value] = true;
            }

            $tetheredNodeType = $nodeTypeManager->getNodeType($definition->nodeTypeName);

            if ($tetheredNodeType?->isOfType(self::CONTENT_COLLECTION_NODE_TYPE) === true) {
                $collections[] = $definition->name;
            }
        }

        if ($nodeType->isOfType(self::CONTENT_COLLECTION_NODE_TYPE) || $collections === []) {
            foreach ($candidates as $candidateName => $candidate) {
                if ($nodeType->allowsChildNodeType($candidate)) {
                    $allowed[$candidateName] = true;
                }
            }
        } else {
            foreach ($collections as $collectionName) {
                foreach ($candidates as $candidateName => $candidate) {
                    if ($nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode($nodeType->name, $collectionName, $candidate->name)) {
                        $allowed[$candidateName] = true;
                    }
                }
            }
        }

        $names = [];

        foreach (array_keys($allowed) as $name) {
            $split = self::split((string)$name);

            if ($split !== null) {
                $names[] = $split[0] . ':' . $split[1];
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param array<int,string> $packageKeys
     */
    private function aggregateSchema(array $packageKeys, string $manifestSchemaLocation): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $schema = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:schema');
        $document->appendChild($schema);

        $schema->appendChild($document->createComment(
            "\n    GENERATED by cr:exportxsd. Do not edit.\n\n"
            . "    An entry point for command line validation, where nothing resolves a namespace on\n"
            . "    its own: pass this file to xmllint as the schema for a manifest. An IDE does not\n"
            . "    need it, having found every schema in the project already.\n  "
        ));

        $schema->appendChild(self::import($document, ManifestXmlParser::MANIFEST_NAMESPACE, $manifestSchemaLocation));

        foreach ($packageKeys as $packageKey) {
            $schema->appendChild(self::import($document, (string)$packageKey, $packageKey . '.xsd'));
        }

        return (string)$document->saveXML();
    }

    private static function annotation(\DOMDocument $document, string $text): \DOMElement
    {
        $annotation = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:annotation');
        $documentation = $document->createElementNS(self::XML_SCHEMA_NAMESPACE, 'xs:documentation');
        $documentation->appendChild($document->createTextNode($text));
        $annotation->appendChild($documentation);

        return $annotation;
    }

    /**
     * What an IDE shows on hover, which is worth more than the node type's own label: a Neos label is
     * usually the literal "i18n", which says nothing.
     */
    private static function describe(NodeType $nodeType): string
    {
        $label = $nodeType->getConfiguration('ui.label');
        $description = $nodeType->name->value;

        if (is_string($label) && $label !== '' && $label !== 'i18n') {
            $description .= ' — ' . $label;
        }

        return $description;
    }

    /**
     * The declared type of every property a manifest may set, by name.
     *
     * References are left out: Neos keeps them out of `properties` anyway, and the format cannot set
     * them.
     *
     * So are underscore-prefixed names. Neos declares those so the inspector renders an editor for
     * them, then intercepts them by name and issues something other than a property write —
     * `_nodeType` becomes ChangeNodeAggregateType, `_hidden` becomes the disabled subtree tag. Writing
     * one as a property stores a value nothing reads, and for `_hidden` it would look like it worked
     * while the node stayed visible.
     *
     * @return array<string,string>
     */
    private static function propertiesOf(NodeType $nodeType): array
    {
        $properties = [];

        foreach (array_keys($nodeType->getProperties()) as $propertyName) {
            $propertyName = (string)$propertyName;

            if (str_starts_with($propertyName, '_')) {
                continue;
            }

            if (preg_match(self::NC_NAME_PATTERN, $propertyName) !== 1) {
                continue;
            }

            $properties[$propertyName] = $nodeType->getPropertyType($propertyName);
        }

        ksort($properties);

        return $properties;
    }

    private static function typeOf(string $declaredType, string $packageKey): string
    {
        return self::SIMPLE_TYPES[$declaredType] ?? $packageKey . ':' . self::PROPERTY_VALUE_TYPE;
    }

    /**
     * A node type name split into its package key and local name, or null where it is neither.
     *
     * @return array{0:string,1:string}|null
     */
    private static function split(string $nodeTypeName): ?array
    {
        if (substr_count($nodeTypeName, ':') !== 1) {
            return null;
        }

        [$packageKey, $localName] = explode(':', $nodeTypeName, 2);

        if (preg_match(self::NC_NAME_PATTERN, $packageKey) !== 1 || preg_match(self::NC_NAME_PATTERN, $localName) !== 1) {
            return null;
        }

        return [$packageKey, $localName];
    }
}
