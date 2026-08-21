# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `cr:exportxsd` no longer writes `elementFormDefault="unqualified"` on a generated schema. It is the
  XSD default, so stating it changed nothing and an IDE reported it as a redundant attribute value.

## [0.6.0] - 2026-08-21

The seed XML format becomes the manifest format. Every existing file needs rewriting: the root
element, both namespaces and the property syntax all move.

### Added

- `cr:exportxsd`, which writes XML schemas for the installed node types so that an IDE validates a
  manifest and completes node types, properties and select box values as it is written. One schema
  per package that declares a non-abstract node type, plus `all.xsd` as an entry point for
  command line validation.
- A static manifest schema, shipped at `Schema/manifest.xsd`. It declares two substitution group
  heads — `crm:document` for document node types and `crm:content` for everything a content
  collection accepts — which the generated schemas enrol into, so it never needs regenerating.
- Manifests may carry assets and no site, seeding the media library and no content.

### Changed

- **The root element is `crm:manifest`**, holding one optional `crm:assets` and one optional
  `crm:site`. The asset list was previously `<seed:manifest>` nested inside a `<seed:site>` root,
  which implied assets were owned by a site; the Neos media library is global.
- **A property is an attribute or an unqualified child element.** The `prop:` namespace is gone.
  Unqualified means a property, a namespace means a node type.
- **A node type namespace is the bare package key**, `Acme.Site` rather than
  `https://acme.example/ns/nodetypes/Acme.Site`. The prefix remains arbitrary.
- **The manifest namespace is
  `https://medienreaktor.de/ns/contentrepository-commands/manifest`**, unversioned, conventionally
  prefixed `crm:`. It was `https://medienreaktor.de/ns/neos-seed/1.0`.
- **An import is now the whole truth about what its file describes.** A content collection the file
  does not mention is emptied, including a page element with no children at all, and a matched node
  has its whole property set written, so a property dropped from the file is unset rather than left
  behind. Previously the same file could produce different trees on two instances depending on what
  each held first. Node type defaults are deliberately not written for a matched node's unnamed
  properties, so a created node starts from its defaults and a matched one from nothing.
- Several `crm:assets` or `crm:site` blocks in one file are an error. Several `<seed:manifest>`
  blocks were previously merged silently, in any order.
- Error `1787097627` reports "neither assets nor a site" rather than "no page", a manifest with
  assets alone now being valid.
- `Xml\SeedXmlParser` is `Xml\ManifestXmlParser` and `Import\XmlSeedImporter` is
  `Import\XmlManifestImporter`. `ParsedManifest` is the new root value object, holding the assets and
  a nullable `ParsedSite`. `cr:importxml` keeps its name.

### Removed

- The `prop:` property namespace, and with it the `PROPERTY_NAMESPACE` constant.

## [0.5.1] - 2026-08-19

### Fixed

- The properties a seed file puts on a document are written. A document addressed by a page path is
  matched rather than created, and its own properties were being skipped along with it — which is
  how a site node's title, logo and favicons get seeded.

## [0.5.0] - 2026-08-19

### Added

- `cr:importxml`, which imports a whole content tree from a seed XML file rather than composing the
  primitives in a shell script, with `--dry-run` to check that the file reads before anything is
  written.

## [0.4.0] - 2026-08-19

### Added

- `cr:importasset`, which imports a file into the media library from a path or an `http(s)` URL and
  prints its identifier, so that a script can fill an asset property. `--reference` prints the whole
  property value instead of the bare identifier. Importing the same content twice returns the first
  asset rather than a copy.

## [0.3.0] - 2026-08-19

### Added

- `cr:removenodeaggregate`, with a selectable `nodeVariantSelectionStrategy`. This is a hard removal,
  which is not what the Neos UI does when an editor deletes a node.
- `cr:findnodeaggregate` and `cr:findchildnodeaggregates`, so that a script can locate the nodes it
  needs to act on. Both query the graph unfiltered, including nodes the UI has tagged as removed.
- Declared dependencies, PHP CodeSniffer and PHPStan, and CI across PHP 8.3, 8.4 and 8.5.

### Changed

- **stdout carries data, stderr carries everything else.** A command with a result writes it to
  stdout, one value per line and nothing else, so a caller can capture it without parsing. Progress
  and errors go to stderr.
- Every failure is reported as a single error line rather than a stack trace.

## [0.2.0] - 2026-04-16

### Fixed

- A property the node type declares as `DateTime` is read from its string form and passed on as a
  `DateTimeImmutable`, which the Content Repository accepts where it rejects a plain string.

## [0.1.0] - 2026-04-16

### Added

- `cr:createnodeaggregate` and `cr:setnodeproperties`, dispatching Content Repository commands from
  the CLI.

[Unreleased]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.6.0...HEAD
[0.6.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.5.1...0.6.0
[0.5.1]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/releases/tag/0.1.0
