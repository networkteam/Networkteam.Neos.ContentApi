# Networkteam.Neos.ContentApi

## Concepts

### Site handling

The content API will use the current domain / site of the request, so make sure to call the API via the correct domain when in a multi-site installation.

### Extensibility via Fusion

The API responses are declared by Fusion prototypes. This allows for a high degree of flexibility and customization.

### Simple API

This package does not offer a complex API for querying nodes.
It is mainly focused on rendering pages backed by document nodes when using Neos as a headless CMS. It is also perfect to
pair with [@networkteam/zebra](https://github.com/networkteam/zebra) and supports full editing of nodes with visual editing.

A node can be fetched either by path (for public access) or by context path (for access with preview in workspaces).

## Features

* Support for multi-site installations
* Supports dimensions (e.g. for multi-language sites)
* Supports [Flowpack.Neos.DimensionResolver](https://github.com/Flowpack/neos-dimensionresolver) for flexible dimension routing
* Supports Neos.RedirectHandler (if installed) and `checkRedirects` is enabled in settings

## Configuration

```yaml
Networkteam:
  Neos:
    ContentApi:
      recursiveReferencePropertyDepth: 1
      documentList:
        ignoredNodeTypes:
          - 'Neos.Neos:Shortcut'
      # Enable to check redirects of RedirectsHandler (if package is available) if a node is not found
      checkRedirects: false
```

## Examples

### Adding an API prototype for document node types:

```neosfusion
prototype(Neos.Demo:Document.Page.Api) < prototype(Networkteam.Neos.ContentApi:DefaultDocument) {
	content {
		main = Neos.Neos:ContentCollection {
			nodePath = 'main'
		}
		teaser = Neos.Neos:ContentCollection {
			nodePath = 'teaser'
		}
	}
}
prototype(Neos.Demo:Document.LandingPage.Api) < prototype(Neos.Demo:Document.Page.Api)
prototype(Neos.Demo:Document.Homepage.Api) < prototype(Neos.Demo:Document.Page.Api)
```

The idea here is to have a `.Api` prototype for the complete document
node type hierarchy.
Content as well as arbitrary data can be added to the prototype and will be
serialized as JSON.

### Extending API site properties

```neosfusion
contentApi {
	site {
		# Set some additional context variables for default Fusion to work correctly
		@context {
			documentNode = ${site}
			node = ${site}
		}
		navigation = Neos.Fusion:DataStructure {
			mainItems = Neos.Fusion:Map {
				items = ${q(site).children('[instanceof Neos.Neos:Document][_hiddenInIndex=false]')}
				itemName = 'node'
				itemRenderer = Neos.Fusion:DataStructure {
					title = ${q(node).property('title')}
					renderPath = Neos.Neos:NodeUri {
						node = ${node}
						format = 'html'
					}
				}
			}
		}
		content {
			footer = Neos.Neos:ContentCollection {
				nodePath = 'footer'
			}
		}
	}
}
```

This can be fetched via the `/content-api/site` endpoint for the current site (depends on domain).

### Provide a query for list data

```neosfusion
contentApi {
  queries {
    # Declare a simple query that can be used to fetch articles
    articles = Networkteam.Neos.ContentApi:Query.FlowQuery {
      items = ${q(site).find('[instanceof Zebra.Site:Document.Article]')}
      itemName = 'node'
      itemRenderer = Networkteam.Neos.ContentApi:BaseNode

      page = ${params.pagination.page || 0}
      perPage = ${params.pagination.perPage || 3}
    }
  }
}
```

This query can be fetched via the `/content-api/query/articles` endpoint.
It uses the predefined `Networkteam.Neos.ContentApi:Query.FlowQuery` to fetch data based on a `FlowQuery` expression.

> Note: For more complex queries, you can create your own query implementation, e.g. based on a search implementation
> for more efficient queries.

## API endpoints

### `/neos/content-api/documents`

Lists available documents with route path / context path and iterating through dimensions.

A different workspace can be selected via `workspaceName` (needs authentication).

> Note: If `Flowpack.Neos.DimensionResolver` already resolved dimensions e.g. based on the domain, then the dimensions are not iterated.

### `/neos/content-api/document`

Render a document given by `path` or `contextPath`.

### `/neos/content-api/node`

Render a single node given by `contextPath`.

### `/neos/content-api/site`

Render site properties independent of a single node.

### `/neos/content-api/query/{queryName}`

Fetch data for a predefined query.

**Query Parameters:**

- `params`: Parameters for the query (filter, sorting, pagination, etc.). It is dependent on the Fusion implementation which exact parameters are supported.
- `workspaceName`: Workspace name for node context (defaults to live)
- `dimensions`: Dimensions for node context

**Response:**

Query implementations should return a JSON result that contains a list of data and meta information:

```json
{
  "data": [{ ... }],
  "meta": {
    "total": 0
  }
}
```
