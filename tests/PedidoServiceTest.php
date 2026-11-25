<?php
declare(strict_types=1);

namespace Tests;

use App\Database;
use App\PedidoService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class PedidoServiceTest extends TestCase
{
    private PDO $pdo;
    private PedidoService $service;

    protected function setUp(): void
    {
        $dbTest = getenv('DB_TEST_NAME') ?: 'restaurante_test';
        $this->pdo = Database::getConnection($dbTest);
        $this->pdo->exec('TRUNCATE TABLE detalle_pedidos, pedidos RESTART IDENTITY CASCADE');
        $this->service = new PedidoService($this->pdo);
    }

    public function testCrearPedidoCalculaTotalCorrecto(): void
    {
        $menu = $this->service->listarMenu();
        $this->assertNotEmpty($menu);

        $plato = $menu[0];
        $esperado = round((float)$plato['precio'] * 2, 2);

        $pedido = $this->service->crearPedido('María López', [
            ['plato_id' => (int)$plato['id'], 'cantidad' => 2],
        ]);

        $this->assertSame('confirmado', $pedido['estado']);
        $this->assertEquals($esperado, (float)$pedido['total']);
    }

    public function testRechazaPedidoSinPlatos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->crearPedido('María López', []);
    }
}
