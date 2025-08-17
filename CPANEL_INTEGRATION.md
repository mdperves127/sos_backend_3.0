# cPanel Integration with Environment-Based Logic

This document explains how to use the cPanel integration system that automatically handles subdomain and database creation based on the environment.

## Overview

The system provides environment-based logic for creating subdomains and databases:
- **Local Environment**: Skips actual cPanel operations and returns success responses
- **Production Environment**: Uses cPanel API to create actual subdomains and databases
- **Other Environments**: Returns error messages for unsupported environments

**Important**: Database creation is now handled automatically by the Laravel tenancy package using our custom `CpanelDatabaseManager` that integrates with cPanel API in production.

## Files Created/Modified

1. **`app/Services/CpanelService.php`** - Main service handling cPanel operations
2. **`app/Services/CpanelDatabaseManager.php`** - Custom database manager for cPanel integration
3. **`app/Bootstrappers/CpanelDatabaseTenancyBootstrapper.php`** - Custom bootstrapper for database configuration
4. **`app/Http/Controllers/CpanelController.php`** - API controller for cPanel operations
5. **`app/Services/TenantService.php`** - Updated to integrate with CpanelService
6. **`app/Providers/AppServiceProvider.php`** - Registered CpanelService in service container
7. **`config/tenancy.php`** - Updated to use custom database manager and bootstrapper
8. **`routes/api.php`** - Added cPanel API routes
9. **Environment files** - Added cPanel configuration variables

## Environment Configuration

### Local Environment (`sos-env-local.env`)
```env
APP_ENV=local
# cPanel variables are not used in local environment
CPANEL_USER=
CPANEL_PASSWORD=
CPANEL_HOST=
MAIN_DOMAIN=
```

### Production Environment (`sos-env-live.env`)
```env
APP_ENV=production
# Configure these with your actual cPanel credentials
CPANEL_USER=your_cpanel_username
CPANEL_PASSWORD=your_cpanel_password
CPANEL_HOST=your_cpanel_host.com
MAIN_DOMAIN=your_main_domain.com
```

## API Endpoints

### 1. Create Subdomain
```http
POST /api/cpanel/subdomain
Content-Type: application/json

{
    "subdomain": "example"
}
```

### 2. Create Database
```http
POST /api/cpanel/database
Content-Type: application/json

{
    "dbname": "sosanik_tenant_example"
}
```

### 3. Create Tenant Infrastructure (Both)
```http
POST /api/cpanel/infrastructure
Content-Type: application/json

{
    "subdomain": "example",
    "dbname": "sosanik_tenant_example"
}
```

### 4. Get Environment Information
```http
GET /api/cpanel/environment-info
```

## Usage Examples

### Using the Service Directly

```php
use App\Services\CpanelService;

class YourController extends Controller
{
    protected CpanelService $cpanelService;

    public function __construct(CpanelService $cpanelService)
    {
        $this->cpanelService = $cpanelService;
    }

    public function createTenantInfrastructure()
    {
        $result = $this->cpanelService->createTenantInfrastructure('example', 'sosanik_tenant_example');
        
        if ($result['success']) {
            // Handle success
            return response()->json($result);
        } else {
            // Handle error
            return response()->json($result, 500);
        }
    }
}
```

### Using TenantService (Automatic Integration)

The `TenantService` now automatically creates subdomain infrastructure when creating a new tenant. Database creation is handled automatically by the tenancy package:

```php
use App\Services\TenantService;

$tenantService = app(TenantService::class);
$result = $tenantService->createTenant([
    'domain' => 'example',
    'company_name' => 'Example Company',
    'email' => 'example@company.com',
    'owner_name' => 'John Doe'
]);

// The result will include subdomain creation details
$subdomain = $result['subdomain'];
// Database is created automatically by the tenancy package using cPanel API
```

## Response Examples

### Local Environment Response
```json
{
    "success": true,
    "data": {
        "subdomain": {
            "status": 1,
            "message": "Subdomain creation skipped for local environment",
            "subdomain": "example",
            "environment": "local"
        },
        "database": {
            "status": 1,
            "message": "Database creation skipped for local environment",
            "database": "sosanik_tenant_example",
            "environment": "local"
        },
        "environment": "local",
        "success": true
    }
}
```

### Production Environment Response
```json
{
    "success": true,
    "data": {
        "subdomain": {
            "status": 1,
            "result": "Subdomain created successfully"
        },
        "database": {
            "database": {
                "status": 1,
                "result": "Database created successfully"
            },
            "assignment": {
                "status": 1,
                "result": "User assigned to database successfully"
            }
        },
        "environment": "production",
        "success": true
    }
}
```

**Note**: Database creation is now handled automatically by the tenancy package, so you may not see database creation responses in the API unless you call the database endpoints directly.

## Error Handling

The system includes comprehensive error handling:

1. **Environment not supported**: Returns error for unsupported environments
2. **cPanel API errors**: Returns detailed error messages from cPanel
3. **Missing configuration**: Validates required environment variables
4. **Network errors**: Handles cURL errors gracefully

## Security Considerations

1. **Environment Variables**: Store cPanel credentials securely in environment variables
2. **API Access**: Consider adding authentication middleware to cPanel endpoints
3. **Input Validation**: All inputs are validated before processing
4. **Error Messages**: Avoid exposing sensitive information in error responses

## Testing

### Local Testing
- Set `APP_ENV=local` in your environment
- Operations will be simulated without actual cPanel calls
- Perfect for development and testing

### Production Testing
- Set `APP_ENV=production` and configure cPanel credentials
- Test with a small subdomain/database first
- Monitor cPanel logs for any issues

## Troubleshooting

### Common Issues

1. **"Unsupported environment" error**
   - Check that `APP_ENV` is set to either `local` or `production`

2. **cPanel authentication failed**
   - Verify `CPANEL_USER`, `CPANEL_PASSWORD`, and `CPANEL_HOST` are correct
   - Ensure cPanel API access is enabled

3. **Database creation failed**
   - Check if the database name already exists
   - Verify database user permissions

4. **Subdomain creation failed**
   - Check if the subdomain already exists
   - Verify domain configuration in cPanel

### Debug Mode

Enable debug mode to get more detailed error information:

```env
APP_DEBUG=true
```

## Integration with Existing Code

The system is designed to work seamlessly with your existing tenant management:

1. **Automatic Integration**: `TenantService` automatically creates infrastructure
2. **Manual Control**: Use `CpanelService` directly for custom operations
3. **API Access**: Use the provided API endpoints for external integrations
4. **Environment Awareness**: No code changes needed when switching environments
