<?php
declare(strict_types=1);

namespace App;

use PDO;

class ReporteService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function obtenerResumen(): array
    {
        $reservasActivas = (int)$this->pdo->query("SELECT COUNT(*) FROM reservas WHERE estado = 'confirmada'")->fetchColumn();
        $totalPedidos = (int)$this->pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
        $ingresos = (float)$this->pdo->query('SELECT COALESCE(SUM(total), 0) FROM pedidos')->fetchColumn();
        $mesasActivas = (int)$this->pdo->query('SELECT COUNT(*) FROM mesas WHERE activa = TRUE')->fetchColumn();

        return [
            'reservas_activas' => $reservasActivas,
            'total_pedidos' => $totalPedidos,
            'ingresos_totales' => round($ingresos, 2),
            'mesas_activas' => $mesasActivas,
        ];
    }
}
