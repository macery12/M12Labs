# Jexpanel API Documentation

This directory contains the OpenAPI (formerly Swagger) documentation for the Jexpanel API.

## 📚 Documentation Files

### Core Documentation
- **[openapi.yaml](openapi.yaml)** - The OpenAPI 3.0 specification file that defines all API endpoints
- **[index.html](index.html)** - Swagger UI interface for interactive API documentation
- **[redoc.html](redoc.html)** - ReDoc interface for a clean, three-panel documentation layout

### Guides
- **[QUICKSTART.md](QUICKSTART.md)** - ⭐ Start here! Quick start guide with examples
- **[POSTMAN.md](POSTMAN.md)** - How to use the API with Postman
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - Guide for maintaining and updating the documentation

### Tools
- **[validate.sh](validate.sh)** - Script to validate the OpenAPI specification

## Accessing the Documentation

Once your Jexpanel instance is running, you can access the API documentation at:

- **Swagger UI**: `https://your-panel-domain.com/docs/` or `https://your-panel-domain.com/docs/index.html`
- **ReDoc**: `https://your-panel-domain.com/docs/redoc.html`
- **Raw OpenAPI Spec**: `https://your-panel-domain.com/docs/openapi.yaml`

## Documentation Viewers

### Swagger UI (index.html)
- Interactive interface with "Try it out" functionality
- Allows you to test API endpoints directly from the browser
- Great for developers who want to experiment with the API

### ReDoc (redoc.html)
- Clean, three-panel layout
- Easier to read and navigate for reference
- Better for documentation consumers

## API Authentication

The Jexpanel API uses Bearer token authentication. To use the API:

1. Generate an API key from your panel:
   - For Application API: Admin panel → API Keys
   - For Client API: Account settings → API Keys

2. Include the API key in your requests:
   ```
   Authorization: Bearer YOUR_API_KEY
   ```

## API Types

Jexpanel has three main API types:

### Application API (`/api/application/*`)
Administrative API for managing the panel. Requires admin privileges.

**Use cases:**
- Managing users, servers, nodes
- Configuring billing settings
- Managing nests and eggs
- Viewing analytics and metrics

### Client API (`/api/client/*`)
User-facing API for managing servers and accounts.

**Use cases:**
- Managing your servers (start, stop, restart)
- Updating account settings
- Managing API keys and SSH keys
- Purchasing products
- Creating support tickets

### Remote API (`/api/remote/*`)
API for daemon communication. Internal use only.

## Updating the Documentation

The `openapi.yaml` file follows the OpenAPI 3.0 specification. To update the documentation:

1. Edit the `openapi.yaml` file
2. Validate your changes using an OpenAPI validator (e.g., https://editor.swagger.io/)
3. The changes will be reflected immediately in both Swagger UI and ReDoc

## Tools for Working with OpenAPI

- **Swagger Editor**: https://editor.swagger.io/ - Online editor with validation
- **Postman**: Can import OpenAPI specs for API testing
- **Insomnia**: Alternative API client with OpenAPI support
- **OpenAPI Generator**: Generate client SDKs in various languages

## Contributing

When adding new API endpoints:

1. Update the `openapi.yaml` file with the new endpoint
2. Include request/response examples
3. Document all parameters and response codes
4. Update relevant schemas in the `components/schemas` section
5. Add appropriate tags for organization

## Resources

- OpenAPI Specification: https://swagger.io/specification/
- Swagger UI Documentation: https://swagger.io/tools/swagger-ui/
- ReDoc Documentation: https://github.com/Redocly/redoc
- Jexpanel GitHub: https://github.com/Jexactyl/Jexactyl
- Jexpanel Website: https://jexpanel.com
