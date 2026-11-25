pipeline {
    agent any

    environment {
        DB_HOST      = '127.0.0.1'
        DB_PORT      = '5432'
        DB_TEST_NAME = 'restaurante_test'
        APACHE_DIR   = 'C:\\Apache24\\htdocs\\restaurante'
    }

    stages {
        stage('1. Checkout') {
            steps {
                checkout scm
            }
        }

        stage('2. Lint y Análisis Estático (SAST)') {
            steps {
                bat 'for /R src %%f in (*.php) do php -l "%%f"'
                bat 'for /R public %%f in (*.php) do php -l "%%f"'
            }
        }

        stage('3. Build y Auditoría de Seguridad') {
            steps {
                bat 'composer install --no-interaction --prefer-dist'
                bat 'composer audit'
            }
        }

        stage('4. Pruebas Automatizadas (PHPUnit + PostgreSQL 16)') {
            steps {
                bat 'vendor\\bin\\phpunit --testdox'
            }
        }

        stage('5. Despliegue y Smoke Test en Apache') {
            steps {
                bat 'powershell -ExecutionPolicy Bypass -File scripts\\run_pipeline.ps1 -OnlyDeploy'
            }
        }
    }

    post {
        success {
            echo 'Pipeline completado exitosamente. Sistema desplegado en Apache.'
        }
        failure {
            echo 'Pipeline detenido por fallo en calidad o pruebas. Despliegue abortado.'
        }
    }
}
