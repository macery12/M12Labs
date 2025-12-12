# Jexpanel API Quick Start Guide

This guide will help you get started with the Jexpanel API quickly.

## Prerequisites

Before using the API, you need:
1. A Jexpanel instance running
2. An API key (Application or Client API key depending on your needs)
3. An HTTP client (curl, Postman, or any programming language with HTTP support)

## Getting Your API Key

### Application API Key (Admin)
1. Log in to your Jexpanel admin panel
2. Navigate to **Application → API**
3. Click "Create New" to generate a new API key
4. Copy the key immediately (it's only shown once!)
5. Store it securely

### Client API Key (User)
1. Log in to your Jexpanel account
2. Navigate to **Account → API Keys**
3. Click "Create API Key"
4. Provide a description and allowed IPs (optional)
5. Copy the generated key

## Making Your First Request

### Example: List Your Servers (Client API)

```bash
curl -X GET \
  'https://panel.example.com/api/client' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json'
```

### Example: List All Users (Application API)

```bash
curl -X GET \
  'https://panel.example.com/api/application/users' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json'
```

## Common Operations

### Create a User (Application API)

```bash
curl -X POST \
  'https://panel.example.com/api/application/users' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "user@example.com",
    "username": "newuser",
    "first_name": "John",
    "last_name": "Doe",
    "password": "SecurePassword123",
    "root_admin": false
  }'
```

### Update Account Email (Client API)

```bash
curl -X PUT \
  'https://panel.example.com/api/client/account/email' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "newemail@example.com",
    "password": "YourCurrentPassword"
  }'
```

### Create a Node (Application API)

```bash
curl -X POST \
  'https://panel.example.com/api/application/nodes' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Node 1",
    "description": "Primary node",
    "location_id": 1,
    "fqdn": "node1.example.com",
    "scheme": "https",
    "memory": 8192,
    "disk": 102400,
    "daemon_base": "/var/lib/pterodactyl/volumes",
    "daemon_listen": 8080,
    "daemon_sftp": 2022
  }'
```

### Get Billing Categories (Client API)

```bash
curl -X GET \
  'https://panel.example.com/api/client/billing/categories' \
  -H 'Authorization: Bearer YOUR_API_KEY' \
  -H 'Accept: application/json'
```

## Response Format

Jexpanel API responses follow a consistent format:

### Success Response (Single Object)
```json
{
  "object": "user",
  "attributes": {
    "id": 1,
    "email": "user@example.com",
    "username": "johndoe",
    "first_name": "John",
    "last_name": "Doe",
    "created_at": "2024-01-01T00:00:00+00:00"
  }
}
```

### Success Response (List)
```json
{
  "object": "list",
  "data": [
    {
      "object": "user",
      "attributes": { ... }
    }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "total": 100,
      "per_page": 50
    }
  }
}
```

### Error Response
```json
{
  "errors": [
    {
      "code": "ValidationException",
      "status": "422",
      "detail": "The email field is required."
    }
  ]
}
```

## Pagination

For endpoints that return lists, use pagination parameters:

```bash
curl -X GET \
  'https://panel.example.com/api/client?page=2&per_page=25' \
  -H 'Authorization: Bearer YOUR_API_KEY'
```

## Filtering

Many endpoints support filtering:

```bash
# Filter servers by name
curl -X GET \
  'https://panel.example.com/api/client?filter[name]=minecraft' \
  -H 'Authorization: Bearer YOUR_API_KEY'

# Filter users by email
curl -X GET \
  'https://panel.example.com/api/application/users?filter[email]=admin@example.com' \
  -H 'Authorization: Bearer YOUR_API_KEY'
```

## Error Handling

Always check the HTTP status code:

- **200 OK** - Request successful
- **201 Created** - Resource created successfully
- **204 No Content** - Resource deleted successfully
- **400 Bad Request** - Invalid request format
- **401 Unauthorized** - Invalid or missing API key
- **403 Forbidden** - Insufficient permissions
- **404 Not Found** - Resource doesn't exist
- **422 Unprocessable Entity** - Validation failed
- **429 Too Many Requests** - Rate limit exceeded
- **500 Internal Server Error** - Server error

## Rate Limiting

The API implements rate limiting. Check these response headers:

- `X-RateLimit-Limit` - Total requests allowed
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - When the limit resets (Unix timestamp)

If you exceed the rate limit, you'll receive a `429` status code.

## Code Examples

### Python

```python
import requests

API_KEY = 'your_api_key'
BASE_URL = 'https://panel.example.com/api'

headers = {
    'Authorization': f'Bearer {API_KEY}',
    'Content-Type': 'application/json',
    'Accept': 'application/json'
}

# List servers
response = requests.get(f'{BASE_URL}/client', headers=headers)
servers = response.json()

print(f"Found {len(servers['data'])} servers")
```

### JavaScript (Node.js)

```javascript
const axios = require('axios');

const API_KEY = 'your_api_key';
const BASE_URL = 'https://panel.example.com/api';

const api = axios.create({
  baseURL: BASE_URL,
  headers: {
    'Authorization': `Bearer ${API_KEY}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// List servers
api.get('/client')
  .then(response => {
    console.log(`Found ${response.data.data.length} servers`);
  })
  .catch(error => {
    console.error('Error:', error.response.data);
  });
```

### PHP

```php
<?php

$apiKey = 'your_api_key';
$baseUrl = 'https://panel.example.com/api';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/client");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json",
    "Accept: application/json"
]);

$response = curl_exec($ch);
$servers = json_decode($response, true);

echo "Found " . count($servers['data']) . " servers\n";

curl_close($ch);
```

## Best Practices

1. **Always use HTTPS** - Never send API keys over unencrypted connections
2. **Store API keys securely** - Use environment variables, not hardcoded strings
3. **Handle rate limits gracefully** - Implement exponential backoff
4. **Validate input** - Check data before sending to the API
5. **Handle errors properly** - Always check response status codes
6. **Use pagination** - Don't try to load all data at once
7. **Cache when appropriate** - Reduce unnecessary API calls
8. **Set timeouts** - Don't wait indefinitely for responses

## Testing with Swagger UI

The easiest way to test the API is using the built-in Swagger UI:

1. Navigate to `https://your-panel.com/docs/`
2. Click the "Authorize" button at the top
3. Enter your API key as: `Bearer YOUR_API_KEY`
4. Explore and test endpoints interactively

## Need Help?

- **Documentation**: https://panel.example.com/docs/
- **GitHub**: https://github.com/Jexactyl/Jexactyl
- **Discord**: https://discord.gg/qttGR4Z5Pk
- **Website**: https://jexpanel.com

## Next Steps

1. Read the full API documentation at `/docs/`
2. Explore the OpenAPI specification at `/docs/openapi.yaml`
3. Check out example integrations in the GitHub repository
4. Join the Discord community for support

Happy coding! 🚀
