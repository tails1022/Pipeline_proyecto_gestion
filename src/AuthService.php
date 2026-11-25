<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

class AuthService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function registrar(string $nombre, string $email, string $password): array
    {
        $nombre = trim($nombre);
        $email = strtolower(trim($email));

        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Nombre o correo electrónico inválido.');
        }
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('La contraseña debe tener al menos 6 caracteres.');
        }

        $check = $this->pdo->prepare('SELECT id FROM usuarios WHERE email = :email');
        $check->execute(['email' => $email]);
        if ($check->fetch()) {
            throw new InvalidArgumentException('El correo ya está registrado.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash) VALUES (:nombre, :email, :hash) RETURNING id, nombre, email, rol'
        );
        $stmt->execute([
            'nombre' => $nombre,
            'email' => $email,
            'hash' => $hash,
        ]);

        return $stmt->fetch();
    }

    public function autenticar(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare('SELECT id, nombre, email, password_hash, rol FROM usuarios WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        unset($user['password_hash']);
        return $user;
    }
}
