<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use Psr\Http\Message\RequestInterface;

/**
 * RFC 9421 Appendix B.2's test-request.
 */
function rfc9421Request(): RequestInterface
{
    return new Request('POST', 'https://example.com/foo?param=Value&Pet=dog', [
        'Host' => 'example.com',
        'Date' => 'Tue, 20 Apr 2021 02:07:55 GMT',
        'Content-Type' => 'application/json',
        'Content-Digest' => 'sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
        'Content-Length' => '18',
    ], '{"hello": "world"}');
}

const RFC9421_SHARED_SECRET = 'uzvJfB4u3N0Jy4T7NZ75MDVcr8zSTInedJtkgcu46YW4XByzNJjxBdtjUkdJPBtbmHhIDi6pcl8jsasjlTMtDQ==';

const RFC9421_ED25519_PRIVATE = "-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIJ+DYvh6SEqVTm50DFtMDoQikTmiCqirVv9mWG9qfSnF\n-----END PRIVATE KEY-----";

const RFC9421_ED25519_PUBLIC = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAJrQLj5P/89iXES9+vFgrIy29clF9CC/oPPsw3c5D0bs=\n-----END PUBLIC KEY-----";
