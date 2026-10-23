<?php

namespace Tests\Unit\GlobalDns;

use App\Services\GlobalDns\DnsWire;
use PHPUnit\Framework\TestCase;

/**
 * The DNS client that asks one given server (a region's PowerDNS on its public address): the
 * query it sends and the answers it reads — names with compression pointers, A, SOA, TXT, the
 * AA and TC flags.
 */
class DnsWireTest extends TestCase
{
    public function test_the_query_asks_one_question_of_class_in_without_recursion(): void
    {
        $packet = DnsWire::encodeQuery(0x1234, 'shop.g.example.com.', 1);

        $this->assertSame(pack('nnnnnn', 0x1234, 0, 1, 0, 0, 0), substr($packet, 0, 12));
        $this->assertSame("\x04shop\x01g\x07example\x03com\x00", substr($packet, 12, 20));
        $this->assertSame(pack('nn', 1, 1), substr($packet, 32));
    }

    public function test_an_authoritative_answer_with_compressed_names_is_read(): void
    {
        $question = "\x04shop\x01g\x07example\x03com\x00".pack('nn', 1, 1);
        // answer 1: A at the question's name (pointer to offset 12), answer 2: TXT
        $a = "\xC0\x0C".pack('nnNn', 1, 1, 30, 4).inet_pton('203.0.113.10');
        $txt = "\xC0\x0C".pack('nnNn', 16, 1, 60, 6)."\x02ab\x02cd";
        $packet = pack('nnnnnn', 7, 0x8400, 1, 2, 0, 0).$question.$a.$txt;

        $parsed = DnsWire::decodeResponse($packet, 7);

        $this->assertNotNull($parsed);
        $this->assertSame(0, $parsed['rcode']);
        $this->assertTrue($parsed['authoritative']);
        $this->assertFalse($parsed['truncated']);
        $this->assertSame([
            ['name' => 'shop.g.example.com', 'type' => 'A', 'ttl' => 30, 'data' => '203.0.113.10'],
            ['name' => 'shop.g.example.com', 'type' => 'TXT', 'ttl' => 60, 'data' => 'abcd'],
        ], $parsed['answers']);
    }

    public function test_an_soa_answer_names_its_primary_and_serial(): void
    {
        $question = "\x01g\x07example\x03com\x00".pack('nn', 6, 1);
        $rdata = "\x05ns-eu\xC0\x0C"."\x0Ahostmaster\xC0\x0C".pack('NNNNN', 2026102101, 10800, 3600, 604800, 30);
        $soa = "\xC0\x0C".pack('nnNn', 6, 1, 3600, strlen($rdata)).$rdata;
        $packet = pack('nnnnnn', 9, 0x8400, 1, 1, 0, 0).$question.$soa;

        $parsed = DnsWire::decodeResponse($packet, 9);

        $this->assertSame('ns-eu.g.example.com hostmaster.g.example.com 2026102101', $parsed['answers'][0]['data']);
    }

    public function test_refusals_truncation_and_foreign_answers(): void
    {
        $question = "\x01g\x07example\x03com\x00".pack('nn', 6, 1);

        // REFUSED, not authoritative: a server that does not serve the zone
        $refused = DnsWire::decodeResponse(pack('nnnnnn', 3, 0x8005, 1, 0, 0, 0).$question, 3);
        $this->assertSame(5, $refused['rcode']);
        $this->assertFalse($refused['authoritative']);

        $this->assertTrue(DnsWire::decodeResponse(pack('nnnnnn', 3, 0x8600, 1, 0, 0, 0).$question, 3)['truncated']);

        // another id, a query instead of a response, a short packet
        $this->assertNull(DnsWire::decodeResponse(pack('nnnnnn', 4, 0x8400, 1, 0, 0, 0).$question, 3));
        $this->assertNull(DnsWire::decodeResponse(pack('nnnnnn', 3, 0x0000, 1, 0, 0, 0).$question, 3));
        $this->assertNull(DnsWire::decodeResponse("\x00\x03"));
    }
}
