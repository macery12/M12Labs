# Using the API with Postman

[Postman](https://www.postman.com/) is a popular API testing tool. This guide shows you how to import and use the Jexpanel API in Postman.

## Importing the OpenAPI Specification

### Method 1: Import from URL (Recommended)

1. Open Postman
2. Click **Import** in the top left
3. Select **Link** tab
4. Enter: `https://your-panel.com/docs/openapi.yaml`
5. Click **Continue** then **Import**

### Method 2: Import from File

1. Download the OpenAPI spec:
   ```bash
   wget https://your-panel.com/docs/openapi.yaml
   ```
2. Open Postman
3. Click **Import** in the top left
4. Drag and drop the `openapi.yaml` file
5. Click **Import**

## Setting Up Authentication

After importing, you need to configure authentication:

### Option 1: Collection-Level Authentication

1. Select the imported collection
2. Go to the **Authorization** tab
3. Select **Type**: Bearer Token
4. Enter your API key in the **Token** field
5. Save

All requests will now automatically include your API key.

### Option 2: Environment Variables (Recommended)

1. Click the eye icon (👁️) in the top right
2. Click **Add** to create a new environment
3. Name it "Jexpanel Production" or similar
4. Add these variables:

   | Variable | Initial Value | Current Value |
   |----------|--------------|---------------|
   | `base_url` | `https://your-panel.com/api` | (same) |
   | `api_key` | `your_api_key_here` | (same) |

5. Save the environment
6. Select it from the environment dropdown

Then, in your collection:
1. Go to **Authorization** tab
2. Type: **Bearer Token**
3. Token: `{{api_key}}`

And update the base URL to use `{{base_url}}`

## Example Requests

### List Servers

```
GET {{base_url}}/client
Authorization: Bearer {{api_key}}
```

### Create User

```
POST {{base_url}}/application/users
Authorization: Bearer {{api_key}}
Content-Type: application/json

{
  "email": "user@example.com",
  "username": "newuser",
  "first_name": "John",
  "last_name": "Doe",
  "password": "SecurePassword123"
}
```

### Get Billing Categories

```
GET {{base_url}}/client/billing/categories
Authorization: Bearer {{api_key}}
```

## Creating Collections

You can organize requests into collections:

### Client API Collection
- Account Management
  - Get Account
  - Update Email
  - Update Password
  - Manage 2FA
- Server Management
  - List Servers
  - Server Details
- Billing
  - List Categories
  - List Products
  - Process Payment

### Application API Collection
- Users
  - List Users
  - Create User
  - Update User
  - Delete User
- Nodes
  - List Nodes
  - Create Node
  - Update Node
  - Delete Node
- Servers
  - List Servers
  - Server Details
- Billing
  - Categories
  - Products
  - Orders

## Testing Workflows

Use Postman's test scripts to validate responses:

```javascript
// Check status code
pm.test("Status code is 200", function () {
    pm.response.to.have.status(200);
});

// Check response structure
pm.test("Response has data array", function () {
    var jsonData = pm.response.json();
    pm.expect(jsonData).to.have.property('data');
    pm.expect(jsonData.data).to.be.an('array');
});

// Save ID for next request
pm.test("Save user ID", function () {
    var jsonData = pm.response.json();
    pm.environment.set("user_id", jsonData.attributes.id);
});
```

## Pre-request Scripts

Add common headers automatically:

```javascript
pm.request.headers.add({
    key: 'Accept',
    value: 'application/json'
});

pm.request.headers.add({
    key: 'Content-Type',
    value: 'application/json'
});
```

## Postman Collection Variables

For dynamic values:

```javascript
// Generate random email
pm.collectionVariables.set("random_email", 
    `user${Math.floor(Math.random() * 10000)}@example.com`);

// Generate timestamp
pm.collectionVariables.set("timestamp", 
    new Date().toISOString());
```

## Running Collections

### Manual Testing
1. Open a request
2. Click **Send**
3. View response

### Collection Runner
1. Right-click collection
2. Select **Run collection**
3. Choose requests to run
4. Click **Run**

### Newman (CLI)
Export your collection and run via command line:

```bash
# Install Newman
npm install -g newman

# Run collection
newman run jexpanel-collection.json \
  --environment jexpanel-env.json \
  --reporters cli,html \
  --reporter-html-export report.html
```

## Sharing Collections

### Export Collection
1. Right-click collection
2. Select **Export**
3. Choose format (v2.1 recommended)
4. Save file

### Share with Team
1. Right-click collection
2. Select **Share**
3. Get public link or invite team members

## Tips & Tricks

### 1. Use Folders
Organize requests into logical folders

### 2. Add Descriptions
Document what each request does

### 3. Save Examples
Save response examples for reference

### 4. Use Variables
Replace hardcoded values with variables

### 5. Add Tests
Validate responses automatically

### 6. Monitor APIs
Set up monitors to check API health

### 7. Mock Servers
Create mock responses for development

### 8. Version Control
Sync collections to Git

## Common Issues

### Authentication Fails
- Check API key is correct
- Ensure "Bearer " prefix is included
- Verify key has necessary permissions

### 404 Not Found
- Check base URL is correct
- Verify endpoint path
- Ensure resource exists

### Rate Limited
- Wait for rate limit reset
- Reduce request frequency
- Check rate limit headers

## Resources

- [Postman Documentation](https://learning.postman.com/docs/)
- [Postman API Testing](https://learning.postman.com/docs/writing-scripts/test-scripts/)
- [Newman CLI](https://learning.postman.com/docs/running-collections/using-newman-cli/command-line-integration-with-newman/)

## Alternative Tools

If you prefer alternatives to Postman:

- **Insomnia** - Similar to Postman, great UI
- **HTTPie** - Beautiful CLI HTTP client
- **curl** - Classic command-line tool
- **Thunder Client** - VS Code extension
- **REST Client** - VS Code extension

All of these can import the OpenAPI specification!
