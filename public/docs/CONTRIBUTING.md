# Contributing to API Documentation

This guide explains how to maintain and update the OpenAPI documentation for Jexpanel.

## Overview

The API documentation is built using OpenAPI 3.0 specification and consists of:
- `openapi.yaml` - The main specification file
- `index.html` - Swagger UI viewer
- `redoc.html` - ReDoc viewer
- `validate.sh` - Validation script

## Updating Existing Endpoints

When modifying an existing API endpoint:

1. Locate the endpoint in `openapi.yaml` under the `paths` section
2. Update the relevant fields (parameters, request body, responses, etc.)
3. Run validation: `./public/docs/validate.sh`
4. Test the documentation by viewing in Swagger UI or ReDoc

### Example: Adding a Query Parameter

```yaml
/client/servers:
  get:
    parameters:
      - name: new_param
        in: query
        description: Description of the new parameter
        required: false
        schema:
          type: string
```

## Adding New Endpoints

When adding a new API endpoint:

1. Add the endpoint under the appropriate path in the `paths` section
2. Include all HTTP methods (GET, POST, PUT, DELETE, etc.)
3. Document all parameters, request bodies, and responses
4. Reference existing schemas where possible
5. Add appropriate tags for organization
6. Run validation

### Template for New Endpoint

```yaml
/path/to/endpoint:
  get:
    tags:
      - Tag Name
    summary: Short description
    description: Detailed description
    operationId: uniqueOperationId
    parameters:
      - name: param_name
        in: path/query/header
        required: true/false
        description: Parameter description
        schema:
          type: string/integer/boolean
    responses:
      '200':
        description: Success response
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/SchemaName'
      '401':
        description: Unauthorized
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/Error'
```

## Adding New Schemas

When adding a new data model:

1. Add the schema under `components/schemas`
2. Use descriptive property names
3. Include types and format information
4. Mark required fields
5. Add examples where helpful

### Template for New Schema

```yaml
components:
  schemas:
    NewModel:
      type: object
      required:
        - required_field
      properties:
        required_field:
          type: string
          description: Field description
        optional_field:
          type: integer
          nullable: true
        created_at:
          type: string
          format: date-time
```

## Validation

Always validate your changes before committing:

```bash
# Run the validation script
./public/docs/validate.sh

# Or manually validate with Python
python3 -c "import yaml; yaml.safe_load(open('public/docs/openapi.yaml'))"
```

## Testing Documentation

1. Start a local web server:
   ```bash
   cd public/docs
   python3 -m http.server 8000
   ```

2. Open in browser:
   - Swagger UI: http://localhost:8000/
   - ReDoc: http://localhost:8000/redoc.html

3. Test "Try it out" functionality with real API endpoints

## Best Practices

### Naming Conventions
- Use `camelCase` for operationId
- Use `snake_case` for parameter names
- Use descriptive, clear names

### Descriptions
- Keep summaries short (one line)
- Use descriptions for detailed information
- Include examples for complex parameters
- Document edge cases and limitations

### Response Codes
Always document these standard responses:
- `200` - Success (GET, PUT, PATCH)
- `201` - Created (POST)
- `204` - No Content (DELETE)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Internal Server Error

### Security
- Document authentication requirements
- Specify required permissions
- Note any rate limiting

### Examples
Include examples for:
- Complex request bodies
- Nested objects
- Array parameters
- Response payloads

## Tools & Resources

### Online Editors
- [Swagger Editor](https://editor.swagger.io/) - Edit and validate OpenAPI specs
- [Stoplight Studio](https://stoplight.io/studio) - Visual OpenAPI editor

### Validators
- [OpenAPI Validator](https://apitools.dev/swagger-parser/online/)
- [IBM OpenAPI Validator](https://github.com/IBM/openapi-validator)

### Reference
- [OpenAPI 3.0 Specification](https://swagger.io/specification/)
- [OpenAPI Best Practices](https://swagger.io/blog/api-development/openapi-best-practices/)

## Common Tasks

### Adding Authentication to an Endpoint
All endpoints inherit the global security scheme, but you can override:

```yaml
/path:
  get:
    security: []  # No authentication required
```

### Documenting Pagination
Use the existing Pagination schema:

```yaml
responses:
  '200':
    content:
      application/json:
        schema:
          properties:
            data:
              type: array
              items:
                $ref: '#/components/schemas/YourModel'
            meta:
              type: object
              properties:
                pagination:
                  $ref: '#/components/schemas/Pagination'
```

### Adding Filtering Parameters
Document query parameters for filtering:

```yaml
parameters:
  - name: filter[field]
    in: query
    description: Filter by field value
    schema:
      type: string
```

## Troubleshooting

### YAML Syntax Errors
- Check indentation (use 2 spaces, not tabs)
- Ensure proper quoting of special characters
- Validate colons and dashes

### Schema References Not Working
- Ensure the schema exists in `components/schemas`
- Check the reference path: `#/components/schemas/SchemaName`
- Schema names are case-sensitive

### Changes Not Reflecting
- Clear browser cache (Ctrl+Shift+R)
- Check YAML file was saved
- Restart development server if running locally

## Questions?

For questions about the API documentation:
- Open an issue on GitHub
- Ask in the Discord server
- Check the [OpenAPI documentation](https://swagger.io/specification/)
