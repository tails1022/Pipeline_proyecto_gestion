<?php
declare(strict_types=1);

namespace Tests;

use App\Database;
use App\ReservaService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class ReservaServiceTest extends TestCase
{
    private PDO $pdo;
    private ReservaService $service;

    protected function setUp(): void
    {
        $dbTest = getenv('DB_TEST_NAME') ?: 'restaurante_test';
        $this->pdo = Database::getConnection($dbTest);
        $this->pdo->exec('TRUNCATE TABLE reservas RESTART IDENTITY CASCADE');
        $this->service = new ReservaService($this->pdo);
    }

    public function testConsultarDisponibilidadYCrearReserva(): void
    {
        $disponibles = $this->service->consultarDisponibilidad('2026-12-01', '20:00', 4);
        $this->assertNotEmpty($disponibles);

        $mesaId = (int)$disponibles[0]['id'];
        $reserva = $this->service->crearReserva($mesaId, 'Juan Pérez', '2026-12-01', '20:00', 4);
        $this->assertSame('confirmada', $reserva['estado']);
        $this->assertStringStartsWith('RES-', $reserva['codigo_confirmacion']);
    }

    public function testRechazaReservaDuplicadaEnMismoHorario(): void
    {
        $disponibles = $this->service->consultarDisponibilidad('2026-12-02', '19:00', 2);
        $mesaId = (int)$disponibles[0]['id'];
        $this->service->crearReserva($mesaId, 'Cliente Uno', '2026-12-02', '19:00', 2);

        $this->expectException(InvalidArgumentException::class);
        $this->service->crearReserva($mesaId, 'Cliente Dos', '2026-12-02', '19:00', 2);
    }

    public function testRechazaReservaCuandoSuperaCapacidad(): void
    {
        $mesaDosPersonas = (int)$this->pdo->query('SELECT id FROM mesas WHERE capacidad = 2 LIMIT 1')->fetchColumn();
        $this->expectException(InvalidArgumentException::class);
        $this->service->crearReserva($mesaDosPersonas, 'Grupo Grande', '2026-12-03', '21:00', 10);
    }
}
