<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SteamOpenId
{
    public const ENDPOINT = 'https://steamcommunity.com/openid/login';

    public const NS = 'http://specs.openid.net/auth/2.0';

    public function parameters(Request $request): array
    {
        $parameters = [];
        foreach (explode('&', $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (array_key_exists($key, $parameters)) {
                throw new RuntimeException('Invalid assertion');
            }
            $parameters[$key] = urldecode($value);
        }

        return $parameters;
    }

    public function verify(array $parameters, string $returnTo): string
    {
        foreach (['ns', 'mode', 'op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle', 'signed', 'sig'] as $field) {
            if (empty($parameters['openid.'.$field]) || strlen($parameters['openid.'.$field]) > 2048) {
                throw new RuntimeException('Invalid assertion');
            }
        }

        $identity = $parameters['openid.claimed_id'];
        if ($parameters['openid.ns'] !== self::NS
            || $parameters['openid.mode'] !== 'id_res'
            || $parameters['openid.op_endpoint'] !== self::ENDPOINT
            || $parameters['openid.return_to'] !== $returnTo
            || $parameters['openid.identity'] !== $identity
            || ! preg_match('~\Ahttps://steamcommunity\.com/openid/id/([0-9]{17})\z~', $identity, $match)) {
            throw new RuntimeException('Invalid assertion');
        }

        $signed = explode(',', $parameters['openid.signed']);
        if (array_diff(['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle'], $signed)) {
            throw new RuntimeException('Unsigned assertion fields');
        }

        $nonce = $parameters['openid.response_nonce'];
        if (! preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z).+\z/D', $nonce, $nonceMatch)) {
            throw new RuntimeException('Invalid nonce');
        }
        $timestamp = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $nonceMatch[1], 'UTC');
        if (! $timestamp || $timestamp->format('Y-m-d\TH:i:s\Z') !== $nonceMatch[1]
            || $timestamp->lt(now()->subSeconds(config('steam_auth.attempt_seconds')))
            || $timestamp->gt(now()->addSeconds(60))) {
            throw new RuntimeException('Expired nonce');
        }

        // Discover only the exact Steam HTTPS identity; never follow user-controlled redirects.
        $this->discover($identity);

        $fields = array_filter($parameters, fn ($key) => str_starts_with($key, 'openid.'), ARRAY_FILTER_USE_KEY);
        $fields['openid.mode'] = 'check_authentication';
        $response = Http::asForm()->connectTimeout(5)->timeout(10)->withoutRedirecting()
            ->post(self::ENDPOINT, $fields);
        if (! $response->successful() || strlen($response->body()) > 4096) {
            throw new RuntimeException('Verification failed');
        }
        $result = [];
        foreach (explode("\n", trim($response->body())) as $line) {
            $parts = explode(':', rtrim($line, "\r"), 2);
            if (count($parts) !== 2 || array_key_exists($parts[0], $result)) {
                throw new RuntimeException('Invalid verification response');
            }
            $result[$parts[0]] = $parts[1];
        }
        if (($result['ns'] ?? null) !== self::NS || ($result['is_valid'] ?? null) !== 'true') {
            throw new RuntimeException('Verification rejected');
        }

        return $match[1];
    }

    private function discover(string $identity): void
    {
        $response = Http::accept('application/xrds+xml')->connectTimeout(5)->timeout(10)
            ->withoutRedirecting()->get($identity);
        $body = $response->body();
        if (! $response->successful() || strlen($body) > 65536 || stripos($body, '<!DOCTYPE') !== false) {
            throw new RuntimeException('Discovery failed');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($body, LIBXML_NONET)) {
                throw new RuntimeException('Discovery failed');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('xrd', 'xri://$xrd*($v*2.0)');
            foreach ($xpath->query('//xrd:Service') as $service) {
                $types = $xpath->query('xrd:Type', $service);
                $uris = $xpath->query('xrd:URI', $service);
                $locals = $xpath->query('xrd:LocalID', $service);
                $signon = false;
                $endpoint = false;
                foreach ($types as $type) {
                    $signon = $signon || trim($type->textContent) === self::NS.'/signon';
                }
                foreach ($uris as $uri) {
                    $endpoint = $endpoint || trim($uri->textContent) === self::ENDPOINT;
                }
                if ($signon && $endpoint && ($locals->length === 0 || ($locals->length === 1 && trim($locals->item(0)->textContent) === $identity))) {
                    return;
                }
            }
            throw new RuntimeException('Discovery mismatch');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
