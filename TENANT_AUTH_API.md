# Tenant Authentication API

This document describes the authentication endpoints for tenant users in the multi-tenant application.

## Base URL
All tenant authentication endpoints are prefixed with the tenant domain, e.g., `http://yourtenant.localhost:8000/api/auth`

## Endpoints

### 1. Register User

**POST** `/api/auth/register`

Register a new user for the current tenant.

#### Request Body
```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
}
```

#### Response (201)
```json
{
    "success": true,
    "message": "User registered successfully",
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "status": "active"
        },
        "token": "1|abc123def456...",
        "tenant_id": "yourtenant"
    }
}
```

### 2. Login User

**POST** `/api/auth/login`

Login with email and password.

#### Request Body
```json
{
    "email": "john@example.com",
    "password": "password123"
}
```

#### Response (200)
```json
{
    "success": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "status": "active",
            "last_seen": "2024-01-15T10:30:00.000000Z"
        },
        "token": "2|xyz789abc123...",
        "tenant_id": "yourtenant"
    }
}
```

### 3. Get Current User

**GET** `/api/auth/me`

Get the current authenticated user's information.

#### Headers
```
Authorization: Bearer {token}
```

#### Response (200)
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "status": "active",
            "last_seen": "2024-01-15T10:30:00.000000Z",
            "created_at": "2024-01-15T10:00:00.000000Z"
        },
        "tenant_id": "yourtenant"
    }
}
```

### 4. Update Profile

**PUT** `/api/auth/profile`

Update the current user's profile information.

#### Headers
```
Authorization: Bearer {token}
```

#### Request Body
```json
{
    "name": "John Smith",
    "email": "johnsmith@example.com",
    "number": "+1234567890",
    "image": "profile.jpg"
}
```

#### Response (200)
```json
{
    "success": true,
    "message": "Profile updated successfully",
    "data": {
        "user": {
            "id": 1,
            "name": "John Smith",
            "email": "johnsmith@example.com",
            "status": "active",
            "number": "+1234567890",
            "image": "profile.jpg"
        }
    }
}
```

### 5. Change Password

**PUT** `/api/auth/change-password`

Change the current user's password.

#### Headers
```
Authorization: Bearer {token}
```

#### Request Body
```json
{
    "current_password": "oldpassword123",
    "new_password": "newpassword123",
    "new_password_confirmation": "newpassword123"
}
```

#### Response (200)
```json
{
    "success": true,
    "message": "Password changed successfully"
}
```

### 6. Logout

**POST** `/api/auth/logout`

Logout the current user and invalidate the token.

#### Headers
```
Authorization: Bearer {token}
```

#### Response (200)
```json
{
    "success": true,
    "message": "Logged out successfully"
}
```

## Usage Examples

### JavaScript/Fetch

```javascript
// Register a new user
const registerResponse = await fetch('http://yourtenant.localhost:8000/api/auth/register', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        name: 'John Doe',
        email: 'john@example.com',
        password: 'password123',
        password_confirmation: 'password123'
    })
});

const registerData = await registerResponse.json();
const token = registerData.data.token;

// Login
const loginResponse = await fetch('http://yourtenant.localhost:8000/api/auth/login', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        email: 'john@example.com',
        password: 'password123'
    })
});

const loginData = await loginResponse.json();
const authToken = loginData.data.token;

// Get current user
const meResponse = await fetch('http://yourtenant.localhost:8000/api/auth/me', {
    headers: {
        'Authorization': `Bearer ${authToken}`
    }
});

const userData = await meResponse.json();
console.log('Current user:', userData.data.user);
```

### cURL

```bash
# Register
curl -X POST http://yourtenant.localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'

# Login
curl -X POST http://yourtenant.localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "password123"
  }'

# Get current user (replace TOKEN with actual token)
curl -H "Authorization: Bearer TOKEN" \
  http://yourtenant.localhost:8000/api/auth/me
```

## Error Responses

### Validation Error (422)
```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "email": ["The email field is required."],
        "password": ["The password must be at least 8 characters."]
    }
}
```

### Authentication Error (401)
```json
{
    "success": false,
    "message": "Invalid credentials"
}
```

### Tenant Context Error (400)
```json
{
    "success": false,
    "message": "Tenant context not found. Please ensure you are accessing the correct tenant domain."
}
```

## Security Features

1. **Password Hashing**: All passwords are hashed using Laravel's Hash facade
2. **Token-based Authentication**: Uses Laravel Sanctum for secure token management
3. **Tenant Isolation**: Each tenant's users are completely isolated
4. **Input Validation**: All inputs are validated and sanitized
5. **Status Checking**: Only active users can login

## Notes

- All endpoints require a valid tenant context (correct domain)
- Tokens are automatically scoped to the tenant
- Users are stored in the tenant's database, not the central database
- Each tenant has its own user authentication system 
