# Medienreaktor.ContentRepository.Commands

CLI Commands for the Event Sourced Content Repository of Neos CMS.

**Note:** This package is still work in progress. Use with care.

## Commands

The CLI Commands directly dispatch Commands on the Content Repository. The Content Repository handles the Command and emits the Event to the Event Store.

### Create node aggregate

Use `cr:createnodeaggregate` to create a new node aggregate.

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

This makes scripted content setup idempotent: remove a collection's children, then recreate them. Without it, a seed script can only append.

#### This is a hard removal

The node is really gone from the workspace it was removed in. That is *not* what the Neos UI does when an editor deletes a node — the UI tags the subtree as removed, so that the deletion can still be published or discarded like any other change. A hard removal in a user workspace cannot be discarded, so unless you know you want the event-level behaviour, remove in `live` and leave editorial deletions to the UI.

#### `nodeVariantSelectionStrategy`

A node aggregate can cover several dimension space points, and the strategy decides which of them the removal reaches beyond the one named in `coveredDimensionSpacePoint`. Consider a site with `de`, its specialization `gsw`, and `fr` as a peer of `de`:

| Value                | Removing in `de` also removes | Notes                                                                    |
| -------------------- | ----------------------------- | ------------------------------------------------------------------------ |
| `allVariants`        | `gsw` and `fr`                | Default. Every point the aggregate covers. Required for root nodes.      |
| `allSpecializations` | `gsw`                         | The given point and everything more specific. What the Neos UI issues.   |

The default is `allVariants`, which is what a seed script wiping a collection wants. Pass `allSpecializations` when peer variants — a separate translation, say — have to survive.

### Passing property values

`propertyValues` is a JSON object of property name => value. Two things about it are worth knowing.

**Attach the value with `=`.** A value containing an `=` is truncated in the detached form, because Flow glues the option name onto the value and then splits on the first `=` it finds. Any HTML attribute in a payload trips this:

```
flow cr:createnodeaggregate ... --property-values '{"text": "<a href=\"/x\">y</a>"}'   # arrives as: \"/x\">y</a>"}
flow cr:createnodeaggregate ... --property-values='{"text": "<a href=\"/x\">y</a>"}'   # correct
```

**Dates are converted for you.** JSON has no date type, so a property the node type declares as `DateTime` (or `DateTimeImmutable`, or `DateTimeInterface`) is read from its string and passed on as a `\DateTimeImmutable`; the Content Repository rejects a plain string there. Every other value is passed through as it arrived, and the Content Repository decides whether it fits — so `{"title": "2026-08-19"}` stays a string.

## Development

```
composer install
composer lint   # PHP CodeSniffer (PSR-12) and PHPStan
composer test   # PHPUnit
```

CI runs both against PHP 8.3, 8.4 and 8.5.
