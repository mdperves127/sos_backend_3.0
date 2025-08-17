# Tenant Registration API Documentation

This document describes the API endpoints for managing tenants in the multi-tenant application.

## Base URL
All tenant API endpoints are prefixed with `/api/tenants`

## Endpoints

### 1. Register a New Tenant

**POST** `/api/tenants/register`

Register a new tenant with a unique domain.

#### Request Body
```json
{
    "company_name": "Acme Corporation",
    "domain": "acme.example.com",
    "email": "admin@acme.com",
    "phone": "+1234567890",
    "address": "123 Business St, City, State 12345",
    "owner_name": "John Doe"
}
```

#### Required Fields
- `company_name` (string, max 255 characters)
- `domain` (string, max 255 characters, must be unique)
- `email` (valid email format, max 255 characters)
- `owner_name` (string, max 255 characters)

#### Optional Fields
- `phone` (string, max 20 characters)
- `address` (string, max 500 characters)

#### Response (Success - 201)
```json
{
    "success": true,
    "message": "Tenant registered successfully",
    "data": {
        "tenant_id": "abc123def4",
        "domain": "acme.example.com",
        "company_name": "Acme Corporation",
        "email": "admin@acme.com"
    }
}
```

#### Response (Validation Error - 422)
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "domain": ["This domain is already registered."],
        "email": ["The email field is required."]
    }
}
```

### 2. Get All Tenants

**GET** `/api/tenants`

Retrieve a list of all registered tenants.

#### Response (Success - 200)
```json
{
    "success": true,
    "data": [
        {
            "id": "abc123def4",
            "company_name": "Acme Corporation",
            "email": "admin@acme.com",
            "owner_name": "John Doe",
            "domains": ["acme.example.com"],
            "created_at": "2024-01-15T10:30:00.000000Z"
        }
    ]
}
```

### 3. Get Specific Tenant

**GET** `/api/tenants/{id}`

Retrieve details of a specific tenant by ID.

#### Response (Success - 200)
```json
{
    "success": true,
    "data": {
        "id": "abc123def4",
        "company_name": "Acme Corporation",
        "email": "admin@acme.com",
        "phone": "+1234567890",
        "address": "123 Business St, City, State 12345",
        "owner_name": "John Doe",
        "domains": ["acme.example.com"],
        "created_at": "2024-01-15T10:30:00.000000Z",
        "updated_at": "2024-01-15T10:30:00.000000Z"
    }
}
```

#### Response (Not Found - 404)
```json
{
    "success": false,
    "message": "Tenant not found"
}
```

### 4. Delete Tenant

**DELETE** `/api/tenants/{id}`

Delete a tenant and all associated data (database, domains, etc.).

#### Response (Success - 200)
```json
{
    "success": true,
    "message": "Tenant deleted successfully"
}
```

#### Response (Not Found - 404)
```json
{
    "success": false,
    "message": "Tenant not found"
}
```

## Error Responses

All endpoints may return the following error response format:

```json
{
    "success": false,
    "message": "Error description",
    "error": "Detailed error message"
}
```

## Usage Examples

### Using cURL

#### Register a new tenant:
```bash
curl -X POST http://your-domain.com/api/tenants/register \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "My Company",
    "domain": "mycompany.example.com",
    "email": "admin@mycompany.com",
    "owner_name": "Jane Smith"
  }'
```

#### Get all tenants:
```bash
curl -X GET http://your-domain.com/api/tenants
```

#### Get specific tenant:
```bash
curl -X GET http://your-domain.com/api/tenants/abc123def4
```

#### Delete tenant:
```bash
curl -X DELETE http://your-domain.com/api/tenants/abc123def4
```

### Using JavaScript/Fetch

```javascript
// Register a new tenant
const response = await fetch('/api/tenants/register', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        company_name: 'My Company',
        domain: 'mycompany.example.com',
        email: 'admin@mycompany.com',
        owner_name: 'Jane Smith'
    })
});

const result = await response.json();
console.log(result);
```

## Notes

1. **Domain Uniqueness**: Each domain must be unique across all tenants.
2. **Database Creation**: When a tenant is registered, a new database is automatically created for that tenant.
3. **Data Storage**: Tenant-specific data is stored in the `data` JSON column of the tenants table.
4. **Cascade Deletion**: When a tenant is deleted, all associated domains and the tenant database are also deleted.

## Security Considerations

- Consider implementing authentication and authorization for these endpoints
- Validate domain names to prevent malicious input
- Implement rate limiting to prevent abuse
- Consider adding audit logging for tenant operations 
