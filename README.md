# Networkteam.Neos.ContentApi

## Concepts

### Site handling

The content API will use the current domain / site of the request, so make sure to call the API via the correct domain when in a multi-site installation.

## Examples

### Adding an API prototype for document node types:

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

The idea here is to have a `.Api` prototype for the complete document
node type hierarchy.
Content as well as arbitrary data can be added to the prototype and will be
serialized as JSON.

### Extending API site properties

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

This can be fetched via the `/content-api/site` endpoint for the current site (depends on domain).

## API endpoints

### `/content-api/documents`

### `/content-api/render`

Render a document given by `path`.

### `/content-api/site`
