<?php

namespace Tests\Unit;

use App\Services\NfcCard\NfcTokenGenerator;
use PHPUnit\Framework\TestCase;

class NfcTokenGeneratorTest extends TestCase
{
    public function test_generated_tokens_are_csprng_sized_and_alphanumeric(): void
    {
        $generator = new NfcTokenGenerator;
        $tokens = [];

        for ($i = 0; $i < 25; $i++) {
            $token = $generator->generate();

            $this->assertSame(NfcTokenGenerator::TOKEN_LENGTH, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);

            $tokens[] = $token;
        }

        $this->assertCount(25, array_unique($tokens));
    }

    public function test_hash_is_sha256_and_never_the_plaintext_token(): void
    {
        $generator = new NfcTokenGenerator;
        $token = $generator->generate();
        $hash = $generator->hash($token);

        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertNotSame($token, $hash);
    }
}
