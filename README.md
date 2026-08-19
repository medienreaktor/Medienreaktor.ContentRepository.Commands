# Medienreaktor.ContentRepository.Commands

CLI Commands for the Event Sourced Content Repository of Neos CMS.

**Note:** This package is still work in progress. Use with care.

## Commands

The `create` / `setnodeproperties` / `remove` commands directly dispatch Commands on the Content Repository. The Content Repository handles the Command and emits the Event to the Event Store. The `find` commands read the content graph, so that a script can locate the nodes it needs to act on. `importasset` puts a file into the media library, so that a script can fill an asset property.

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
| `file`      | Path to the file to import, absolute or relative to the current directory                                | `seed/hero.png`                              |
| `title`     | Optional. Title of the asset, as it appears in the media browser. Defaults to the file name.             | `Hero`                                       |
| `reference` | Optional. Print the property value that refers to the asset instead of the bare identifier.              | —                                            |

A node property declared as an asset holds the asset itself, so seeding such a node means importing the file first. Neos has no command for that: `media:importresources` picks up resources Flow already knows about, which is the second half of the job rather than this one.

The asset type follows the file, through the same mapping strategy the media library uses — a PNG becomes an `Image`, a PDF a `Document`.

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
