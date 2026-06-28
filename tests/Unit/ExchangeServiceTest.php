<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ExchangeService;
use ExchangeRepository;

require_once __DIR__ . '/../../services/ExchangeService.php';

class ExchangeServiceTest extends TestCase {
    public function testCreateOfferCommitsTransaction() {
        // 1. Création du Mock du Repository
        $mockRepo = $this->createMock(ExchangeRepository::class);
        
        // 2. Définition des attentes (Expectations)
        $mockRepo->expects($this->once())->method('beginTransaction');
        $mockRepo->expects($this->once())->method('createOffer')
                 ->with('offer-123', 'assign-456');
        $mockRepo->expects($this->once())->method('commit');
        $mockRepo->expects($this->never())->method('rollBack');

        // 3. Exécution
        $service = new ExchangeService($mockRepo);
        $service->createOffer('offer-123', 'assign-456');
    }
    
    public function testCreateOfferRollsbackOnError() {
        $mockRepo = $this->createMock(ExchangeRepository::class);
        
        $mockRepo->expects($this->once())->method('beginTransaction');
        $mockRepo->method('createOffer')->willThrowException(new \Exception("DB Error"));
        $mockRepo->expects($this->never())->method('commit');
        $mockRepo->expects($this->once())->method('rollBack');

        $service = new ExchangeService($mockRepo);
        
        $this->expectException(\Exception::class);
        $service->createOffer('offer-123', 'assign-456');
    }
}
