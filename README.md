# Medienreaktor.ContentRepository.Commands

[![CI](https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/medienreaktor/Medienreaktor.ContentRepository.Commands/actions/workflows/ci.yml)

CLI Commands for the Event Sourced Content Repository of Neos CMS.

**Note:** This package is still work in progress. Use with care.

## Commands

The `create` / `setnodeproperties` / `remove` commands directly dispatch Commands on the Content Repository. The Content Repository handles the Command and emits the Event to the Event Store. The `find` commands read the content graph, so that a script can locate the nodes it needs to act on. `importasset` puts a file into the media library, so that a script can fill an asset property.

Those are primitives, composed by the caller. `importxml` is the one command that is not: it takes a file describing a whole content tree and makes that tree exist. Where a seed built from the primitives is a script that has to get its order, its ids and its shell quoting right, the same seed as XML is a document — see [Import a content tree](#import-a-content-tree). `exportxsd` writes the schemas that make such a document checkable while it is being written.

### Create node aggregate

Use `cr:createnodeaggregate` to create a new node aggregate. It prints the new node aggregate ID to stdout.

| Argument                    | Description                                                       | Example                                                     |
| --------------------------- | ----------------------------------------------------------------- | ----------------------------------------------------------- |
| `contentRepository`         | Identifier of the Content Repository                              | `default`                                                   |
| `workspaceName`             | The workspace in which the create operation is to be performed    | `live`                                                      |
| `originDimensionSpacePoint` | The dimension space point in which the new node should be created | `{"language": "en"}`                                        |
| `nodeTypeName`              | Name of the node type of the new node                             | `Neos.Neos:Page`                                            |
| `parentNodeId`              | The identifier of the parent node aggregate                       | `213b1564-14df-4984-bccd-5c6d003179ef`                      |
| `propertyValues`            | The property key/value pairs to write to the new node             | `{"title": "My new node", "uriPathSegment": "my-new-node"}` |

If you execute the CLI Command without arguments, all required arguments will be asked interactively. You can pass all arguments in a single line (e.g. to use with Claude or bash scripts) like this:

```
flow cr:createnodeaggregate
    --contentRepository default
    --workspaceName live
    --originDimensionSpacePoint '{"language": "en"}'
    --nodeTypeName Neos.Neos:Page
    --parentNodeId 213b1564-14df-4984-bccd-5c6d003179ef
    --propertyValues '{"title": "My new node", "uriPathSegment": "my-new-node"}'
```

### Set node properties

Use `cr:setnodeproperties` to set new properties on existing nodes.

| Argument                    | Description                                                            | Example                                |
| --------------------------- | ---------------------------------------------------------------------- | -------------------------------------- |
| `contentRepository`         | Identifier of the Content Repository                                   | `default`                              |
| `workspaceName`             | The workspace in which the set properties operation is to be performed | `live`                                 |
| `nodeAggregateId`           | The identifier of the node aggregate to set the properties for         | `213b1564-14df-4984-bccd-5c6d003179ef` |
| `originDimensionSpacePoint` | The dimension space point the properties should be changed in          | `{"language": "en"}`                   |
| `propertyValues`            | The property key/value pairs to write to set                           | `{"title": "My new title"}`            |

### Remove node aggregate

Use `cr:removenodeaggregate` to remove an existing node aggregate, together with everything below it.

| Argument                       | Description                                                                       | Example                                |
| ------------------------------ | --------------------------------------------------------------------------------- | -------------------------------------- |
| `contentRepository`            | Identifier of the Content Repository                                              | `default`                              |
| `workspaceName`                | The workspace in which the remove operation is to be performed                    | `live`                                 |
| `nodeAggregateId`              | The identifier of the node aggregate to remove                                    | `213b1564-14df-4984-bccd-5c6d003179ef` |
| `coveredDimensionSpacePoint`   | The dimension space point the node should be removed in                           | `{"language": "en"}`                   |
| `nodeVariantSelectionStrategy` | Optional. Which further dimension space points to remove in, see below            | `allVariants`                          |

```
flow cr:removenodeaggregate
    --contentRepository default
    --workspaceName live
    --nodeAggregateId 213b1564-14df-4984-bccd-5c6d003179ef
    --coveredDimensionSpacePoint '{"language": "en"}'
```

#### This is a hard removal

The node is really gone from the workspace it was removed in. That is *not* what the Neos UI does when an editor deletes a node — the UI tags the subtree as removed, so that the deletion can still be published or discarded like any other change. A hard removal in a user workspace cannot be discarded, so unless you know you want the event-level behaviour, remove in `live` and leave editorial deletions to the UI.

#### `nodeVariantSelectionStrategy`

A node aggregate can cover several dimension space points, and the strategy decides which of them the removal reaches beyond the one named in `coveredDimensionSpacePoint`. Consider a site with `de`, its specialization `gsw`, and `fr` as a peer of `de`:

| Value                | Removing in `de` also removes | Notes                                                                    |
| -------------------- | ----------------------------- | ------------------------------------------------------------------------ |
| `allVariants`        | `gsw` and `fr`                | Default. Every point the aggregate covers. Required for root nodes.      |
| `allSpecializations` | `gsw`                         | The given point and everything more specific. What the Neos UI issues.   |

The default is `allVariants`, which is what a seed script wiping a collection wants. Pass `allSpecializations` when peer variants — a separate translation, say — have to survive.

### Find a node aggregate

Use `cr:findnodeaggregate` to resolve an absolute node path to a node aggregate ID. An absolute path starts at the root node — written as its node type in angle brackets — and continues with node names.

| Argument              | Description                          | Example                            |
| --------------------- | ------------------------------------ | ---------------------------------- |
| `contentRepository`   | Identifier of the Content Repository | `default`                          |
| `workspaceName`       | The workspace to look in             | `live`                             |
| `dimensionSpacePoint` | The dimension space point to look in | `{"language": "en"}`               |
| `path`                | The absolute node path               | `/<Neos.Neos:Sites>/my-site/main`  |

```
flow cr:findnodeaggregate
    --contentRepository default
    --workspaceName live
    --dimensionSpacePoint '{"language": "en"}'
    --path '/<Neos.Neos:Sites>/my-site/main'
```

Exits non-zero if no node exists at the path, so a script that captures the result does not silently continue with an empty ID.

### Find child node aggregates

Use `cr:findchildnodeaggregates` to list the direct children of a node aggregate, one ID per line, in the order they are arranged. It prints nothing when there are none, so a loop over its output simply does not run.

| Argument              | Description                                        | Example                                |
| --------------------- | -------------------------------------------------- | -------------------------------------- |
| `contentRepository`   | Identifier of the Content Repository               | `default`                              |
| `workspaceName`       | The workspace to look in                           | `live`                                 |
| `dimensionSpacePoint` | The dimension space point to look in               | `{"language": "en"}`                   |
| `nodeAggregateId`     | The node aggregate whose children to find          | `213b1564-14df-4984-bccd-5c6d003179ef` |

Clearing a collection is the two commands together:

```sh
for id in $(flow cr:findchildnodeaggregates ... --node-aggregate-id "$MAIN"); do
  flow cr:removenodeaggregate ... --node-aggregate-id "$id" --covered-dimension-space-point "$DSP"
done
```

Both `find` commands query the graph unfiltered, which includes nodes the Neos UI has tagged as removed. That is deliberate: a script clearing a collection has to see a soft-removed node, or it skips it, the node survives the rebuild, and the result is not the clean tree the script was written to produce.

### Import an asset

Use `cr:importasset` to import a file into the media library. It prints the asset identifier to stdout.

| Argument    | Description                                                                                             | Example                                      |
| ----------- | ------------------------------------------------------------------------------------------------------- | -------------------------------------------- |
| `file`      | Path to the file to import, absolute or relative to the current directory, or an `http(s)` URL            | `seed/hero.png`                              |
| `title`     | Optional. Title of the asset, as it appears in the media browser. Defaults to the file name.             | `Hero`                                       |
| `reference` | Optional. Print the property value that refers to the asset instead of the bare identifier.              | —                                            |

A node property declared as an asset holds the asset itself, so seeding such a node means importing the file first. Neos has no command for that: `media:importresources` picks up resources Flow already knows about, which is the second half of the job rather than this one.

The asset type follows the file, through the same mapping strategy the media library uses — a PNG becomes an `Image`, a PDF a `Document`.

A URL is downloaded to a temporary copy, imported, and the copy removed; the asset is named after the URL's last path segment rather than the temporary file. Worth knowing before committing a seed that uses one: an export URL from a design tool is usually signed and short-lived, so it suits a one-off import and not a file meant to be re-imported next year.

```
flow cr:importasset --file seed/hero.png --title Hero
```

#### Importing twice does not import twice

Sameness is the SHA-1 of the content, which is what the media library itself deduplicates on: a second import of the same file returns the asset from the first one instead of a copy. So a seed script can be re-run without the media library filling up with duplicates. A renamed copy of an imported file is the same asset; an edited one is a new asset.

#### `--reference`

The property value that refers to an asset is not the identifier alone (see below), so `--reference` prints the whole thing, ready to be dropped into the JSON that `cr:createnodeaggregate` takes:

```sh
IMAGE=$(flow cr:importasset --file seed/hero.png --reference)
flow cr:createnodeaggregate ... --property-values="{\"image\":$IMAGE}"
```

### Import a content tree

Use `cr:importxml` to import a content tree from a manifest XML file.

| Argument        | Description                                          | Example                     |
| --------------- | ---------------------------------------------------- | --------------------------- |
| `file`          | Path to the manifest XML file                        | `manifest/Site.xml`         |
| `workspaceName` | Optional. The workspace to write to. Defaults to `live`. | `live`                  |
| `dryRun`        | Optional. Report what the import would do, and write nothing. | —                  |

```sh
flow cr:importxml --file manifest/Site.xml
```

The file describes the tree it wants to exist, with node types as element names:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<crm:manifest
    xmlns:crm="https://medienreaktor.de/ns/contentrepository-commands/manifest"
    xmlns:Acme.Site="Acme.Site">

  <crm:assets>
    <crm:asset id="hero" href="images/hero.png" title="Hero"/>
    <crm:asset id="logo" href="images/logo.svg" title="Logo"/>
  </crm:assets>

  <crm:site name="site" contentRepository="default" dimension="language=de">
    <crm:page path="/">
      <Acme.Site:Document.Page.Homepage title="Acme" logo="logo">
        <Acme.Site:Content.Hero image="hero" alternativeText="Rectangle 85">
          <title>Example <span class="highlight">headline.</span></title>
        </Acme.Site:Content.Hero>
        <Acme.Site:Content.Grid columns="2" layout="6-6">
          <Acme.Site:Content.Grid.Cell>
            <Acme.Site:Content.Teaser number="01" title="Military"/>
          </Acme.Site:Content.Grid.Cell>
        </Acme.Site:Content.Grid>
      </Acme.Site:Document.Page.Homepage>
    </crm:page>
  </crm:site>
</crm:manifest>
```

`cr:importxml` reads the file and nothing else. It does not validate against a schema and runs with none present, assuming only that the file is well-formed and unambiguous. For schemas an IDE can check it against, see [Export XML schemas](#export-xml-schemas).

#### Structure

| Element        | Occurs | Holds                                                        |
| -------------- | ------ | ------------------------------------------------------------ |
| `crm:manifest` | root   | one `crm:assets`, one `crm:site`, both optional               |
| `crm:assets`   | 0..1   | `crm:asset`                                                  |
| `crm:site`     | 0..1   | one or more `crm:page`; attributes `name` (required), `contentRepository`, `dimension` |
| `crm:page`     | 1..n   | exactly one document element; attribute `path` (required)      |

Assets are global to the file rather than owned by the site, so a manifest may carry assets and no site: it seeds the media library and no content. A manifest with neither is an error.

#### The element name is the node type name

A QName holds at most one colon and a node type name holds exactly one, so the package key becomes the namespace prefix and the rest the local name. Dots are legal in an NCName, so `Acme.Site:Content.Grid.Cell` survives intact and the element reads as the node type does in `NodeTypes.yaml`.

**The namespace URI is the package key.** The prefix is arbitrary and reassignable; `xmlns:ns0="Acme.Site"` with `<ns0:Content.Hero>` is the same document. Writing the prefix to match the package key is a convention.

#### A property is an attribute or an unqualified element

**Unqualified means a property; a namespace means a node type.** Both forms of a property end up in the same place:

```xml
<Acme.Site:Content.Heading title="Plain text"/>

<Acme.Site:Content.Heading>
  <title><h2>Markup <span class="highlight">as written.</span></h2></title>
</Acme.Site:Content.Heading>
```

- Setting the same property both ways is an error.
- Leading and trailing whitespace around an element's content is dropped; whitespace inside it is kept.
- Values are converted to the type the node type declares, so `showDash="true"` arrives as a boolean and `width="7"` as an integer. A value that does not fit is reported with the property name rather than cast.
- Text directly inside a node element is an error.

#### What the import does at each level

**The file is the whole truth about what it describes.** Running the same file on two instances leaves them with the same tree, whatever state each was in beforehand.

- **Content is rebuilt.** The children of every container the file describes are removed and written again in document order.
- **A collection the file says nothing about is emptied.**
- **A matched node's properties are brought to exactly what the file gives them.** Drop a property from the XML and it is unset on the next import.
- **A document named by a page path is matched, never created.** Its properties are still written, which is how a site's own settings get seeded. The import fails if the node type does not match what the file says, before anything is removed.
- **Tethered nodes are never removed.** Their content is reconciled like anything else.

**An editor's changes under a seeded page are lost.** Do not point this at a site being worked on.

One asymmetry: a node the import **creates** starts from its node type's defaults; a node it only **matches** starts from nothing, so the file's properties are written and every other declared property is unset. Either way the outcome follows from the file rather than from what came before.

#### Where content goes is derived from the node type

For a node whose children are content:

1. the node type **is** a content collection — children go directly into it;
2. it has **exactly one** tethered content collection — children go there;
3. it has **several** — the file has to say which, with `crm:name`;
4. it has **none** — children go directly into it.

```xml
<Acme.Site:Content.Columns.Two>
  <Neos.Neos:ContentCollection crm:name="column1">
    <Acme.Site:Content.Text text="Left."/>
  </Neos.Neos:ContentCollection>
</Acme.Site:Content.Columns.Two>
```

`crm:name` addresses any tethered node, and is required only for case 3.

#### A typo is an error, a reference is a warning

A property the node type does not declare stops the import:

```
Error: Line 6: Acme.Site:Document.Page.Homepage has no property "titel".
```

A property that is really a reference warns and is skipped; the rest of the import proceeds. This format cannot set references.

```
Warning: Line 10: "footerItems" of Acme.Site:Document.Page.Homepage is a reference, not a property. This format cannot set references yet, so it was skipped.
```

Warnings are printed as they are collected and counted in the summary.

#### Assets are declared once and referenced by id

`<crm:assets>` lists the files the content needs; content refers to them by id (`image="hero"`). Ids are local to the file. A relative `href` resolves against the directory of the manifest, not the working directory of the command. Importing deduplicates on content, so re-running does not fill the media library with copies.

#### `--dry-run`

A dry run walks the whole tree, resolving every node type and property against the node types, checking that each asset reference is declared and each asset file is there, and writes nothing. It reports no removal count.

It cannot check the Content Repository's own constraints — whether this node type may sit under that one is answered by handling the command, and a dry run issues none. **It is a proofread, not a rehearsal.**

### Export XML schemas

Use `cr:exportxsd` to write XML schemas for the installed node types, so that an IDE validates a manifest and completes node types, properties and select box values as it is written.

| Argument            | Description                                                              | Example     |
| ------------------- | ------------------------------------------------------------------------ | ----------- |
| `target`            | Optional. Directory to write to, relative to the current directory. Defaults to `Schema`. | `Schema` |
| `contentRepository` | Optional. Whose node types to read. Defaults to `default`.               | `default`   |

```sh
flow cr:exportxsd --target Schema
```

| File                | Contents                                                                 |
| ------------------- | ------------------------------------------------------------------------ |
| `<PackageKey>.xsd`  | One per package declaring at least one non-abstract node type            |
| `all.xsd`           | Imports the others plus the manifest schema; an entry point for `xmllint` |

The command neither reads nor rewrites a manifest, and does not write the manifest schema — that ships with this package in `Schema/manifest.xsd` and stays there.

#### Association

A manifest needs no `xsi:schemaLocation`, and the project needs no external-resource mapping or XML catalog. An IDE resolves a namespace by scanning the project for a matching `targetNamespace`, and that scan reaches into the Composer install directory even when it is gitignored.

Every `xs:import` in a generated schema carries a `schemaLocation`, and must: an IDE does not follow a location-less import, and without one a substitution group member declared in another package is never seen.

No schema is ever copied. Two schemas sharing a `targetNamespace` resolve silently and arbitrarily, so there is exactly one of each in a project, and the generated schemas go to one shared directory rather than beside each manifest.

#### What the schemas express

- **Which node types exist**, one element per non-abstract one, root node types excluded.
- **Which properties each takes**, as attributes and as unqualified elements, typed.
- **Which values a select box allows**, as an enumeration, in both forms.
- **What may go inside what**, from the same rules the importer applies.
- **Which document types a `crm:page` accepts**, through the `crm:document` substitution group.
- **The content group**, `crm:content`, which every node type a plain content collection accepts enrols into. A container taking exactly that set references the head; one taking less lists what it takes.

The node type name and each property's declared type are emitted as `xs:documentation`.

Not emitted:

- **References.** The format cannot set one.
- **Underscore-prefixed properties.** Neos declares `_nodeType`, `_hidden` and the like so the inspector renders an editor, then intercepts them by name and issues something other than a property write. Setting one stores a value nothing reads.

A node type with several content collections gets the union of their constraints, which is wider than the importer allows.

#### Regenerating

The schemas follow the installed packages. Re-run the command after changing a `NodeTypes.yaml` or installing a package. Output is ordered, so a regenerated set diffs cleanly.

**An IDE may keep serving the previous schemas.** These are written from a CLI, so a manifest can go on being validated against the old ones until the change is indexed.

### Passing property values

`propertyValues` is a JSON object of property name => value.

**Attach the value with `=`.** A value containing an `=` is truncated in the detached form, because Flow glues the option name onto the value and then splits on the first `=` it finds. Any HTML attribute in a payload trips this:

```
flow cr:createnodeaggregate ... --property-values '{"text": "<a href=\"/x\">y</a>"}'   # arrives as: \"/x\">y</a>"}
flow cr:createnodeaggregate ... --property-values='{"text": "<a href=\"/x\">y</a>"}'   # correct
```

**Assets and other persisted objects are referenced by class and identifier.** A property the node type declares as an entity holds the object itself, and the Content Repository accepts nothing else. The identifier alone cannot express it, because the declared type is usually an interface — `Neos\Media\Domain\Model\ImageInterface` — that Doctrine cannot map to a table, so the concrete class travels with it:

```
flow cr:createnodeaggregate ... --property-values='{"image": {"__flow_object_type": "Neos\\Media\\Domain\\Model\\Image", "__identifier": "…"}}'
```

That is the same shape the Content Repository writes when it serializes such a property, so a value read out of a node can be fed straight back in — and it is what `cr:importasset --reference` prints. A reference to something that no longer exists is an error rather than a `null`, which the Content Repository would read as "unset this property".

A bare string is never taken for an identifier. Which properties are entities is not knowable from the value, and a string quietly turned into a failed lookup reports worse than the Content Repository does.

**Dates are converted for you.** JSON has no date type, so a property the node type declares as `DateTime` (or `DateTimeImmutable`, or `DateTimeInterface`) is read from its string and passed on as a `\DateTimeImmutable`; the Content Repository rejects a plain string there. Every other value is passed through as it arrived, and the Content Repository decides whether it fits — so `{"title": "2026-08-19"}` stays a string.

## Development

```
composer install
composer lint   # PHP CodeSniffer (PSR-12) and PHPStan
composer test   # PHPUnit
```

CI runs both against PHP 8.3, 8.4 and 8.5.
