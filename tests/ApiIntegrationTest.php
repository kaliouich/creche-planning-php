<?php

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class ApiIntegrationTest extends TestCase
{
    private Client $client;
    
    protected function setUp(): void
    {
        // On s'attend à ce que le serveur PHP built-in tourne sur localhost:8000 (via CI ou local dev)
        $this->client = new Client([
            'base_uri' => 'http://localhost:8000',
            'http_errors' => false, // Ne pas throw d'exceptions sur les 4xx/5xx pour pouvoir les tester
        ]);
        
        // On attend que le serveur soit prêt (utile surtout pour la CI)
        $maxRetries = 5;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = $this->client->get('/index.php');
                if ($response->getStatusCode() !== 0) {
                    break; // Le serveur est prêt
                }
            } catch (\Exception $e) {
                // Ignore
            }
            sleep(1);
        }
    }

    public function testLoginWithInvalidCredentialsReturns401()
    {
        $response = $this->client->post('/index.php/auth/login', [
            'json' => [
                'email' => 'fake@email.com',
                'password' => 'wrongpassword'
            ]
        ]);

        $this->assertEquals(401, $response->getStatusCode());
        
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Identifiants invalides', $body['error']);
    }
    
    public function testProtectedEndpointWithoutTokenReturns401()
    {
        // La route /week nécessite d'être authentifié
        $response = $this->client->get('/index.php/week');
        
        $this->assertEquals(401, $response->getStatusCode());
        
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals('Non authentifié', $body['error']);
    }
    
    public function testCORSHeadersArePresent()
    {
        $response = $this->client->options('/index.php/auth/login', [
            'headers' => [
                'Origin' => 'http://localhost:5173',
                'Access-Control-Request-Method' => 'POST'
            ]
        ]);
        
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertEquals('http://localhost:5173', $response->getHeader('Access-Control-Allow-Origin')[0]);
    }
}
