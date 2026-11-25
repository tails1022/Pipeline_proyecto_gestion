<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

class PedidoService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listarMenu(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre, descripcion, precio, categoria FROM platos WHERE disponible = TRUE ORDER BY categoria, nombre'
        )->fetchAll();
    }

    /**
     * @param array<int, array{plato_id: int, cantidad: int}> $items
     */
    public function crearPedido(string $nombreCliente, array $items, ?int $usuarioId = null): array
    {
        $nombreCliente = trim($nombreCliente);
        if ($nombreCliente === '' || empty($items)) {
            throw new InvalidArgumentException('El pedido debe tener un cliente y al menos un plato.');
        }

        $this->pdo->beginTransaction();
        try {
            $total = 0.0;
            $detalles = [];

            $platoStmt = $this->pdo->prepare('SELECT id, precio, disponible FROM platos WHERE id = :id');
            foreach ($items as $item) {
                $platoId = (int)($item['plato_id'] ?? 0);
                $cantidad = (int)($item['cantidad'] ?? 0);
                if ($platoId <= 0 || $cantidad <= 0) {
                    throw new InvalidArgumentException('Cantidad o plato inválido.');
                }

                $platoStmt->execute(['id' => $platoId]);
                $plato = $platoStmt->fetch();
                if (!$plato || !$plato['disponible']) {
                    throw new InvalidArgumentException("El plato ID {$platoId} no está disponible.");
                }

                $subtotal = round((float)$plato['precio'] * $cantidad, 2);
                $total += $subtotal;
                $detalles[] = [
                    'plato_id' => $platoId,
                    'cantidad' => $cantidad,
                    'subtotal' => $subtotal,
                ];
            }

            $codigo = 'PED-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $pedStmt = $this->pdo->prepare(
                "INSERT INTO pedidos (usuario_id, nombre_cliente, total, estado, codigo_pedido)
                 VALUES (:usuario_id, :nombre_cliente, :total, 'confirmado', :codigo)
                 RETURNING id, nombre_cliente, total, estado, codigo_pedido"
            );
            $pedStmt->execute([
                'usuario_id' => $usuarioId,
                'nombre_cliente' => $nombreCliente,
                'total' => round($total, 2),
                'codigo' => $codigo,
            ]);
            $pedido = $pedStmt->fetch();

            $detStmt = $this->pdo->prepare(
                'INSERT INTO detalle_pedidos (pedido_id, plato_id, cantidad, subtotal) VALUES (:pedido_id, :plato_id, :cantidad, :subtotal)'
            );
            foreach ($detalles as $det) {
                $detStmt->execute([
                    'pedido_id' => $pedido['id'],
                    'plato_id' => $det['plato_id'],
                    'cantidad' => $det['cantidad'],
                    'subtotal' => $det['subtotal'],
                ]);
            }

            $this->pdo->commit();
            return $pedido;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listarPedidos(): array
    {
        return $this->pdo->query(
            'SELECT id, nombre_cliente, total, estado, codigo_pedido, creado_en FROM pedidos ORDER BY id DESC LIMIT 20'
        )->fetchAll();
    }
}
