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
