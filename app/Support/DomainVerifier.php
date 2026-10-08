<?php

namespace App\Support;

/**
 * Proves a workspace controls a custom domain: the owner adds a TXT record at
 * _zonseo-verify.<domain> holding the workspace's token, and we look it up in DNS.
 */
class DomainVerifier
{
    public const RECORD_PREFIX = '_zonseo-verify';

    public function recordName(string $domain): string
    {
        return self::RECORD_PREFIX.'.'.$domain;
    }

    public function verify(string $domain, string $token): bool
    {
        foreach ($this->txtRecords($this->recordName($domain)) as $value) {
            if (hash_equals($token, trim($value, " \t\n\r\0\x0B\""))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        return is_array($records) ? array_values(array_filter(array_map(fn (array $record) => $record['txt'] ?? null, $records), 'is_string')) : [];
    }
}
