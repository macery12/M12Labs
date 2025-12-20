<?php

namespace Everest\Tests\Traits;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;

trait CreatesTestJwtToken
{
    /**
     * Create a real JWT token for testing.
     *
     * This is used to avoid mocking the final class \Lcobucci\JWT\Token\Plain.
     */
    private function createTestToken(): Plain
    {
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText('test-secret-key-that-is-long-enough-for-sha256-signer')
        );

        return $config->builder()
            ->issuedBy('test')
            ->permittedFor('test')
            ->identifiedBy('test')
            ->getToken($config->signer(), $config->signingKey());
    }
}
