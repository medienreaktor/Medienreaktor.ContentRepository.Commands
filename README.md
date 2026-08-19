# Medienreaktor.ContentRepository.Commands

CLI Commands for the Event Sourced Content Repository of Neos CMS.

**Note:** This package is still work in progress. Use with care.

## Commands

The `create` / `setnodeproperties` / `remove` commands directly dispatch Commands on the Content Repository. The Content Repository handles the Command and emits the Event to the Event Store. The `find` commands read the content graph, so that a script can locate the nodes it needs to act on. `importasset` puts a file into the media library, so that a script can fill an asset property.

Those are primitives, composed by the caller. `importxml` is the one command that is not: it takes a file describing a whole content tree and makes that tree exist. Where a seed built from the primitives is a script that has to get its order, its ids and its shell quoting right, the same seed as XML is a document — see [Import a content tree](#import-a-content-tree).

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

Use `cr:importxml` to import a content tree from a seed XML file.

| Argument        | Description                                          | Example                     |
| --------------- | ---------------------------------------------------- | --------------------------- |
| `file`          | Path to the seed XML file                            | `seed/LandingPage.xml`      |
| `workspaceName` | Optional. The workspace to write to. Defaults to `live`. | `live`                  |
| `dryRun`        | Optional. Report what the import would do, and write nothing. | —                  |

```sh
flow cr:importxml --file seed/LandingPage.xml
```

The file describes the tree it wants to exist, with node types as element names:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<seed:site
    xmlns:seed="https://medienreaktor.de/ns/neos-seed/1.0"
    xmlns:prop="https://medienreaktor.de/ns/neos-seed/1.0/property"
    xmlns:Acme.Site="https://acme.example/ns/nodetypes/Acme.Site"
    name="site" contentRepository="default" dimension="language=de">

  <seed:manifest>
    <seed:asset id="hero" href="images/hero.png" title="Hero"/>
  </seed:manifest>

  <seed:page path="/">
    <Acme.Site:Document.Page.Homepage>
      <Acme.Site:Content.Hero image="hero" alternativeText="Rectangle 85">
        <prop:title>We power <span class="highlight">freedom.</span></prop:title>
      </Acme.Site:Content.Hero>
      <Acme.Site:Content.Grid columns="2" layout="6-6">
        <Acme.Site:Content.Grid.Cell>
          <Acme.Site:Content.Teaser number="01" title="Military"/>
        </Acme.Site:Content.Grid.Cell>
      </Acme.Site:Content.Grid>
    </Acme.Site:Document.Page.Homepage>
  </seed:page>
</seed:site>
```

#### The element name is the node type name

A QName holds at most one colon and a node type name holds exactly one, so the package key becomes the namespace prefix and the rest the local name. Dots are legal in an NCName, so `Acme.Site:Content.Grid.Cell` survives intact and the element reads as the node type does in `NodeTypes.yaml`.

**The namespace URI carries the package key, not the prefix.** XML treats a prefix as arbitrary and reassignable — a formatter may rewrite `Acme.Site:` to `ns0:` and mean the same document — so the package key is read out of the URI, which has to end in `/ns/nodetypes/<PackageKey>`. Writing the prefix to match the package key is a convention for the reader, not a requirement. The host is free, so `Neos.Neos` can live under `neos.io` and a site package under its own vendor's domain.

#### A property is an attribute or a `prop:` element

Both end up in the same place. An attribute suits a short scalar; a `prop:` element holds markup literally, which an attribute can only do escaped past legibility:

```xml
<Acme.Site:Content.Heading>
  <prop:title><h2>We power <span class="highlight">freedom.</span></h2></prop:title>
</Acme.Site:Content.Heading>
```

Setting the same property both ways is an error rather than a precedence rule, because a silent winner is how an edit gets ignored. Leading and trailing whitespace around a `prop:` element's content is indentation and dropped; whitespace inside it is content and kept.

Values are converted to the type the node type declares, so `showDash="true"` arrives as a boolean and `width="7"` as an integer if that is how they are declared. A value that does not fit is reported with the property name rather than cast: `(bool) "false"` is `true`, and a dash nobody asked for takes an afternoon to find.

#### What the import does at each level

The file is the desired state, and the level says what making it true means:

- **A document named by a page path is matched, never created.** In Neos 9 the site node is itself a document — the homepage — and `site:create` made it. The import checks that the node type matches what the file says, so a file written for another page fails before anything is removed.
- **Content is rebuilt.** The children of every container the file describes are removed and written again in document order. A seed that appended would double its content on the second run, and one that merged would need identity the file does not carry. **So an editor's changes under a seeded collection are lost** — which is the trade a seed makes, and the reason not to point this at a site being worked on.

Tethered nodes are never removed, because the node type says they are always there. Their content is rebuilt when the file describes it.

#### Where content goes is derived from the node type

`main` is not a Neos fact: core declares no tethered node by that name, and each site package repeats the convention for itself. So rather than assuming it, the import asks the node type. For a node whose children are content:

1. the node type **is** a content collection — children go directly into it;
2. it has **exactly one** tethered content collection — children go there;
3. it has **several** — the file has to say which, with `seed:name`;
4. it has **none** — children go directly into it.

That covers `main` on every document type without naming it, and `content` on an element that has one. Case 3 is the one worth having: an element with `column0` and `column1` would otherwise take a guess, and content silently landing in the first column looks like a rendering bug.

Where a name is needed, or wanted for clarity, `seed:name` gives it:

```xml
<Acme.Site:Content.Columns.Two>
  <Neos.Neos:ContentCollection seed:name="column1">
    <Acme.Site:Content.Text text="Left."/>
  </Neos.Neos:ContentCollection>
</Acme.Site:Content.Columns.Two>
```

#### Assets are declared once and referenced by id

`<seed:manifest>` lists the files the page needs; content refers to them by id (`image="hero"`). The ids are local to the file, which is what keeps it portable: an asset identifier differs in every database, an id does not. A relative `href` is resolved against the directory of the XML file, not the working directory of the command. Importing deduplicates on content, so re-running a seed does not fill the media library with copies.

#### `--dry-run`

Unlike the other commands here this one takes a dry run, because it is a whole file rather than a single operation and there is a real question of whether it will read. A dry run walks the whole tree, resolving every node type and every property against it, checking that each asset reference is declared and each manifest file is there, and writes nothing.

It can walk the whole tree because everything it checks follows from the node types rather than from any node existing — including which collection children belong in, so an element with two of them fails here rather than halfway through a real run.

What it cannot check is the Content Repository's own constraints: whether this node type may sit under that one is answered by handling the command, and a dry run issues none. **It is a proofread, not a rehearsal** — a clean dry run does not promise a clean import.

It reports no removal count, because it does not read the content that is already there.

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
