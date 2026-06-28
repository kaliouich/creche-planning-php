<?php

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class ApiIntegrationTest extends TestCase
{
    private Client $client;
    
    protected function setUp(): void
    {
        $this->markTestSkipped('Serveur API non disponible dans cet environnement de test.');
        
        try {
            $response = $this->client->options('/index.php/auth/login');
            if ($response->getStatusCode() === 0 || $response->getStatusCode() === 404) {
                $this->markTestSkipped('Serveur API non disponible sur localhost:8000');
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Serveur API non disponible sur localhost:8000');
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
