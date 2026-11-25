<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

class ReservaService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function consultarDisponibilidad(string $fecha, string $hora, int $personas): array
    {
        if ($personas <= 0) {
            throw new InvalidArgumentException('El número de personas debe ser mayor a cero.');
        }

        $sql = "
            SELECT m.id, m.numero, m.capacidad, m.ubicacion
            FROM mesas m
            WHERE m.activa = TRUE
              AND m.capacidad >= :personas
              AND m.id NOT IN (
                  SELECT r.mesa_id
                  FROM reservas r
                  WHERE r.fecha = :fecha
                    AND r.hora = :hora
                    AND r.estado = 'confirmada'
              )
            ORDER BY m.capacidad ASC, m.numero ASC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'personas' => $personas,
            'fecha' => $fecha,
            'hora' => $hora,
        ]);

        return $stmt->fetchAll();
    }

    public function crearReserva(
        int $mesaId,
        string $nombreCliente,
        string $fecha,
        string $hora,
        int $personas,
        ?int $usuarioId = null
    ): array {
        $nombreCliente = trim($nombreCliente);
        if ($nombreCliente === '' || $personas <= 0) {
            throw new InvalidArgumentException('Datos de reserva inválidos.');
        }

        $mesaStmt = $this->pdo->prepare('SELECT id, capacidad, activa FROM mesas WHERE id = :id');
        $mesaStmt->execute(['id' => $mesaId]);
        $mesa = $mesaStmt->fetch();

        if (!$mesa || !$mesa['activa']) {
            throw new InvalidArgumentException('La mesa seleccionada no existe o no está activa.');
        }
        if ((int)$mesa['capacidad'] < $personas) {
            throw new InvalidArgumentException('La capacidad de la mesa es insuficiente para el grupo.');
        }

        $dupStmt = $this->pdo->prepare(
            "SELECT id FROM reservas WHERE mesa_id = :mesa_id AND fecha = :fecha AND hora = :hora AND estado = 'confirmada'"
        );
        $dupStmt->execute([
            'mesa_id' => $mesaId,
            'fecha' => $fecha,
            'hora' => $hora,
        ]);
        if ($dupStmt->fetch()) {
            throw new InvalidArgumentException('La mesa ya se encuentra reservada en esa fecha y hora.');
        }

        $codigo = 'RES-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $this->pdo->prepare(
            "INSERT INTO reservas (usuario_id, mesa_id, nombre_cliente, fecha, hora, personas, estado, codigo_confirmacion)
             VALUES (:usuario_id, :mesa_id, :nombre_cliente, :fecha, :hora, :personas, 'confirmada', :codigo)
             RETURNING id, mesa_id, nombre_cliente, fecha, hora, personas, estado, codigo_confirmacion"
        );
        $stmt->execute([
            'usuario_id' => $usuarioId,
            'mesa_id' => $mesaId,
            'nombre_cliente' => $nombreCliente,
            'fecha' => $fecha,
            'hora' => $hora,
            'personas' => $personas,
            'codigo' => $codigo,
        ]);

        return $stmt->fetch();
    }

    public function cancelarReserva(int $reservaId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE reservas SET estado = 'cancelada' WHERE id = :id AND estado = 'confirmada'");
        $stmt->execute(['id' => $reservaId]);
        return $stmt->rowCount() > 0;
    }

    public function listarReservas(): array
    {
        $sql = "
            SELECT r.id, r.nombre_cliente, r.fecha, r.hora, r.personas, r.estado, r.codigo_confirmacion,
                   m.numero AS mesa_numero, m.ubicacion
            FROM reservas r
            JOIN mesas m ON m.id = r.mesa_id
            ORDER BY r.fecha DESC, r.hora DESC, r.id DESC
            LIMIT 25
        ";
        return $this->pdo->query($sql)->fetchAll();
    }
}
