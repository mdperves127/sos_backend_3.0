<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantCustomDomain;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Models\Domain;

class CustomDomainService
{
    public function normalizeDomain( string $domain ): string {
        $domain = strtolower( trim( $domain ) );
        $domain = preg_replace( '#^https?://#', '', $domain );
        $domain = rtrim( $domain, '/' );

        if ( str_starts_with( $domain, 'www.' ) ) {
            $domain = substr( $domain, 4 );
        }

        return $domain;
    }

    public function targetIp(): ?string {
        $configured = config( 'tenancy.custom_domain_target_ip' );

        if ( is_string( $configured ) && filter_var( $configured, FILTER_VALIDATE_IP ) ) {
            return $configured;
        }

        $appHost = parse_url( (string) config( 'app.url' ), PHP_URL_HOST );

        if ( ! $appHost || in_array( $appHost, ['localhost', '127.0.0.1'], true ) ) {
            return null;
        }

        $records = @dns_get_record( $appHost, DNS_A ) ?: [];

        foreach ( $records as $record ) {
            if ( ! empty( $record['ip'] ) && filter_var( $record['ip'], FILTER_VALIDATE_IP ) ) {
                return $record['ip'];
            }
        }

        return null;
    }

    public function forTenant( string $tenantId ): ?TenantCustomDomain {
        return TenantCustomDomain::on( 'mysql' )->where( 'tenant_id', $tenantId )->first();
    }

    public function getSavedDomainStatusForTenant( string $tenantId ): array {
        $tenantRow = DB::connection( 'mysql' )
            ->table( 'tenants' )
            ->whereNull( 'deleted_at' )
            ->where( 'id', $tenantId )
            ->first();

        if ( ! $tenantRow ) {
            return ['found' => false];
        }

        $customDomain = $tenantRow->custom_domain ?? null;

        if ( ! $customDomain ) {
            return [
                'found'             => true,
                'has_custom_domain' => false,
                'active'            => false,
                'saved'             => false,
                'domain'            => null,
            ];
        }

        $domain         = $this->normalizeDomain( (string) $customDomain );
        $record         = TenantCustomDomain::on( 'mysql' )->where( 'tenant_id', $tenantId )->first();
        $isRegistered   = Domain::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->where( 'domain', $domain )
            ->exists();
        $connectionStatus = $record?->status ?? ( $isRegistered ? 'active' : 'pending' );
        $isActive         = $connectionStatus === 'active' || $isRegistered;

        return [
            'found'             => true,
            'has_custom_domain' => true,
            'active'            => $isActive,
            'saved'             => true,
            'domain'            => $domain,
            'connection_status' => $connectionStatus,
            'verification'      => $record?->verification ?? ( $isActive ? 'verified' : 'pending' ),
            'ssl'               => $record?->ssl ?? ( $isActive ? 'active' : 'pending' ),
        ];
    }

    public function findTenantByIdOrCustomDomain( string $identifier ): ?Tenant {
        $identifier = trim( $identifier );
        $normalized = $this->normalizeDomain( $identifier );

        if ( $normalized === '' ) {
            return null;
        }

        $tenantRow = DB::connection( 'mysql' )
            ->table( 'tenants' )
            ->whereNull( 'deleted_at' )
            ->whereNotNull( 'custom_domain' )
            ->where( function ( $query ) use ( $normalized, $identifier ) {
                $query->where( 'custom_domain', $normalized )
                    ->orWhere( 'custom_domain', $identifier )
                    ->orWhere( 'custom_domain', 'www.' . $normalized );
            } )
            ->first();

        if ( $tenantRow ) {
            return Tenant::on( 'mysql' )->find( $tenantRow->id );
        }

        $tenantRow = DB::connection( 'mysql' )
            ->table( 'tenants' )
            ->whereNull( 'deleted_at' )
            ->whereNotNull( 'custom_domain' )
            ->get()
            ->first( fn ( $row ) => $this->normalizeDomain( (string) $row->custom_domain ) === $normalized );

        if ( $tenantRow ) {
            return Tenant::on( 'mysql' )->find( $tenantRow->id );
        }

        $customDomainRecord = TenantCustomDomain::on( 'mysql' )->where( 'domain', $normalized )->first();

        if ( $customDomainRecord ) {
            return Tenant::on( 'mysql' )->find( $customDomainRecord->tenant_id );
        }

        return null;
    }

    public function resolveTenantByIdentifier( string $identifier ): ?array {
        $identifier = trim( $identifier );

        if ( $identifier === '' ) {
            return null;
        }

        $matchedBy = 'tenant_id';
        $tenant    = Tenant::on( 'mysql' )->find( $identifier );

        if ( ! $tenant ) {
            $matchedBy = 'custom_domain';
            $tenant    = $this->findTenantByIdOrCustomDomain( $identifier );
        }

        if ( ! $tenant ) {
            return null;
        }

        $domainStatus = $this->getSavedDomainStatusForTenant( $tenant->id );
        $subdomain    = $this->getTenantSubdomain( $tenant->id, $domainStatus['domain'] ?? null );

        return [
            'matched_by'        => $matchedBy,
            'tenant_id'         => $tenant->id,
            'subdomain'         => $subdomain['subdomain'] ?? null,
            'subdomain_name'    => $subdomain['subdomain_name'] ?? null,
            'has_custom_domain' => $domainStatus['has_custom_domain'] ?? false,
            'custom_domain'     => ( $domainStatus['has_custom_domain'] ?? false ) ? [
                'domain'            => $domainStatus['domain'],
                'active'            => $domainStatus['active'],
                'connection_status' => $domainStatus['connection_status'],
                'verification'      => $domainStatus['verification'],
                'ssl'               => $domainStatus['ssl'],
            ] : null,
        ];
    }

    public function addDomain( string $tenantId, string $domain ): TenantCustomDomain {
        $domain    = $this->normalizeDomain( $domain );
        $targetIp  = $this->targetIp();

        return DB::connection( 'mysql' )->transaction( function () use ( $tenantId, $domain, $targetIp ) {
            $record = TenantCustomDomain::on( 'mysql' )->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'domain'        => $domain,
                    'status'        => 'pending',
                    'verification'  => 'pending',
                    'ssl'           => 'pending',
                    'target_ip'     => $targetIp,
                    'last_dns_check'=> null,
                    'verified_at'   => null,
                    'activated_at'  => null,
                ]
            );

            Tenant::on( 'mysql' )->whereKey( $tenantId )->update( [
                'custom_domain' => $domain,
            ] );

            return $record->fresh();
        } );
    }

    public function checkConnection( TenantCustomDomain $record ): array {
        $dnsCheck = $this->verifyDnsARecord( $record->domain, $record->target_ip );

        $record->update( [
            'last_dns_check' => $dnsCheck,
        ] );

        return $this->formatStatusPayload( $record->fresh(), $dnsCheck );
    }

    public function verifyDomain( TenantCustomDomain $record ): array {
        $dnsCheck = $this->verifyDnsARecord( $record->domain, $record->target_ip );

        $record->update( [
            'last_dns_check' => $dnsCheck,
        ] );

        if ( ! $dnsCheck['connected'] ) {
            return $this->formatStatusPayload( $record->fresh(), $dnsCheck, false, 'DNS A record does not point to the required server IP yet.' );
        }

        $record->update( [
            'status'       => 'verified',
            'verification' => 'verified',
            'ssl'          => 'issuing',
            'verified_at'  => now(),
        ] );

        return $this->formatStatusPayload( $record->fresh(), $dnsCheck, true, 'Domain ownership verified. SSL provisioning started.' );
    }

    public function activateDomain( TenantCustomDomain $record ): array {
        if ( $record->verification !== 'verified' ) {
            return $this->formatStatusPayload( $record, $record->last_dns_check ?? [], false, 'Verify the domain before activation.' );
        }

        $dnsCheck = $this->verifyDnsARecord( $record->domain, $record->target_ip );

        if ( ! $dnsCheck['connected'] ) {
            $record->update( ['last_dns_check' => $dnsCheck] );

            return $this->formatStatusPayload( $record->fresh(), $dnsCheck, false, 'DNS is no longer pointing to the required server IP.' );
        }

        DB::connection( 'mysql' )->transaction( function () use ( $record, $dnsCheck ) {
            $record->update( [
                'status'         => 'active',
                'verification'   => 'verified',
                'ssl'            => 'active',
                'activated_at'   => now(),
                'last_dns_check' => $dnsCheck,
            ] );

            Tenant::on( 'mysql' )->whereKey( $record->tenant_id )->update( [
                'custom_domain' => $record->domain,
            ] );

            Domain::on( 'mysql' )->updateOrCreate(
                ['domain' => $record->domain],
                ['tenant_id' => $record->tenant_id]
            );
        } );

        return $this->formatStatusPayload( $record->fresh(), $record->last_dns_check ?? [], true, 'Custom domain activated successfully.' );
    }

    public function lookupCustomDomain( string $domain ): ?array {
        $domain = $this->normalizeDomain( $domain );

        if ( $domain === '' ) {
            return null;
        }

        $record = TenantCustomDomain::on( 'mysql' )->where( 'domain', $domain )->first();

        if ( ! $record ) {
            $tenant = Tenant::on( 'mysql' )->where( 'custom_domain', $domain )->first();

            if ( ! $tenant ) {
                return null;
            }

            $subdomain = $this->getTenantSubdomain( $tenant->id, $domain );

            return [
                'custom_domain'  => $domain,
                'tenant_id'      => $tenant->id,
                'subdomain'      => $subdomain['subdomain'] ?? null,
                'subdomain_name' => $subdomain['subdomain_name'] ?? null,
                'status'         => null,
                'verification'   => null,
                'ssl'            => null,
                'connected'      => true,
            ];
        }

        $subdomain = $this->getTenantSubdomain( $record->tenant_id, $domain );

        return [
            'custom_domain'  => $domain,
            'tenant_id'      => $record->tenant_id,
            'subdomain'      => $subdomain['subdomain'] ?? null,
            'subdomain_name' => $subdomain['subdomain_name'] ?? null,
            'status'         => $record->status,
            'verification'   => $record->verification,
            'ssl'            => $record->ssl,
            'connected'      => true,
        ];
    }

    /**
     * @return array{subdomain: string, subdomain_name: string}|null
     */
    private function getTenantSubdomain( string $tenantId, ?string $customDomain = null ): ?array {
        $mainDomain = env( 'MAIN_DOMAIN' );
        $domains    = Domain::on( 'mysql' )
            ->where( 'tenant_id', $tenantId )
            ->pluck( 'domain' );

        $normalizedCustom = $customDomain ? $this->normalizeDomain( $customDomain ) : null;

        foreach ( $domains as $domain ) {
            $normalizedDomain = $this->normalizeDomain( $domain );

            if ( $normalizedCustom && $normalizedDomain === $normalizedCustom ) {
                continue;
            }

            if ( $mainDomain && str_ends_with( $normalizedDomain, '.' . strtolower( $mainDomain ) ) ) {
                return [
                    'subdomain'      => $normalizedDomain,
                    'subdomain_name' => str_replace( '.' . strtolower( $mainDomain ), '', $normalizedDomain ),
                ];
            }
        }

        $fallback = $domains->first( function ( $domain ) use ( $normalizedCustom ) {
            if ( ! $normalizedCustom ) {
                return true;
            }

            return $this->normalizeDomain( $domain ) !== $normalizedCustom;
        } );

        if ( ! $fallback ) {
            return null;
        }

        $normalizedFallback = $this->normalizeDomain( $fallback );
        $subdomainName      = $normalizedFallback;

        if ( $mainDomain && str_contains( $normalizedFallback, '.' ) ) {
            $subdomainName = str_replace( '.' . strtolower( $mainDomain ), '', $normalizedFallback );
        }

        return [
            'subdomain'      => $normalizedFallback,
            'subdomain_name' => $subdomainName,
        ];
    }

    public function resolveHost( string $host ): ?array {
        $host = $this->normalizeDomain( $host );

        if ( $host === '' ) {
            return null;
        }

        $customDomain = TenantCustomDomain::on( 'mysql' )
            ->where( 'domain', $host )
            ->where( 'status', 'active' )
            ->first();

        if ( $customDomain ) {
            return $this->buildResolvePayload( $customDomain );
        }

        $tenant = Tenant::on( 'mysql' )
            ->where( 'custom_domain', $host )
            ->first();

        if ( $tenant ) {
            $customDomain = TenantCustomDomain::on( 'mysql' )->where( 'tenant_id', $tenant->id )->first();

            if ( $customDomain && $customDomain->status === 'active' ) {
                return $this->buildResolvePayload( $customDomain );
            }
        }

        $domain = Domain::on( 'mysql' )->where( 'domain', $host )->first();

        if ( ! $domain ) {
            return null;
        }

        $customDomain = TenantCustomDomain::on( 'mysql' )
            ->where( 'tenant_id', $domain->tenant_id )
            ->where( 'domain', $host )
            ->first();

        if ( $customDomain && $customDomain->status !== 'active' ) {
            return null;
        }

        return [
            'tenant_id' => $domain->tenant_id,
            'domain'    => $host,
            'source'    => 'domains',
        ];
    }

    private function verifyDnsARecord( string $domain, ?string $expectedIp ): array {
        $records     = @dns_get_record( $domain, DNS_A ) ?: [];
        $resolvedIps = [];

        foreach ( $records as $record ) {
            if ( ! empty( $record['ip'] ) ) {
                $resolvedIps[] = $record['ip'];
            }
        }

        $resolvedIps = array_values( array_unique( $resolvedIps ) );
        $connected   = false;

        if ( $expectedIp && $resolvedIps !== [] ) {
            $connected = in_array( $expectedIp, $resolvedIps, true );
        }

        return [
            'domain'       => $domain,
            'type'         => 'A',
            'expected_ip'  => $expectedIp,
            'resolved_ips' => $resolvedIps,
            'records'      => $records,
            'connected'    => $connected,
            'checked_at'   => now()->toIso8601String(),
        ];
    }

    private function buildResolvePayload( TenantCustomDomain $record ): array {
        return [
            'tenant_id' => $record->tenant_id,
            'domain'    => $record->domain,
            'status'    => $record->status,
            'source'    => 'custom_domain',
        ];
    }

    private function formatStatusPayload(
        TenantCustomDomain $record,
        array $dnsCheck,
        bool $success = true,
        ?string $message = null
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'domain'  => [
                'domain'       => $record->domain,
                'status'       => $record->status,
                'verification' => $record->verification,
                'ssl'          => $record->ssl,
                'target_ip'    => $record->target_ip,
                'verified_at'  => $record->verified_at,
                'activated_at' => $record->activated_at,
            ],
            'dns' => $dnsCheck,
            'instructions' => [
                'type'  => 'A',
                'host'  => '@',
                'value' => $record->target_ip,
                'note'  => 'Point your root domain A record to the value above, then verify DNS ownership.',
            ],
        ];
    }
}
