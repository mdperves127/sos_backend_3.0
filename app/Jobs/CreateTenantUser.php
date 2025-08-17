<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class CreateTenantUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tenant;

    public function __construct(TenantWithDatabase $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        // Get password from tenant's data field
        $tenantData = is_string($this->tenant->data) ? json_decode($this->tenant->data, true) : $this->tenant->data;
        $password = $tenantData['password'] ?? 'password123';

        // Get user data from tenant fields
        $userData = [
            'name' => $this->tenant->owner_name,
            'email' => $this->tenant->email,
            'password' => $password
        ];

        \Log::info('CreateTenantUser: Attempting to create user', [
            'tenant_id' => $this->tenant->id,
            'user_data' => array_merge($userData, ['password' => '***hidden***'])
        ]);

        try {
            // Run in tenant context to create user
            $this->tenant->run(function () use ($userData) {
                $user = User::create([
                    'name' => $userData['name'],
                    'email' => $userData['email'],
                    'password' => Hash::make($userData['password']),
                    'last_seen' => now(),
                ]);

                \Log::info('CreateTenantUser: User created successfully', [
                    'tenant_id' => $this->tenant->id,
                    'user_id' => $user->id,
                    'email' => $user->email
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('CreateTenantUser: Failed to create user', [
                'tenant_id' => $this->tenant->id,
                'error' => $e->getMessage(),
                'user_data' => $userData
            ]);
            throw $e;
        }
    }
}
