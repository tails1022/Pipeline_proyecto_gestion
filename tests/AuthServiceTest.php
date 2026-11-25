<?php
declare(strict_types=1);

namespace Tests;

use App\AuthService;
use App\Database;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private AuthService $auth;

    protected function setUp(): void
    {
        $dbTest = getenv('DB_TEST_NAME') ?: 'restaurante_test';
        $this->pdo = Database::getConnection($dbTest);
        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'test_%@restaurante.local'");
        $this->auth = new AuthService($this->pdo);
    }

    public function testRegistroYAutenticacionExitosa(): void
    {
        $user = $this->auth->registrar('Usuario Prueba', 'test_user1@restaurante.local', 'ClaveSegura123');
        $this->assertSame('test_user1@restaurante.local', $user['email']);

        $login = $this->auth->autenticar('test_user1@restaurante.local', 'ClaveSegura123');
        $this->assertNotNull($login);
        $this->assertSame('Usuario Prueba', $login['nombre']);
    }

    public function testRechazaPasswordCorto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->auth->registrar('Usuario Corto', 'test_user2@restaurante.local', '123');
    }
}
