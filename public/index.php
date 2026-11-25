<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\AuthService;
use App\Database;
use App\PedidoService;
use App\ReporteService;
use App\ReservaService;

// Endpoint ligero para Smoke Test y pruebas de carga del pipeline CI/CD
if (isset($_GET['health'])) {
    header('Content-Type: application/json');
    try {
        $pdo = Database::getConnection();
        $pdo->query('SELECT 1');
        echo json_encode(['status' => 'ok', 'db' => 'postgresql-16', 'timestamp' => date('c')]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$pdo = Database::getConnection();
$authService = new AuthService($pdo);
$reservaService = new ReservaService($pdo);
$pedidoService = new PedidoService($pdo);
$reporteService = new ReporteService($pdo);

$mensaje = null;
$error = null;
$disponibles = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'registro') {
            $user = $authService->registrar($_POST['nombre'] ?? '', $_POST['email'] ?? '', $_POST['password'] ?? '');
            $_SESSION['usuario'] = $user;
            $mensaje = "Bienvenido, {$user['nombre']}. Cuenta registrada.";
        } elseif ($accion === 'login') {
            $user = $authService->autenticar($_POST['email'] ?? '', $_POST['password'] ?? '');
            if ($user) {
                $_SESSION['usuario'] = $user;
                $mensaje = "Sesión iniciada como {$user['nombre']}.";
            } else {
                $error = 'Credenciales inválidas.';
            }
        } elseif ($accion === 'logout') {
            unset($_SESSION['usuario']);
            $mensaje = 'Sesión cerrada.';
        } elseif ($accion === 'crear_reserva') {
            $res = $reservaService->crearReserva(
                (int)($_POST['mesa_id'] ?? 0),
                $_POST['nombre_cliente'] ?? '',
                $_POST['fecha'] ?? date('Y-m-d'),
                $_POST['hora'] ?? '19:00',
                (int)($_POST['personas'] ?? 2),
                $_SESSION['usuario']['id'] ?? null
            );
            $mensaje = "Reserva confirmada. Código: {$res['codigo_confirmacion']}";
        } elseif ($accion === 'crear_pedido') {
            $items = [];
            foreach ($_POST['cantidad'] ?? [] as $platoId => $qty) {
                if ((int)$qty > 0) {
                    $items[] = ['plato_id' => (int)$platoId, 'cantidad' => (int)$qty];
                }
            }
            $ped = $pedidoService->crearPedido(
                $_POST['nombre_cliente'] ?? '',
                $items,
                $_SESSION['usuario']['id'] ?? null
            );
            $mensaje = "Pedido confirmado ({$ped['codigo_pedido']}). Total: $" . number_format((float)$ped['total'], 2);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$fechaConsulta = $_GET['fecha'] ?? date('Y-m-d');
$horaConsulta = $_GET['hora'] ?? '19:00';
$personasConsulta = max(1, (int)($_GET['personas'] ?? 2));
$disponibles = $reservaService->consultarDisponibilidad($fechaConsulta, $horaConsulta, $personasConsulta);
$menu = $pedidoService->listarMenu();
$reservas = $reservaService->listarReservas();
$pedidos = $pedidoService->listarPedidos();
$resumen = $reporteService->obtenerResumen();
$usuario = $_SESSION['usuario'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bistró Gourmet | Reservas y Pedidos</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header>
    <div class="container header-flex">
        <div>
            <h1>Bistró Gourmet</h1>
            <p class="subtitle">Sistema de Gestión de Pedidos y Reservas (PHP 8.4 + PostgreSQL 16)</p>
        </div>
        <div class="auth-box">
            <?php if ($usuario): ?>
                <span>Hola, <strong><?= htmlspecialchars($usuario['nombre']) ?></strong></span>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="accion" value="logout">
                    <button type="submit" class="btn-secondary">Salir</button>
                </form>
            <?php else: ?>
                <span class="badge">Modo Invitado</span>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="container">
    <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Reportes Operativos -->
    <section class="kpi-grid">
        <div class="kpi-card">
            <span class="kpi-label">Mesas Activas</span>
            <strong class="kpi-value"><?= $resumen['mesas_activas'] ?></strong>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Reservas Confirmadas</span>
            <strong class="kpi-value"><?= $resumen['reservas_activas'] ?></strong>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Pedidos Registrados</span>
            <strong class="kpi-value"><?= $resumen['total_pedidos'] ?></strong>
        </div>
        <div class="kpi-card">
            <span class="kpi-label">Ingresos Totales</span>
            <strong class="kpi-value">$<?= number_format($resumen['ingresos_totales'], 2) ?></strong>
        </div>
    </section>

    <div class="grid-2">
        <!-- Módulo de Reservas -->
        <section class="card">
            <h2>1. Disponibilidad y Reservas</h2>
            <form method="get" class="form-inline">
                <label>Fecha <input type="date" name="fecha" value="<?= htmlspecialchars($fechaConsulta) ?>"></label>
                <label>Hora <input type="time" name="hora" value="<?= htmlspecialchars($horaConsulta) ?>"></label>
                <label>Personas <input type="number" name="personas" min="1" max="12" value="<?= $personasConsulta ?>"></label>
                <button type="submit" class="btn-secondary">Consultar</button>
            </form>

            <form method="post" class="stack-form">
                <input type="hidden" name="accion" value="crear_reserva">
                <input type="hidden" name="fecha" value="<?= htmlspecialchars($fechaConsulta) ?>">
                <input type="hidden" name="hora" value="<?= htmlspecialchars($horaConsulta) ?>">
                <input type="hidden" name="personas" value="<?= $personasConsulta ?>">
                <label>Nombre del Cliente
                    <input type="text" name="nombre_cliente" required value="<?= htmlspecialchars($usuario['nombre'] ?? '') ?>" placeholder="Ej. Ana Gómez">
                </label>
                <label>Mesa Disponible
                    <select name="mesa_id" required>
                        <?php foreach ($disponibles as $m): ?>
                            <option value="<?= $m['id'] ?>">Mesa #<?= $m['numero'] ?> (Cap: <?= $m['capacidad'] ?> - <?= htmlspecialchars($m['ubicacion']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-primary" <?= empty($disponibles) ? 'disabled' : '' ?>>Confirmar Reserva</button>
            </form>
        </section>

        <!-- Módulo de Pedidos -->
        <section class="card">
            <h2>2. Pedidos en Línea</h2>
            <form method="post" class="stack-form">
                <input type="hidden" name="accion" value="crear_pedido">
                <label>Nombre del Cliente
                    <input type="text" name="nombre_cliente" required value="<?= htmlspecialchars($usuario['nombre'] ?? '') ?>" placeholder="Ej. Carlos Ruiz">
                </label>
                <table class="menu-table">
                    <thead><tr><th>Plato</th><th>Precio</th><th>Cant.</th></tr></thead>
                    <tbody>
                    <?php foreach ($menu as $plato): ?>
                        <tr>
                            <td><?= htmlspecialchars($plato['nombre']) ?></td>
                            <td>$<?= number_format((float)$plato['precio'], 2) ?></td>
                            <td><input type="number" name="cantidad[<?= $plato['id'] ?>]" min="0" max="20" value="0" style="width:60px;"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn-primary">Realizar Pedido</button>
            </form>
        </section>
    </div>

    <?php if (!$usuario): ?>
    <section class="card" style="margin-top: 1.5rem;">
        <h2>3. Autenticación de Usuarios</h2>
        <div class="grid-2">
            <form method="post" class="stack-form">
                <h3>Iniciar Sesión</h3>
                <input type="hidden" name="accion" value="login">
                <input type="email" name="email" required placeholder="Correo electrónico">
                <input type="password" name="password" required placeholder="Contraseña">
                <button type="submit" class="btn-secondary">Entrar</button>
            </form>
            <form method="post" class="stack-form">
                <h3>Registrarse</h3>
                <input type="hidden" name="accion" value="registro">
                <input type="text" name="nombre" required placeholder="Nombre completo">
                <input type="email" name="email" required placeholder="Correo electrónico">
                <input type="password" name="password" required placeholder="Contraseña (mín. 6 caracteres)">
                <button type="submit" class="btn-secondary">Crear Cuenta</button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <div class="grid-2" style="margin-top: 1.5rem;">
        <section class="card">
            <h3>Últimas Reservas</h3>
            <ul class="list-compact">
                <?php foreach (array_slice($reservas, 0, 5) as $r): ?>
                    <li><strong><?= htmlspecialchars($r['codigo_confirmacion']) ?></strong> — <?= htmlspecialchars($r['nombre_cliente']) ?> (Mesa #<?= $r['mesa_numero'] ?>, <?= $r['fecha'] ?> <?= substr($r['hora'], 0, 5) ?>)</li>
                <?php endforeach; ?>
            </ul>
        </section>
        <section class="card">
            <h3>Últimos Pedidos</h3>
            <ul class="list-compact">
                <?php foreach (array_slice($pedidos, 0, 5) as $p): ?>
                    <li><strong><?= htmlspecialchars($p['codigo_pedido']) ?></strong> — <?= htmlspecialchars($p['nombre_cliente']) ?> ($<?= number_format((float)$p['total'], 2) ?> - <?= $p['estado'] ?>)</li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
</main>
</body>
</html>
